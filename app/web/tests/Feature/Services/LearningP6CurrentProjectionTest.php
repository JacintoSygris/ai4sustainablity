<?php

use App\Services\LearningP6Snapshot;
use App\Models\{Characterization, User};
use App\Services\{CharacterizationStateTransaction, LearningP6BaseSourceRevisionClock};
use Illuminate\Support\Facades\DB;

// t06n fixtures: synthetic_only=true; promotion_allowed=false; no real rights.
it('t06n inventory exposes the account projection seam', function () {
    expect(method_exists(LearningP6Snapshot::class, 'projectForAccount'))->toBeTrue();
});

beforeEach(function () {
    config(['services.learning_p6_base_source_clock.enabled' => true]);
});

function t06nFrame(): array
{
    return ['synthetic_only' => true, 'promotion_allowed' => false, 'status' => 'completed',
        'model_profile' => 't06n_synthetic', 'mapping_metadata' => ['python' => ['serving_identity' => [
            'profile' => 't06n_synthetic', 'artifact_sha256' => ['synthetic.pkl' => str_repeat('a', 64)],
            'policy_sha256' => ['crc_recall_floor' => str_repeat('b', 64)],
            'runtime_config' => ['score_threshold' => 0.95, 'policy_active' => [
                'label_thresholds' => false, 'crc_recall_floor' => true, 'sector_guard' => false,
            ]], 'serving_identity_sha256' => str_repeat('c', 64),
        ]]]];
}

function t06nSource(): array
{
    $row = Characterization::query()->create(['user_id' => User::factory()->create()->id,
        'status' => 'completed', 'form_data' => [], 'submission_generation' => 1]);
    app(CharacterizationStateTransaction::class)->runForUser($row->user_id,
        fn ($current) => $current->update(['result_data' => t06nFrame()]));
    return [$row->fresh(), (new LearningP6BaseSourceRevisionClock)->current($row->id)];
}

it('t06n returns the exact persisted current frame repeatedly without writes', function () {
    [$row, $header] = t06nSource();
    $service = new LearningP6Snapshot;
    $expected = ['source_header' => $header, 'projection' => $service->project($row)];
    $source = $row->getRawOriginal();
    DB::enableQueryLog(); DB::flushQueryLog();
    expect($service->projectForAccount($row->user_id, array_reverse($header, true)))->toBe($expected);
    expect($service->projectForAccount($row->user_id, $header))->toBe($expected);
    $queries = DB::getQueryLog(); DB::disableQueryLog();
    expect(array_filter($queries, fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query'])))->toBe([]);
    expect($row->fresh()->getRawOriginal())->toBe($source);
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBe($header);
});

it('t06n refuses inactive modes before PDO or queries', function (mixed $flag, bool $production) {
    $connection = DB::connection(); $pdo = $connection->getPdo();
    config(['services.learning_p6_base_source_clock.enabled' => $flag]);
    if ($production) { app()->instance('env', 'production'); }
    $property = new ReflectionProperty($connection, 'pdo');
    $property->setValue($connection, fn () => throw new RuntimeException('t06n PDO touched'));
    try {
        expect(fn () => (new LearningP6Snapshot)->projectForAccount(1, []))
            ->toThrow(DomainException::class, 'learning_p6.current_disabled');
    } finally { $property->setValue($connection, $pdo); app()->instance('env', 'testing'); }
})->with([[null, false], [false, false], [1, false], ['true', false], [true, true]]);

it('t06n refuses unsafe declared connections before PDO', function (string $driver, string $database, bool $models) {
    $default = DB::getDefaultConnection(); $resolver = Characterization::getConnectionResolver();
    config(['database.connections.t06n_unsafe' => ['driver' => $driver, 'database' => $database]]);
    $unsafe = DB::connection('t06n_unsafe');
    $unsafe->setPdo(fn () => throw new RuntimeException('t06n PDO touched'));
    if ($models) {
        Characterization::setConnectionResolver(new class($unsafe) implements Illuminate\Database\ConnectionResolverInterface {
            public function __construct(private $connection) {}
            public function connection($name = null) { return $this->connection; }
            public function getDefaultConnection() { return 't06n_unsafe'; }
            public function setDefaultConnection($name) {}
        });
    } else { DB::setDefaultConnection('t06n_unsafe'); }
    try {
        expect(fn () => (new LearningP6Snapshot)->projectForAccount(1, []))->toThrow(DomainException::class);
    } finally { DB::setDefaultConnection($default); Characterization::setConnectionResolver($resolver); }
})->with([['sqlite', 't06n-file.sqlite', false], ['mysql', ':memory:', false], ['sqlite', 't06n-model.sqlite', true]]);

it('t06n binds model memory PDO and permits a real shared alias', function (bool $shared) {
    [$row, $header] = t06nSource();
    $resolver = Characterization::getConnectionResolver();
    $alias = new Illuminate\Database\SQLiteConnection($shared ? DB::connection()->getPdo() : new PDO('sqlite::memory:'),
        ':memory:', '', ['driver' => 'sqlite', 'database' => ':memory:']);
    Characterization::setConnectionResolver(new class($alias) implements Illuminate\Database\ConnectionResolverInterface {
        public function __construct(private $connection) {}
        public function connection($name = null) { return $this->connection; }
        public function getDefaultConnection() { return 't06n_alias'; }
        public function setDefaultConnection($name) {}
    });
    try {
        $call = fn () => (new LearningP6Snapshot)->projectForAccount($row->user_id, $header);
        if ($shared) { expect($call()['source_header'])->toBe($header); }
        else { expect($call)->toThrow(DomainException::class, 'learning_p6.connection_mismatch'); }
    } finally { Characterization::setConnectionResolver($resolver); }
})->with([false, true]);

it('t06n validates every expected header key type and value strictly', function () {
    [$row, $header] = t06nSource(); $service = new LearningP6Snapshot;
    $bad = [[], $header + ['unknown' => 1]];
    foreach ($header as $key => $value) {
        $missing = $header; unset($missing[$key]); $bad[] = $missing;
        $changed = $header; $changed[$key] = is_int($value) ? $value + 1 : str_repeat('f', 64); $bad[] = $changed;
        $typed = $header; $typed[$key] = is_int($value) ? (string) $value : 1; $bad[] = $typed;
    }
    foreach ($bad as $expected) {
        expect(fn () => $service->projectForAccount($row->user_id, $expected))->toThrow(DomainException::class);
    }
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBe($header);
});

it('t06n rejects missing source and absent header without seeding', function () {
    [$row, $header] = t06nSource();
    $other = User::factory()->create();
    expect(fn () => (new LearningP6Snapshot)->projectForAccount($other->id, $header))->toThrow(DomainException::class);
    DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id)->delete();
    expect(fn () => (new LearningP6Snapshot)->projectForAccount($row->user_id, $header))->toThrow(DomainException::class);
    expect(DB::table('learning_p6_base_source_revisions')->count())->toBe(0);
});

it('t06n rejects a foreign actor header', function () {
    [$row, $header] = t06nSource(); [$other] = t06nSource();
    expect(fn () => (new LearningP6Snapshot)->projectForAccount($other->user_id, $header))->toThrow(DomainException::class);
});

function t06nRetrieved(Closure $hook, Closure $operation): mixed
{
    $dispatcher = Characterization::getEventDispatcher();
    Characterization::setEventDispatcher(clone $dispatcher);
    Characterization::retrieved($hook);
    try { return $operation(); }
    finally { Characterization::setEventDispatcher($dispatcher); }
}

it('t06n projects raw original rather than retrieved dirty attributes', function () {
    [$row, $header] = t06nSource();
    $expected = (new LearningP6Snapshot)->project($row);
    $frame = t06nRetrieved(function ($retrieved) {
        $retrieved->result_data = ['dirty' => true]; $retrieved->status = 'failed';
        $retrieved->user_id = 999; $retrieved->id = 999;
    }, fn () => (new LearningP6Snapshot)->projectForAccount($row->user_id, $header));
    expect($frame['projection'])->toBe($expected)->and($frame['source_header'])->toBe($header);
});

it('t06n retains legacy parser failclosed behavior on persisted metadata', function () {
    foreach ([['status' => 'completed'], ['status' => 'failed'], t06nFrame() + ['legacy' => true]] as $index => $frame) {
        [$row, $header] = t06nSource();
        if ($index === 2) { unset($frame['mapping_metadata']['python']['serving_identity']); }
        app(CharacterizationStateTransaction::class)->runForUser($row->user_id, fn ($current) => $current->update(['result_data' => $frame]));
        $header = (new LearningP6BaseSourceRevisionClock)->current($row->id);
        expect(fn () => (new LearningP6Snapshot)->projectForAccount($row->user_id, $header))->toThrow(InvalidArgumentException::class);
    }
});

it('t06n binds the cached projected row and rolls late drift back with scope recovery', function (string $variant) {
    [$row, $header] = t06nSource(); $original = $row->getRawOriginal(); $calls = 0;
    $hook = function ($retrieved) use (&$calls, $row, $variant) {
        // Common reads the locked source first, then the owner's before-row.
        if (++$calls !== 2) { return; }
        $frame = t06nFrame();
        switch ($variant) {
            case 'source': $frame['late'] = true; break;
            case 'model': $frame['mapping_metadata']['python']['serving_identity']['artifact_sha256']['synthetic.pkl'] = str_repeat('d', 64); break;
            case 'policy': $frame['mapping_metadata']['python']['serving_identity']['policy_sha256']['crc_recall_floor'] = str_repeat('d', 64); break;
            case 'header': DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 99]); return;
            case 'null': DB::table('characterizations')->where('id', $row->id)->delete(); return;
            case 'actor': DB::table('characterizations')->where('id', $row->id)->update(['user_id' => User::factory()->create()->id]); return;
            case 'identity': $raw = $retrieved->getRawOriginal(); $raw['id'] = 999; $retrieved->setRawAttributes($raw, true); return;
            case 'mode': config(['services.learning_p6_base_source_clock.enabled' => false]); return;
            case 'status': DB::table('characterizations')->where('id', $row->id)->update(['status' => 'failed']); return;
        }
        DB::table('characterizations')->where('id', $row->id)->update(['result_data' => json_encode($frame)]);
    };
    try {
        t06nRetrieved($hook, fn () => expect(fn () => (new LearningP6Snapshot)->projectForAccount($row->user_id, $header))->toThrow(DomainException::class));
    } finally { config(['services.learning_p6_base_source_clock.enabled' => true]); }
    expect($row->fresh()->getRawOriginal())->toBe($original);
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBe($header);
    expect((new LearningP6Snapshot)->projectForAccount($row->user_id, $header)['source_header'])->toBe($header);
})->with(['source', 'model', 'policy', 'header', 'null', 'actor', 'identity', 'mode', 'status']);

it('t06n verifies late final readback within the bounded seam', function (string $variant) {
    foreach ([6, 7] as $target) {
        [$row, $header] = t06nSource(); $source = $row->getRawOriginal(); $calls = 0;
        $service = new LearningP6Snapshot;
        $expected = ['source_header' => $header, 'projection' => $service->project($row)];
        $physical = false; $returned = null; $exception = null;
        $hook = function () use (&$calls, &$physical, $row, $variant, $target) {
            if (++$calls !== $target) { return; }
            if ($variant === 'mode') { config(['services.learning_p6_base_source_clock.enabled' => false]); return; }
            $values = match ($variant) {
                'status' => ['status' => 'failed'],
                'actor' => ['user_id' => User::factory()->create()->id],
                'source' => ['result_data' => json_encode(t06nFrame() + ['late' => true])],
            };
            $changed = DB::table('characterizations')->where('id', $row->id)->update($values);
            $persisted = (array) DB::table('characterizations')->where('id', $row->id)->first();
            $physical = $changed === 1 && array_intersect_key($persisted, $values) === $values;
        };
        try {
            $returned = t06nRetrieved($hook, fn () => $service->projectForAccount($row->user_id, $header));
        } catch (DomainException $caught) {
            $exception = $caught;
        } finally { config(['services.learning_p6_base_source_clock.enabled' => true]); }
        expect($calls)->toBeGreaterThanOrEqual($target);
        if ($variant !== 'mode') { expect($physical)->toBeTrue(); }
        if ($exception === null) {
            fwrite(STDERR, 'T06n-R1 counterexample '.json_encode([
                'variant' => $variant, 'target' => $target, 'calls' => $calls,
                'physical_mutation' => $physical, 'returned_old_frame' => $returned === $expected,
                'live_source_changed' => $row->fresh()->getRawOriginal() !== $source,
                'header_before' => $header,
                'header_after' => (array) DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id)->first(),
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        expect($exception)->toBeInstanceOf(DomainException::class);
        expect($row->fresh()->getRawOriginal())->toBe($source);
        $clock = new LearningP6BaseSourceRevisionClock;
        expect($clock->current($row->id))->toBe($header);
        expect($clock->hasActiveScope($row->user_id))->toBeFalse();
        expect($service->projectForAccount($row->user_id, $header))->toBe($expected);
    }
})->with(['status', 'actor', 'source', 'mode']);
