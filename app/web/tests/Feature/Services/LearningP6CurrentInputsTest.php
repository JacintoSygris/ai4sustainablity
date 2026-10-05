<?php

use App\Services\LearningP6Snapshot;
use App\Models\{Characterization, CharacterizationDocument, User, EsrsTopic, NaceCode};
use App\Services\{ApiCharacterizationGateway, CharacterizationPredictionMapper, CharacterizationStateTransaction,
    LearningP5SourceRevisionClock, LearningP6BaseSourceRevisionClock, LearningP6JobParentFence};
use Illuminate\Support\Facades\{DB, Http};

// All fixtures (mapping/catalog/document records/account) are synthetic; no rights/promotion.
beforeEach(function () {
    $mappingPath = storage_path('app/fixtures/t06o.map.json');
    if (! is_dir(dirname($mappingPath))) { mkdir(dirname($mappingPath), 0700, true); }
    file_put_contents($mappingPath, '{"candidate_topics":[]}');
    config(['services.learning_source_clock.enabled' => true,
        'services.learning_p6_base_source_clock.enabled' => true,
        'services.learning_p6_prepared_request.enabled' => true,
        'services.learning_p6_job_parent_fence.enabled' => true,
        'services.learning_p6_interpretation_context.enabled' => true,
        'services.characterization.api.model_profile' => 't06o_synthetic',
        'services.characterization.prediction_mapping_path' => $mappingPath]);
    Http::preventStrayRequests();
});

function t06oFrame(): array
{
    return ['status' => 'completed', 'model_profile' => 't06o_synthetic',
        'mapping_metadata' => ['python' => ['serving_identity' => [
            'profile' => 't06o_synthetic', 'artifact_sha256' => ['synthetic.pkl' => str_repeat('a', 64)],
            'policy_sha256' => [], 'runtime_config' => ['score_threshold' => 0.95,
                'policy_active' => ['label_thresholds' => false, 'crc_recall_floor' => false, 'sector_guard' => false]],
            'serving_identity_sha256' => str_repeat('b', 64),
        ]]]];
}

function t06oSource(): array
{
    $user = User::factory()->create(['name' => 'SYNTHETIC_ACCOUNT', 'email' => 't06o-'.User::count().'@example.invalid']);
    $row = app(CharacterizationStateTransaction::class)->runForUser($user->id,
        fn () => Characterization::create(['user_id' => $user->id, 'status' => 'completed',
            'submission_generation' => 1, 'form_data' => ['operations' => ['employee_count' => 7]],
            'result_data' => t06oFrame()]));
    $gateway = new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
    return [$row->fresh(), ['p5' => (new LearningP5SourceRevisionClock)->current($row->id),
        'p6_base' => (new LearningP6BaseSourceRevisionClock)->current($row->id)], $gateway];
}

function t06oRead(array $fixture): array
{
    [$row, $headers, $gateway] = $fixture;
    return (new LearningP6Snapshot)->projectInputsForAccount($row->user_id, $headers, $gateway);
}

it('t06o inventory exposes the private current input seam', function () {
    expect(method_exists(LearningP6Snapshot::class, 'projectInputsForAccount'))->toBeTrue();
});

it('t06o composes actual prepared references with no HTTP or DML', function () {
    [$row, $headers, $gateway] = $fixture = t06oSource();
    $prepared = $gateway->prepare($row);
    DB::enableQueryLog(); DB::flushQueryLog();
    $frame = t06oRead($fixture);
    expect(array_keys($frame))->toBe(['source_headers', 'projection', 'prepared_input_digest', 'interpretation_digest', 'documents_digest']);
    expect($frame['source_headers'])->toBe($headers)
        ->and($frame['projection'])->toBe((new LearningP6Snapshot)->project($row))
        ->and($frame['prepared_input_digest'])->toBe($prepared->digest())
        ->and($frame['interpretation_digest'])->toBe($prepared->interpretation()->digest())
        ->and($frame['documents_digest'])->toBe(hash('sha256', '[]'));
    expect(t06oRead($fixture))->toBe($frame);
    expect(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query'])))->toBe([]);
    DB::disableQueryLog(); Http::assertNothingSent();
});

it('t06o denies incomplete foreign and unknown header tuples', function () {
    [$row, $headers, $gateway] = t06oSource();
    [$other] = t06oSource();
    $bad = [[], $headers + ['unknown' => []], $headers + ['id' => 1]];
    foreach (['p5', 'p6_base'] as $parent) {
        foreach ($headers[$parent] as $key => $value) {
            $partial = $headers; unset($partial[$parent][$key]); $bad[] = $partial;
            $changed = $headers; $changed[$parent][$key] = is_int($value) ? (string) $value : 'bad'; $bad[] = $changed;
        }
    }
    foreach ($bad as $expected) {
        expect(fn () => (new LearningP6Snapshot)->projectInputsForAccount($row->user_id, $expected, $gateway))->toThrow(DomainException::class);
    }
    expect(fn () => (new LearningP6Snapshot)->projectInputsForAccount($other->user_id, $headers, $gateway))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('t06o guards strict flags and all model declarations before PDO', function () {
    $connection = DB::connection(); $pdo = $connection->getPdo();
    $property = new ReflectionProperty($connection, 'pdo');
    $gateway = new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
    foreach (['learning_source_clock', 'learning_p6_base_source_clock', 'learning_p6_prepared_request', 'learning_p6_job_parent_fence', 'learning_p6_interpretation_context'] as $flag) {
        config(['services.'.$flag.'.enabled' => 'true']);
        $property->setValue($connection, fn () => throw new LogicException('PDO must not open'));
        try { expect(fn () => (new LearningP6Snapshot)->projectInputsForAccount(1, [], $gateway))->toThrow(DomainException::class); }
        finally { $property->setValue($connection, $pdo); config(['services.'.$flag.'.enabled' => true]); }
    }
    $resolver = Characterization::getConnectionResolver();
    $unsafe = new Illuminate\Database\SQLiteConnection(fn () => throw new LogicException('unsafe PDO'), 'synthetic-disk', '', ['driver' => 'sqlite']);
    Characterization::setConnectionResolver(new class($unsafe) implements Illuminate\Database\ConnectionResolverInterface {
        public function __construct(private $unsafe) {}
        public function connection($name = null) { return $this->unsafe; }
        public function getDefaultConnection() { return 'synthetic'; }
        public function setDefaultConnection($name) {}
    });
    try { expect(fn () => (new LearningP6Snapshot)->projectInputsForAccount(1, [], $gateway))->toThrow(DomainException::class); }
    finally { Characterization::setConnectionResolver($resolver); }
});

it('t06o refuses profile mismatch rather than rewriting prepared input', function () {
    $fixture = t06oSource(); config(['services.characterization.api.model_profile' => 'different_synthetic']);
    expect(fn () => t06oRead($fixture))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('t06o missing parents cannot seed a persistent header', function () {
    [$row, $headers, $gateway] = $fixture = t06oSource();
    DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->delete();
    expect(fn () => t06oRead($fixture))->toThrow(DomainException::class);
    expect(DB::table('learning_p5_source_revisions')->count())->toBe(0);
});

function t06oDocument(Characterization $row): CharacterizationDocument
{
    return CharacterizationDocument::create(['characterization_id' => $row->id,
        'original_filename' => 'SYNTHETIC_NO_FILE', 'stored_path' => 'SYNTHETIC_NO_FILE',
        'sha256' => str_repeat('d', 64), 'size_bytes' => 12, 'mime' => 'application/pdf',
        'status' => 'extracted', 'extraction_generation' => 1,
        'extraction_json' => ['synthetic_only' => true], 'merged_state_version' => null]);
}

it('t06o binds raw document mutation membership and rollback', function ($kind) {
    [$row, $headers, $gateway] = $fixture = t06oSource(); $document = t06oDocument($row)->fresh();
    $before = $row->getRawOriginal();
    $caught = false;
    try {
        DB::transaction(function () use ($row, $gateway, $document, $kind) {
            $fence = LearningP6JobParentFence::capture(LearningP6JobParentFence::identity($row->getRawOriginal()), $row, $gateway, true);
            if ($kind === 'mutate') { DB::table('characterization_documents')->where('id', $document->id)->update(['extraction_json' => '{ "synthetic_only": true }']); }
            elseif ($kind === 'remove') { DB::table('characterization_documents')->where('id', $document->id)->delete(); }
            else { t06oDocument($row); }
            $fence->assertParents($row->fresh(), $gateway, ['completed']);
        });
    } catch (DomainException) { $caught = true; }
    expect($caught)->toBeTrue()->and(CharacterizationDocument::count())->toBe(1);
    expect($document->fresh()->getRawOriginal())->toBe($document->getRawOriginal());
    expect($row->fresh()->getRawOriginal())->toBe($before);
    $frame = t06oRead($fixture);
    expect($frame['documents_digest'])->not->toBe(hash('sha256', '[]'));
    Http::assertNothingSent();
})->with(['mutate', 'remove', 'add']);

it('t06o rejects effective input and context drift between prepare and recheck', function ($kind) {
    [$row, $headers, $gateway] = $fixture = t06oSource(); $before = $row->getRawOriginal();
    $catalog = 0; $armed = true;
    DB::listen(function ($query) use ($kind, $row, &$catalog, &$armed) {
        if (! $armed || ! str_contains($query->sql, 'from "esrs_topics"')) { return; }
        if (++$catalog !== 2) { return; } $armed = false;
        if ($kind === 'p5') { DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{"operations":{"employee_count":19}}']); }
        else { config(['services.characterization.defaults.headquarters_country' => 'SYNTHETIC_CHANGED']); }
    });
    try { expect(fn () => t06oRead($fixture))->toThrow(DomainException::class); }
    finally { config(['services.characterization.defaults.headquarters_country' => 'Spain']); }
    expect($armed)->toBeFalse()->and($row->fresh()->getRawOriginal())->toBe($before);
    expect((new LearningP5SourceRevisionClock)->current($row->id))->toBe($headers['p5']);
    expect(t06oRead($fixture)['source_headers'])->toBe($headers); Http::assertNothingSent();
})->with(['p5', 'default']);

it('t06o closes composition after Common finalizers before outer commit', function ($kind) {
    [$row, $headers, $gateway] = $fixture = t06oSource(); $doc = t06oDocument($row)->fresh();
    $raw = $row->getRawOriginal(); $armed = true; $physical = false;
    DB::listen(function ($query) use ($kind, $row, $doc, &$armed, &$physical) {
        $stack = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        if (! $armed || ! str_contains($query->sql, 'from "learning_p6_base_source_revisions"')
            || ! in_array('observeOwners', $stack, true) || in_array('observe', $stack, true)) { return; }
        $armed = false;
        if ($kind === 'mode') { config(['services.learning_p6_prepared_request.enabled' => false]); $physical = true; return; }
        if ($kind === 'docs') {
            $physical = DB::table('characterization_documents')->where('id', $doc->id)->update(['size_bytes' => 55]) === 1
                && DB::table('characterization_documents')->where('id', $doc->id)->value('size_bytes') === 55;
            return;
        }
        $values = $kind === 'actor' ? ['user_id' => User::factory()->create(['name' => 'SYNTHETIC_OTHER', 'email' => 'late@example.invalid'])->id]
            : ['result_data' => json_encode(t06oFrame() + ['synthetic_late' => true])];
        $physical = DB::table('characterizations')->where('id', $row->id)->update($values) === 1
            && array_intersect_key((array) DB::table('characterizations')->where('id', $row->id)->first(), $values) === $values;
    });
    try { expect(fn () => t06oRead($fixture))->toThrow(DomainException::class); }
    finally { config(['services.learning_p6_prepared_request.enabled' => true]); }
    expect($armed)->toBeFalse()->and($physical)->toBeTrue();
    expect($row->fresh()->getRawOriginal())->toBe($raw)->and($doc->fresh()->getRawOriginal())->toBe($doc->getRawOriginal());
    expect((new LearningP5SourceRevisionClock)->current($row->id))->toBe($headers['p5']);
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBe($headers['p6_base']);
    expect((new LearningP6BaseSourceRevisionClock)->hasActiveScope($row->user_id))->toBeFalse();
    expect(t06oRead($fixture)['source_headers'])->toBe($headers); Http::assertNothingSent();
})->with(['source', 'actor', 'mode', 'docs']);

it('t06o uses raw inputs ignores cached relations and accepts only shared PDO aliases', function () {
    [$row, $headers, $gateway] = $fixture = t06oSource();
    $expected = t06oRead($fixture); $resolver = Characterization::getConnectionResolver();
    foreach ([true, false] as $shared) {
        $alias = new Illuminate\Database\SQLiteConnection($shared ? DB::connection()->getPdo() : new PDO('sqlite::memory:'),
            ':memory:', '', ['driver' => 'sqlite', 'database' => ':memory:']);
        Characterization::setConnectionResolver(new class($alias) implements Illuminate\Database\ConnectionResolverInterface {
            public function __construct(private $alias) {}
            public function connection($name = null) { return $this->alias; }
            public function getDefaultConnection() { return 'synthetic_alias'; }
            public function setDefaultConnection($name) {}
        });
        try {
            if ($shared) { expect(t06oRead($fixture))->toBe($expected); }
            else { expect(fn () => t06oRead($fixture))->toThrow(DomainException::class); }
        } finally { Characterization::setConnectionResolver($resolver); }
    }
    $dispatcher = Characterization::getEventDispatcher(); Characterization::setEventDispatcher(clone $dispatcher);
    Characterization::retrieved(function ($retrieved) {
        $retrieved->form_data = ['operations' => ['employee_count' => 999]];
        $retrieved->setRelation('user', new User(['name' => 'SYNTHETIC_DIRTY']));
    });
    try { expect(t06oRead($fixture))->toBe($expected); }
    finally { Characterization::setEventDispatcher($dispatcher); }
    Http::assertNothingSent();
});
