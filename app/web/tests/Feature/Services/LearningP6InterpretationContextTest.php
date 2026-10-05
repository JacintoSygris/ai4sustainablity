<?php

use App\Services\CharacterizationPredictionMapper;
use App\Services\ApiCharacterizationGateway;
use App\Services\CharacterizationStateTransaction;
use App\Models\Characterization;
use App\Models\User;
use App\Models\NaceCode;
use App\Models\EsrsTopic;
use App\Jobs\SubmitCharacterizationJob;
use App\Events\CharacterizationStatusUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

function t06kFixture(): array
{
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->commit();
    RefreshDatabaseState::$migrated = false;
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Event::fake([CharacterizationStatusUpdated::class]);
    config([
        'services.learning_p6_interpretation_context.enabled' => true,
        'services.learning_p6_job_parent_fence.enabled' => true,
        'services.learning_source_clock.enabled' => true,
        'services.learning_p6_base_source_clock.enabled' => true,
        'services.learning_p6_prepared_request.enabled' => true,
        'services.characterization.api.base_url' => 'https://synthetic.invalid',
        'services.characterization.api.token' => null,
        'services.characterization.api.model_profile' => 'synthetic',
        'services.characterization.prediction_mapping_path' => t06kMap('job-a'),
    ]);
    EsrsTopic::create(['esrs_code' => 'SYNTHETIC', 'theme_es' => 'SYNTHETIC', 'theme_en' => 'SYNTHETIC', 'subtheme_es' => 'SYNTHETIC', 'subtheme_en' => 'SYNTHETIC', 'hash' => 'synthetic-one']);
    NaceCode::create(['code' => 'Z', 'level' => 1, 'title_en' => 'SYNTHETIC', 'title_es' => 'SYNTHETIC']);
    $user = User::create(['name' => 'SYNTHETIC', 'email' => 't06k@example.invalid', 'password' => 'synthetic-placeholder']);
    $source = app(CharacterizationStateTransaction::class)->runForUser($user->id, fn () => Characterization::create([
        'user_id' => $user->id, 'status' => 'submitted', 'submission_generation' => 1,
        'nace_code' => 'Z', 'form_data' => ['operations' => ['employee_count' => 7]], 'submitted_at' => now(),
    ]));
    return [$source, new ApiCharacterizationGateway(new CharacterizationPredictionMapper)];
}

function t06kHeaders(): array
{
    return [DB::table('learning_p5_source_revisions')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('learning_p6_base_source_revisions')->get()->map(fn ($r) => (array) $r)->all()];
}

function t06kMap(string $name, bool $modern = false, mixed $id = 1): string
{
    $path = storage_path('app/fixtures/t06k/writer-'.$name.'.json');
    if (! is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
    $map = $modern
        ? ['mapping_version' => 'new_format_732_v1', 'status' => $name, 'keys' => [['status' => 'approved', 'python_esrs_key' => 'synthetic', 'ar16_topic_ids' => [$id], 'web_esrs' => 'SYNTHETIC', 'web_label_en' => 'SYNTHETIC']]]
        : ['version' => $name, 'status' => $name, 'candidate_topics' => [['mapping_status' => 'approved', 'python_esrs_keys' => ['synthetic'], 'ar16_topic_id' => $id, 'web_esrs' => 'SYNTHETIC', 'web_label_en' => 'SYNTHETIC']]];
    file_put_contents($path, json_encode($map, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    return $path;
}

it('binds cached interpretation and metadata to the same captured bytes', function ($modern) {
    $a = t06kMap('cache-a-'.(int) $modern, $modern);
    $b = t06kMap('cache-b-'.(int) $modern, $modern, 2);
    config(['services.characterization.prediction_mapping_path' => $a]);
    $mapper = new CharacterizationPredictionMapper;
    expect($mapper->candidateTopics(['synthetic' => 1])[0]['ar16_topic_id'])->toBe(1);
    expect($mapper->mappingMetadata()['mapping_sha256'])->toBe(hash_file('sha256', $a));
    config(['services.characterization.prediction_mapping_path' => $b]);
    expect($mapper->candidateTopics(['synthetic' => 1])[0]['ar16_topic_id'])->toBe(1);
    expect($mapper->mappingMetadata()['mapping_sha256'])->toBe(hash_file('sha256', $a));
})->with([false, true]);

it('publishes reproducible nonempty IDs using the real prepared API and private Job', function () {
    [$source, $gateway] = t06kFixture();
    $payload = $gateway->prepare($source)->payload();
    Http::fake(function ($request) use ($payload) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        expect($request->data())->toBe($payload);
        return Http::response(['esrs' => ['synthetic' => 1]]);
    });
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    $job->handle($gateway);
    expect($source->fresh()->status)->toBe('completed');
    expect($source->fresh()->esrs_topic_ids)->toBe([1]);
    expect($source->fresh()->result_data)->not->toHaveKeys(['request_payload', 'interpretation_context', 'interpretation_digest']);
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 2);
    $job->assertNotReleased();
});

it('denies prepared interpretation drift before send without HTTP', function ($kind) {
    [$source, $gateway] = t06kFixture();
    $request = $gateway->prepare($source);
    if ($kind === 'map') { config(['services.characterization.prediction_mapping_path' => t06kMap('pre-b')]); }
    elseif ($kind === 'bytes') { file_put_contents(config('services.characterization.prediction_mapping_path'), "\n", FILE_APPEND); }
    else { EsrsTopic::whereKey(1)->update(['theme_en' => 'SYNTHETIC_CHANGED']); }
    expect(fn () => $gateway->submitPrepared($request))->toThrow(DomainException::class, 'learning_p6_interpretation.changed');
    Http::assertNothingSent();
})->with(['map', 'bytes', 'catalog']);

it('denies HTTP and late publication drift with exact rollback then clean dispatch', function ($kind) {
    [$source, $gateway] = t06kFixture();
    $rowAtHttp = null; $headersAtHttp = null;
    $armed = $kind === 'late';
    DB::listen(function ($query) use (&$armed) {
        if ($armed && str_starts_with($query->sql, 'update "learning_p6_base_source_revisions"')) {
            $armed = false;
            EsrsTopic::whereKey(1)->update(['theme_en' => 'SYNTHETIC_CHANGED']);
        }
    });
    Http::fake(function () use ($source, $kind, &$rowAtHttp, &$headersAtHttp) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        if ($kind === 'map') { config(['services.characterization.prediction_mapping_path' => t06kMap('during-b')]); }
        elseif ($kind === 'catalog') { EsrsTopic::whereKey(1)->update(['theme_en' => 'SYNTHETIC_CHANGED']); }
        elseif ($kind === 'missing') { DB::table('esrs_topics')->where('id', 1)->update(['id' => 2]); }
        $rowAtHttp = $source->fresh()->getRawOriginal();
        $headersAtHttp = t06kHeaders();
        return Http::response(['esrs' => ['synthetic' => 1]]);
    });
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    expect(fn () => $job->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.changed');
    expect($source->fresh()->getRawOriginal())->toBe($rowAtHttp);
    expect(t06kHeaders())->toBe($headersAtHttp);
    expect($source->fresh()->result_data)->toBeNull();
    expect($source->fresh()->esrs_topic_ids)->toBeNull();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
    $job->assertNotReleased();
    Http::assertSentCount(1);
    config(['services.characterization.prediction_mapping_path' => t06kMap('job-a')]);
    DB::table('esrs_topics')->where('hash', 'synthetic-one')->update(['id' => 1, 'theme_en' => 'SYNTHETIC']);
    app(CharacterizationStateTransaction::class)->runForUser($source->user_id, fn ($row) => $row->update(['status' => 'submitted']));
    Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
    Http::fake(fn () => Http::response(['esrs' => ['synthetic' => 1]]));
    (new SubmitCharacterizationJob($source->fresh()))->handle(new ApiCharacterizationGateway(new CharacterizationPredictionMapper));
    expect($source->fresh()->status)->toBe('completed');
    expect($source->fresh()->esrs_topic_ids)->toBe([1]);
})->with(['map', 'catalog', 'missing', 'late']);

it('rejects unsafe interpretation modes before queries or effects', function ($mode) {
    [$source, $gateway] = t06kFixture();
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    $resolver = EsrsTopic::getConnectionResolver();
    if ($mode === 'production') { app()->instance('env', 'production'); }
    elseif ($mode === 'default-disk') { DB::connection()->setDatabaseName('forbidden-synthetic.sqlite'); }
    elseif ($mode === 'catalog-disk') {
        config(['database.connections.t06k_forbidden' => ['driver' => 'sqlite', 'database' => 'forbidden-synthetic.sqlite']]);
        EsrsTopic::setConnectionResolver(new class($resolver) implements \Illuminate\Database\ConnectionResolverInterface {
            public function __construct(private $original) {}
            public function connection($name = null) { return DB::connection('t06k_forbidden'); }
            public function getDefaultConnection() { return $this->original->getDefaultConnection(); }
            public function setDefaultConnection($name) { $this->original->setDefaultConnection($name); }
        });
    }
    elseif ($mode === 'prepared-off') { config(['services.learning_p6_prepared_request.enabled' => false]); }
    else { config(['services.learning_p6_interpretation_context.enabled' => $mode]); }
    DB::enableQueryLog(); DB::flushQueryLog();
    try {
        expect(fn () => $job->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.guard');
        expect(DB::getQueryLog())->toBe([]);
        Http::assertNothingSent();
        Event::assertNotDispatched(CharacterizationStatusUpdated::class);
    } finally {
        app()->instance('env', 'testing'); DB::connection()->setDatabaseName(':memory:');
        EsrsTopic::setConnectionResolver($resolver);
    }
})->with(['true', 1, 'production', 'default-disk', 'catalog-disk', 'prepared-off', false]);

it('rejects missing context and ordinary transport downgrade', function ($ordinary) {
    [$source, $gateway] = t06kFixture();
    $payload = $gateway->prepare($source)->payload();
    expect(fn () => $ordinary ? $gateway->submit($source)
        : $gateway->submitPrepared(new \App\Services\LearningP6PreparedRequest($payload)))
        ->toThrow(DomainException::class, $ordinary ? 'learning_p6_interpretation.prepared_required' : 'learning_p6_interpretation.missing');
    Http::assertNothingSent();
})->with([false, true]);

it('rejects fake prepared gateway downgrade before queries', function () {
    [$source] = t06kFixture();
    $gateway = new class implements \App\Services\Contracts\PreparedCharacterizationGateway {
        public function prepare(Characterization $source): \App\Services\LearningP6PreparedRequest { return new \App\Services\LearningP6PreparedRequest([]); }
        public function submitPrepared(\App\Services\LearningP6PreparedRequest $request): array { return []; }
        public function submit(Characterization $source): array { return []; }
    };
    DB::enableQueryLog(); DB::flushQueryLog();
    expect(fn () => (new SubmitCharacterizationJob($source))->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.gateway');
    expect(DB::getQueryLog())->toBe([]);
    Http::assertNothingSent();
});

it('keeps captured context enabled through HTTP and late BASE finalization', function ($late) {
    [$source, $gateway] = t06kFixture();
    $rowAtHttp = null; $headersAtHttp = null; $armed = $late;
    DB::listen(function ($query) use (&$armed) {
        if ($armed && str_starts_with($query->sql, 'update "learning_p6_base_source_revisions"')) {
            $armed = false; config(['services.learning_p6_interpretation_context.enabled' => false]);
        }
    });
    Http::fake(function () use ($source, $late, &$rowAtHttp, &$headersAtHttp) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        $rowAtHttp = $source->fresh()->getRawOriginal(); $headersAtHttp = t06kHeaders();
        if (! $late) { config(['services.learning_p6_interpretation_context.enabled' => false]); }
        return Http::response(['esrs' => ['synthetic' => 1]]);
    });
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    expect(fn () => $job->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.guard');
    expect($source->fresh()->getRawOriginal())->toBe($rowAtHttp);
    expect(t06kHeaders())->toBe($headersAtHttp);
    $job->failed(new RuntimeException('synthetic'));
    expect($source->fresh()->getRawOriginal())->toBe($rowAtHttp);
    $job->assertNotReleased();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
})->with([false, true]);

it('rejects unknown or aliased raw mapping IDs before any completed publication', function ($modern, $id) {
    [$source, $gateway] = t06kFixture();
    config(['services.characterization.prediction_mapping_path' => t06kMap('invalid-'.(int) $modern.'-'.hash('sha256', serialize($id)), $modern, $id)]);
    $row = $source->fresh()->getRawOriginal(); $headers = t06kHeaders();
    expect(fn () => (new SubmitCharacterizationJob($source))->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.topic_id');
    expect($source->fresh()->getRawOriginal())->toBe($row);
    expect(t06kHeaders())->toBe($headers);
    Http::assertNothingSent();
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
})->with([false, true])->with([2, '1suffix', 1.5, '01', 1.0, null]);

it('rejects malformed map capture without publication', function ($raw) {
    [$source, $gateway] = t06kFixture();
    $path = storage_path('app/fixtures/t06k/writer-malformed-'.hash('sha256', $raw).'.json');
    file_put_contents($path, $raw); config(['services.characterization.prediction_mapping_path' => $path]);
    expect(fn () => $gateway->prepare($source))->toThrow(DomainException::class, 'learning_p6_interpretation.mapping');
    Http::assertNothingSent();
})->with(['{', 'null', '{"candidate_topics":"wrong"}']);

it('preserves OFF prepared compatibility and timestamp-only independence', function ($flag) {
    [$source, $gateway] = t06kFixture();
    config(['services.learning_p6_interpretation_context.enabled' => $flag]);
    $request = $gateway->prepare($source);
    Http::fake(fn () => Http::response(['esrs' => ['synthetic' => 1]]));
    EsrsTopic::whereKey(1)->update(['updated_at' => now()->addDay()]);
    expect($gateway->submitPrepared($request)['candidate_topics'][0]['ar16_topic_id'])->toBe(1);
    Http::assertSentCount(1);
})->with([null, false, true]);

it('rejects a context upgrade after a context-free claim', function () {
    [$source, $gateway] = t06kFixture();
    config(['services.learning_p6_interpretation_context.enabled' => false]);
    Http::fake(function () {
        config(['services.learning_p6_interpretation_context.enabled' => true]);
        return Http::response(['esrs' => ['synthetic' => 1]]);
    });
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    expect(fn () => $job->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.missing');
    expect($source->fresh()->result_data)->toBeNull();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
    $job->assertNotReleased();
});

it('never downgrades a rejected context dispatch in failed handling', function () {
    [$source, $gateway] = t06kFixture();
    config(['services.learning_p6_job_parent_fence.enabled' => false]);
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    expect(fn () => $job->handle($gateway))->toThrow(DomainException::class, 'learning_p6_interpretation.prepared_required');
    $row = $source->fresh()->getRawOriginal();
    config(['services.learning_p6_interpretation_context.enabled' => false]);
    $job->failed(new RuntimeException('synthetic'));
    expect($source->fresh()->getRawOriginal())->toBe($row);
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
});

it('detaches mapping authority from gateway cache and returned mapper mutations', function () {
    [$source, $gateway] = t06kFixture();
    $request = $gateway->prepare($source);
    $context = $request->interpretation();
    $digest = $context->digest();
    $payload = $request->payload(); $payload['synthetic_change'] = true;
    $copy = $context->mapper();
    $property = new ReflectionProperty($copy, 'mapping');
    $property->setValue($copy, ['candidate_topics' => []]);
    Http::fake(fn () => Http::response(['esrs' => ['synthetic' => 1]]));
    expect($gateway->submitPrepared($request)['candidate_topics'][0]['ar16_topic_id'])->toBe(1);
    expect($context->digest())->toBe($digest);
    expect($request->payload())->not->toHaveKey('synthetic_change');
});
