<?php

use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Models\User;
use App\Models\NaceCode;
use App\Services\ApiCharacterizationGateway;
use App\Services\CharacterizationPredictionMapper;
use App\Services\CharacterizationStateTransaction;
use App\Services\LearningP5SourceRevisionClock;
use App\Services\LearningP6BaseSourceRevisionClock;
use App\Events\CharacterizationStatusUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

beforeEach(function () {
    // RefreshDatabase owns one fixture transaction. End it before creating rows;
    // its cleanup hook detects no PDO transaction and forces fresh migrations.
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->commit();
    RefreshDatabaseState::$migrated = false;
    Http::preventStrayRequests();
    Event::fake([CharacterizationStatusUpdated::class]);
    config([
        'services.learning_p6_job_parent_fence.enabled' => true,
        'services.learning_source_clock.enabled' => true,
        'services.learning_p6_base_source_clock.enabled' => true,
        'services.learning_p6_prepared_request.enabled' => true,
        'services.characterization.api.base_url' => 'https://synthetic.invalid',
        'services.characterization.api.token' => null,
        'services.characterization.api.model_profile' => 'synthetic',
    ]);
    $this->gateway = new class(new class extends CharacterizationPredictionMapper {
        public function candidateTopics(array $rawPrediction): array { return []; }
        public function reviewRequiredKeys(array $rawPrediction): array { return []; }
        public function mappingMetadata(): array { return ['synthetic' => true]; }
    }) extends ApiCharacterizationGateway {
        public function submit(Characterization $source): array { throw new LogicException('t06g ordinary submit forbidden'); }
    };
    $this->source = t06gFixture();
    $this->job = (new SubmitCharacterizationJob($this->source))->withFakeQueueInteractions();
});

function t06gFixture(): Characterization
{
    $user = User::create(['name' => 'SYNTHETIC_ACCOUNT', 'email' => 't06g@example.invalid', 'password' => 'synthetic-placeholder']);
    NaceCode::create(['code' => 'Z', 'level' => 1, 'title_en' => 'SYNTHETIC_SECTOR', 'title_es' => 'SYNTHETIC_SECTOR']);
    return app(CharacterizationStateTransaction::class)->runForUser($user->id, fn () => Characterization::create([
        'user_id' => $user->id, 'status' => 'submitted', 'submission_generation' => 1,
        'nace_code' => 'Z', 'form_data' => ['operations' => ['employee_count' => 7]],
        'submitted_at' => now(),
    ]));
}

it('accepts the actual prepared job only after committed claim and advances BASE without persisting payload', function () {
    $payload = $this->gateway->prepare($this->source)->payload();
    $base = (new LearningP6BaseSourceRevisionClock)->current($this->source->id);
    Http::fake(function ($request) use ($payload) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        expect($request->data())->toBe($payload);
        Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
        return Http::response(['esrs' => ['synthetic' => 1]]);
    });
    $this->job->handle($this->gateway);
    expect($this->source->fresh()->status)->toBe('completed');
    expect($this->source->fresh()->result_data)->not->toHaveKey('request_payload');
    expect((new LearningP6BaseSourceRevisionClock)->current($this->source->id)['revision'])->toBe($base['revision'] + 1);
    Http::assertSentCount(1);
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 2);
    $this->job->assertNotReleased();
});

it('rolls back a late owner non-source overwrite and permits clean recovery', function () {
    Http::fake(['https://synthetic.invalid/*' => Http::response(['esrs' => ['synthetic' => 1]])]);
    $armed = true;
    DB::listen(function ($query) use (&$armed) {
        if ($armed && str_starts_with($query->sql, 'update "learning_p6_base_source_revisions"')) {
            $armed = false;
            DB::table('characterizations')->where('id', $this->source->id)->update(['retry_count' => 99]);
        }
    });
    expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class);
    expect($this->source->fresh()->status)->toBe('processing');
    expect($this->source->fresh()->retry_count)->toBe(0);
    expect($this->source->fresh()->result_data)->toBeNull();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
    $this->job->assertNotReleased();
    app(CharacterizationStateTransaction::class)->runForUser($this->source->user_id, fn ($row) => $row->update(['status' => 'submitted']));
    (new SubmitCharacterizationJob($this->source->fresh()))->handle($this->gateway);
    expect($this->source->fresh()->status)->toBe('completed');
});

function t06gDrift(Characterization $source, string $kind): void
{
    $transactions = app(CharacterizationStateTransaction::class);
    $write = fn ($operation) => $transactions->runForUser($source->user_id, $operation);
    if ($kind === 'p5' || $kind === 'aba') {
        $original = $source->fresh()->form_data;
        $write(fn ($row) => $row->update(['form_data' => ['operations' => ['employee_count' => 8]]]));
        expect(DB::connection()->transactionLevel())->toBe(0);
        if ($kind === 'aba') {
            $write(fn ($row) => $row->update(['form_data' => $original]));
            expect(DB::connection()->transactionLevel())->toBe(0);
        }
    } elseif ($kind === 'base') {
        $write(fn ($row) => $row->update(['result_data' => ['synthetic' => 'replacement']]));
    } elseif ($kind === 'counter') {
        DB::table('learning_p6_base_source_revisions')->where('characterization_id', $source->id)->increment('revision');
    } elseif ($kind === 'account') {
        User::whereKey($source->user_id)->update(['name' => 'SYNTHETIC_CHANGED']);
    } elseif ($kind === 'default') {
        config(['services.characterization.defaults.headquarters_country' => 'SYNTHETIC_CHANGED']);
    } elseif ($kind === 'config') {
        config(['services.characterization.api.model_profile' => 'synthetic_nace2']);
    } elseif ($kind === 'nace') {
        NaceCode::where('code', 'Z')->update(['title_en' => 'SYNTHETIC_CHANGED']);
    } elseif ($kind === 'generation') {
        $write(fn ($row) => $row->update(['submission_generation' => 2]));
    } elseif ($kind === 'status') {
        $write(fn ($row) => $row->update(['status' => 'draft']));
    }
}

it('rejects changed parents during actual HTTP without accepting output', function ($kind) {
    Http::fake(function () use ($kind) {
        expect(DB::connection()->transactionLevel())->toBe(0);
        t06gDrift($this->source, $kind);
        return Http::response(['esrs' => ['synthetic' => 1]]);
    });
    expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class);
    expect($this->source->fresh()->completed_at)->toBeNull();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
    $this->job->assertNotReleased();
    Http::assertSentCount(1);
})->with(['p5', 'aba', 'base', 'counter', 'account', 'default', 'config', 'nace', 'generation', 'status']);

it('denies stale network failure capacity failed and repeat handling without effects', function ($kind, $capacity) {
    Http::fake(function () use ($kind, $capacity) {
        t06gDrift($this->source, $kind);
        return Http::response($capacity ? ['detail' => ['code' => 'prediction_capacity_busy']] : ['detail' => 'synthetic'], $capacity ? 503 : 422, $capacity ? ['Retry-After' => '60'] : []);
    });
    $this->job->handle($this->gateway);
    $snapshot = $this->source->fresh()->getRawOriginal();
    $this->job->failed(new RuntimeException('synthetic terminal'));
    expect($this->source->fresh()->getRawOriginal())->toBe($snapshot);
    expect($this->source->fresh()->retry_count)->toBe(0);
    $this->job->assertNotReleased();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
})->with(['p5', 'aba', 'base', 'counter', 'account', 'generation', 'status'])->with([false, true]);

it('does not send duplicate processing or failed without original witness', function () {
    $this->source->update(['status' => 'processing']);
    $this->job->handle($this->gateway);
    $this->job->failed(new RuntimeException('synthetic'));
    expect($this->source->fresh()->status)->toBe('processing');
    Http::assertNothingSent();
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
    $this->job->assertNotReleased();
});

it('refuses corrupt parents before claim effects', function ($table) {
    DB::table($table)->where('characterization_id', $this->source->id)->update(['digest' => 'corrupt']);
    expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class);
    expect($this->source->fresh()->status)->toBe('submitted');
    Http::assertNothingSent();
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
})->with(['learning_p5_source_revisions', 'learning_p6_base_source_revisions']);

it('admits no permissive raw dispatch casts', function ($raw, $field) {
    $source = $this->source->fresh();
    $attributes = $source->getAttributes();
    $attributes[$field] = $raw;
    $source->setRawAttributes($attributes, true);
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    expect(fn () => $job->handle($this->gateway))->toThrow(DomainException::class);
    expect($this->source->fresh()->status)->toBe('submitted');
    Http::assertNothingSent();
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
})->with(['1suffix', 1.5, '9007199254740992', '01', -1])->with(['id', 'user_id', 'submission_generation']);

it('guards unsafe modes before queries and never falls back', function ($mode) {
    if ($mode === 'production') { app()->instance('env', 'production'); }
    elseif ($mode === 'disk') { DB::connection()->setDatabaseName('synthetic-forbidden.sqlite'); }
    elseif ($mode === 'p5') { config(['services.learning_source_clock.enabled' => false]); }
    elseif ($mode === 'base') { config(['services.learning_p6_base_source_clock.enabled' => false]); }
    elseif ($mode === 'prepared') { config(['services.learning_p6_prepared_request.enabled' => false]); }
    else { config(['services.learning_p6_job_parent_fence.enabled' => $mode]); }
    DB::enableQueryLog(); DB::flushQueryLog();
    try {
        expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class);
        expect(DB::getQueryLog())->toBe([]);
        Http::assertNothingSent();
    } finally { app()->instance('env', 'testing'); DB::connection()->setDatabaseName(':memory:'); }
})->with(['true', 1, 'production', 'disk', 'p5', 'base', 'prepared']);

it('keeps private mode sticky during a flag flip and failure', function () {
    Http::fake(function () {
        config(['services.learning_p6_job_parent_fence.enabled' => false]);
        return Http::response(['detail' => 'synthetic'], 422);
    });
    $this->job->handle($this->gateway);
    $this->job->failed(new RuntimeException('synthetic'));
    expect($this->source->fresh()->status)->toBe('processing');
    expect($this->source->fresh()->retry_count)->toBe(0);
    $this->job->assertNotReleased();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 1);
});

it('keeps old interface OFF success failure capacity release and failed compatible', function ($mode) {
    config(['services.learning_p6_job_parent_fence.enabled' => false]);
    $job = (new SubmitCharacterizationJob($this->source))->withFakeQueueInteractions();
    $gateway = new class($mode) implements \App\Services\Contracts\CharacterizationGateway {
        public function __construct(private string $mode) {}
        public function submit(Characterization $source): array {
            if ($this->mode === 'error') { throw new RuntimeException('synthetic'); }
            if ($this->mode === 'capacity') { throw new \App\Exceptions\CharacterizationCapacityException(60); }
            return ['synthetic' => true, 'request_payload' => ['synthetic' => true]];
        }
    };
    $job->handle($gateway);
    if ($mode === 'success') {
        expect($this->source->fresh()->status)->toBe('completed');
        expect($this->source->fresh()->result_data)->toHaveKey('request_payload');
        $job->assertNotReleased();
    } else {
        expect($this->source->fresh()->status)->toBe('waiting');
        $job->assertReleased($mode === 'capacity' ? 60 : 300);
        $job->failed(new RuntimeException('synthetic terminal'));
        expect($this->source->fresh()->status)->toBe('timed_out');
    }
    Http::assertNothingSent();
})->with(['success', 'error', 'capacity']);

it('denies missing genuine headers rather than seeding a no-op observation', function () {
    $user = User::create(['name' => 'SYNTHETIC_EMPTY', 'email' => 't06g-empty@example.invalid', 'password' => 'synthetic-placeholder']);
    $source = Characterization::create(['user_id' => $user->id, 'status' => 'submitted', 'submission_generation' => 1, 'form_data' => []]);
    $job = (new SubmitCharacterizationJob($source))->withFakeQueueInteractions();
    expect(fn () => $job->handle($this->gateway))->toThrow(DomainException::class);
    expect((new LearningP5SourceRevisionClock)->current($source->id))->toBeNull();
    expect((new LearningP6BaseSourceRevisionClock)->current($source->id))->toBeNull();
    expect($source->fresh()->status)->toBe('submitted');
    Http::assertNothingSent();
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
});

it('rejects an old interface gateway in private mode before queries', function () {
    $gateway = new class implements \App\Services\Contracts\CharacterizationGateway {
        public function submit(Characterization $source): array { throw new LogicException('never'); }
    };
    DB::enableQueryLog(); DB::flushQueryLog();
    expect(fn () => $this->job->handle($gateway))->toThrow(DomainException::class);
    expect(DB::getQueryLog())->toBe([]);
    Http::assertNothingSent();
});

it('uses the original witness for same-parent retry and denies a changed retry', function ($stale) {
    Http::fake(['https://synthetic.invalid/*' => Http::response(['detail' => ['code' => 'prediction_capacity_busy']], 503, ['Retry-After' => '60'])]);
    $this->job->handle($this->gateway);
    expect($this->source->fresh()->status)->toBe('waiting');
    $this->job->assertReleased(60);
    if ($stale) { t06gDrift($this->source, 'aba'); }
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    $this->job->withFakeQueueInteractions();
    Http::fake(['https://synthetic.invalid/*' => Http::response(['esrs' => ['synthetic' => 1]])]);
    if ($stale) {
        expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class);
        expect($this->source->fresh()->status)->toBe('waiting');
        $this->job->assertNotReleased();
        Http::assertNothingSent();
    } else {
        $this->job->handle($this->gateway);
        expect($this->source->fresh()->status)->toBe('completed');
    }
})->with([false, true]);


it('denies original dispatch replay without handler witness', function ($stale) {
    $original = serialize(new SubmitCharacterizationJob($this->source));
    $first = unserialize($original)->withFakeQueueInteractions();
    Http::fake(['https://synthetic.invalid/*' => Http::response(['detail' => ['code' => 'prediction_capacity_busy']], 503, ['Retry-After' => '5'])]);
    $first->handle($this->gateway);
    $first->assertReleased(5);
    if ($stale) { t06gDrift($this->source, 'aba'); }
    $snapshot = $this->source->fresh()->getRawOriginal();
    $p5 = (new LearningP5SourceRevisionClock)->current($this->source->id);
    $base = (new LearningP6BaseSourceRevisionClock)->current($this->source->id);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://synthetic.invalid/*' => Http::response(['esrs' => ['synthetic' => 1]])]);
    $retry = unserialize($original)->withFakeQueueInteractions();
    expect(fn () => $retry->handle($this->gateway))->toThrow(DomainException::class);
    expect($this->source->fresh()->getRawOriginal())->toBe($snapshot);
    expect($this->source->fresh()->status)->toBe('waiting');
    expect((new LearningP5SourceRevisionClock)->current($this->source->id))->toBe($p5);
    expect((new LearningP6BaseSourceRevisionClock)->current($this->source->id))->toBe($base);
    Http::assertNothingSent();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, 2);
    $retry->assertNotReleased();
})->with([false, true]);

it('rolls back late own opt-out at final owner boundaries and recovers', function ($boundary) {
    Http::fake(['https://synthetic.invalid/*' => $boundary === 'failure'
        ? Http::response(['detail' => ['code' => 'prediction_capacity_busy']], 503, ['Retry-After' => '5'])
        : Http::response(['esrs' => ['synthetic' => 1]])]);
    $armed = true;
    DB::listen(function ($query) use (&$armed, $boundary) {
        $target = $boundary === 'completion'
            ? str_starts_with($query->sql, 'update "learning_p6_base_source_revisions"')
            : str_starts_with($query->sql, 'update "characterizations"') && in_array($boundary === 'claim' ? 'processing' : 'waiting', $query->bindings, true);
        if ($armed && $target) {
            $armed = false;
            config(['services.learning_p6_job_parent_fence.enabled' => false]);
        }
    });
    if ($boundary === 'failure') { $this->job->handle($this->gateway); }
    else { expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class); }
    expect($armed)->toBeFalse();
    expect($this->source->fresh()->status)->toBe($boundary === 'claim' ? 'submitted' : 'processing');
    expect($this->source->fresh()->retry_count)->toBe(0);
    expect($this->source->fresh()->result_data)->toBeNull();
    Event::assertDispatchedTimes(CharacterizationStatusUpdated::class, $boundary === 'claim' ? 0 : 1);
    Http::assertSentCount($boundary === 'claim' ? 0 : 1);
    $this->job->assertNotReleased();
    config(['services.learning_p6_job_parent_fence.enabled' => true]);
    app(CharacterizationStateTransaction::class)->runForUser($this->source->user_id, fn ($row) => $row->update(['status' => 'submitted']));
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://synthetic.invalid/*' => Http::response(['esrs' => ['synthetic' => 1]])]);
    (new SubmitCharacterizationJob($this->source->fresh()))->handle($this->gateway);
    expect($this->source->fresh()->status)->toBe('completed');
})->with(['claim', 'completion', 'failure']);

it('denies non-first queue attempts without witness before effects', function ($attempt) {
    $this->job->setJob(new class($attempt) extends \Illuminate\Queue\Jobs\FakeJob {
        public function __construct(private mixed $attempt) {}
        public function attempts() { return $this->attempt; }
    });
    $snapshot = $this->source->fresh()->getRawOriginal();
    expect(fn () => $this->job->handle($this->gateway))->toThrow(DomainException::class);
    expect($this->source->fresh()->getRawOriginal())->toBe($snapshot);
    Http::assertNothingSent();
    Event::assertNotDispatched(CharacterizationStatusUpdated::class);
})->with([2, '1', null, 0]);


it('rejects raw non-fresh markers without defaulting or casting', function ($field, $value) {
    $raw = $this->source->fresh()->getRawOriginal();
    if ($value === 'MISSING') { unset($raw[$field]); } else { $raw[$field] = $value; }
    expect(fn () => \App\Services\LearningP6JobParentFence::assertFreshClaim($raw, 1))->toThrow(DomainException::class);
    Http::assertNothingSent();
})->with([['retry_count', 'MISSING'], ['retry_count', null], ['retry_count', '00'], ['retry_count', '0suffix'], ['retry_count', 0.0], ['retry_count', 1], ['next_retry_at', 'MISSING'], ['next_retry_at', 'synthetic-retry'], ['last_error', 'synthetic-error'], ['status', 'waiting']]);
