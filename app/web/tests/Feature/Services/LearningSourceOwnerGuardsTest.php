<?php

use App\Models\{Characterization, User};
use App\Services\{CharacterizationStateTransaction, LearningP5SourceRevisionClock, LearningP6BaseSourceRevisionClock};
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Foundation\Testing\RefreshDatabaseState;

beforeEach(function () {
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->commit();
    RefreshDatabaseState::$migrated = false;
    Http::preventStrayRequests();
    t06i_profile('off');
});

function t06i_profile(string $owner): void
{
    config(['services.learning_source_clock.enabled' => in_array($owner, ['p5', 'both', 'all']),
        'services.learning_p6_base_source_clock.enabled' => in_array($owner, ['p6', 'both', 'all']),
        'services.learning_p8_source_clock.enabled' => $owner === 'all']);
}

function t06i_row(): Characterization
{
    return Characterization::query()->create(['user_id' => User::factory()->create()->id,
        'status' => 'draft', 'submission_generation' => 1, 'form_data' => []])->fresh();
}

function t06i_snapshot(): array
{
    $snapshot = [];
    foreach (['characterizations', 'learning_p5_source_revisions', 'learning_p6_base_source_revisions', 'learning_p8_source_revisions'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy($table === 'characterizations' ? 'id' : 'characterization_id')->get()->map(fn ($r) => (array) $r)->all();
    }
    return $snapshot;
}

function t06i_write(Characterization $row, array $changes): mixed
{
    return app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update($changes));
}

function t06i_seed(Characterization $row): void
{
    t06i_write($row, ['form_data' => '{"company_profile":{"synthetic":"A"},"materiality_proposal_review":{"synthetic":"A"},"materiality_confirmation":{"synthetic":"A"}}']);
}

function t06i_actor_matrix(string $owner, string $kind, bool $tracked): void
{
    t06i_profile($owner); $row = t06i_row(); $other = User::factory()->create();
    if ($tracked) { t06i_seed($row); }
    $before = t06i_snapshot(); $calls = 0;
    $clock = $owner === 'p5' ? new LearningP5SourceRevisionClock : new LearningP6BaseSourceRevisionClock;
    $operation = function () use ($row, $other, $kind, &$calls) {
        $calls++;
        if ($kind === 'before') { return 'synthetic'; }
        DB::table('characterizations')->where('id', $row->id)->update(
            $kind === 'id' ? ['id' => $row->id + 1000] : ['user_id' => $other->id]);
    };
    $invoke = match ($kind) {
        'before' => fn () => DB::transaction(fn () => $clock->observe($other->id, fn () => $row->fresh(), $operation)),
        'nullable' => fn () => app(CharacterizationStateTransaction::class)->runForUser($row->user_id, $operation),
        default => fn () => app(CharacterizationStateTransaction::class)->run($row->id, $operation),
    };
    expect($invoke)->toThrow(DomainException::class);
    expect($calls)->toBe($kind === 'before' ? 0 : 1);
    expect(t06i_snapshot())->toBe($before);
    t06i_seed($row);
    expect($clock->current($row->id)['revision'])->toBe(1);
    expect(DB::connection()->transactionLevel())->toBe(0);
}

it('T06i P5 actor guard rejects before visible-after and lost lookup', function ($kind, $tracked) {
    t06i_actor_matrix('p5', $kind, $tracked);
})->with([['before', false], ['before', true], ['id', false], ['visible', false], ['visible', true], ['nullable', false], ['nullable', true]]);

it('T06i P6 actor guard rejects before visible-after and lost lookup', function ($kind, $tracked) {
    t06i_actor_matrix('p6', $kind, $tracked);
})->with([['before', false], ['before', true], ['id', false], ['visible', false], ['visible', true], ['nullable', false], ['nullable', true]]);

function t06i_integer_matrix(string $owner, string $column, string $raw, bool $tracked): void
{
    t06i_profile($owner); $row = t06i_row();
    $clock = $owner === 'p5' ? new LearningP5SourceRevisionClock : new LearningP6BaseSourceRevisionClock;
    if ($tracked) { t06i_seed($row); }
    $before = t06i_snapshot(); $calls = 0;
    expect(fn () => t06i_write($row, [$column => $raw]))->toThrow(DomainException::class, 'unsupported_integer');
    expect(t06i_snapshot())->toBe($before);
    // A restored legitimate operation succeeds before testing already-stored loss.
    t06i_seed($row);
    if (! $tracked) { $row = t06i_row(); }
    $restore = $row->fresh()->getRawOriginal($column);
    DB::table('characterizations')->where('id', $row->id)->update([$column => $raw]);
    $stored = t06i_snapshot();
    if ($tracked) { expect(fn () => $clock->current($row->id))->toThrow(DomainException::class, 'unsupported_integer'); }
    else { expect($clock->current($row->id))->toBeNull(); }
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use (&$calls) { $calls++; }))->toThrow(DomainException::class, 'unsupported_integer');
    expect($calls)->toBe(0)->and(t06i_snapshot())->toBe($stored);
    DB::table('characterizations')->where('id', $row->id)->update([$column => $restore]);
    t06i_seed($row);
    expect($clock->current($row->id)['revision'])->toBe(1);
}

it('T06i P5 integer guard rejects owned native-unrepresentable source', function ($raw, $tracked) {
    t06i_integer_matrix('p5', 'form_data', $raw, $tracked);
})->with([
    ['{"company_profile":{"synthetic":18446744073709551616}}', false],
    ['{"company_profile":{"synthetic":18446744073709551617}}', true],
    ['{"operations":{"synthetic":-18446744073709551617}}', false],
    ['{"operations":{"synthetic":18446744073709551617}}', true],
]);

it('T06i P6 integer guard rejects owned native-unrepresentable source', function ($column, $raw, $tracked) {
    t06i_integer_matrix('p6', $column, $raw, $tracked);
})->with([
    ['result_data', '{"synthetic":18446744073709551616}', false],
    ['result_data', '{"synthetic":18446744073709551617}', true],
    ['esrs_topic_ids', '[18446744073709551616]', false],
    ['esrs_topic_ids', '[-18446744073709551617]', true],
    ['form_data', '{"esg_focus":{"topic_ids":[18446744073709551616]}}', false],
    ['form_data', '{"esg_focus":{"topic_ids":[18446744073709551617]}}', true],
    ['form_data', '{"materiality_proposal_review":{"synthetic":18446744073709551616}}', false],
    ['form_data', '{"materiality_proposal_review":{"synthetic":18446744073709551617}}', true],
]);

it('T06i controls preserve representable values and ignore foreign integers', function ($owner, $raw) {
    t06i_profile($owner); $row = t06i_row();
    t06i_write($row, ['form_data' => $raw]);
    $clock = $owner === 'p5' ? new LearningP5SourceRevisionClock : new LearningP6BaseSourceRevisionClock;
    $header = $clock->current($row->id);
    expect($header['revision'])->toBe(1);
    t06i_write($row, ['form_data' => $raw]);
    expect($clock->current($row->id))->toBe($header);
})->with([
    ['p5', '{"company_profile":{"min":-9223372036854775808,"max":9223372036854775807,"quoted":"18446744073709551617","float":1.7976931348623157e308,"exp":1e20}}'],
    ['p6', '{"materiality_proposal_review":{"min":-9223372036854775808,"max":9223372036854775807,"quoted":"18446744073709551617","float":1.7976931348623157e308,"exp":1e20}}'],
    ['p5', '{"company_profile":{},"other":18446744073709551617,"materiality_confirmation":{"x":18446744073709551617}}'],
    ['p6', '{"company_profile":{"x":18446744073709551617},"esg_focus":{"other":18446744073709551617},"materiality_confirmation":{"x":18446744073709551617}}'],
    ['p5', '{"operations":{"x":"18446744073709551617"},"esg_focus":{"other":18446744073709551617}}'],
    ['p6', '{"materiality_proposal_review":{},"other":18446744073709551617}'],
]);

it('T06i controls preserve canonical keys list order and integer float distinction', function ($owner, $section) {
    t06i_profile($owner); $row = t06i_row();
    $clock = $owner === 'p5' ? new LearningP5SourceRevisionClock : new LearningP6BaseSourceRevisionClock;
    t06i_write($row, ['form_data' => '{"'.$section.'":{"b":[1,2],"a":1}}']);
    $first = $clock->current($row->id);
    t06i_write($row, ['form_data' => '{"'.$section.'":{"a":1,"b":[1,2]}}']);
    expect($clock->current($row->id))->toBe($first);
    t06i_write($row, ['form_data' => '{"'.$section.'":{"a":1.0,"b":[1,2]}}']);
    expect($clock->current($row->id)['revision'])->toBe(2);
    t06i_write($row, ['form_data' => '{"'.$section.'":{"a":1.0,"b":[2,1]}}']);
    expect($clock->current($row->id)['revision'])->toBe(3);
})->with([['p5', 'operations'], ['p6', 'materiality_proposal_review']]);

it('T06i controls support passive noop creation nesting physical cascade and rollback', function ($profile) {
    t06i_profile($profile); $user = User::factory()->create();
    $row = app(CharacterizationStateTransaction::class)->runForUser($user->id, fn () => Characterization::query()->create([
        'user_id' => $user->id, 'status' => 'draft', 'submission_generation' => 1, 'form_data' => []])->fresh());
    $before = t06i_snapshot();
    expect(app(CharacterizationStateTransaction::class)->run($row->id, fn () => 'SYNTHETIC'))->toBe('SYNTHETIC');
    expect(t06i_snapshot())->toBe($before);
    app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row) { t06i_seed($row); t06i_seed($row); });
    foreach (['p5' => 'learning_p5_source_revisions', 'p6' => 'learning_p6_base_source_revisions', 'p8' => 'learning_p8_source_revisions'] as $owner => $table) {
        $enabled = $profile === 'all' || $profile === 'both' && $owner !== 'p8' || $profile === $owner;
        expect(DB::table($table)->where('characterization_id', $row->id)->value('revision'))->toBe($enabled ? 2 : null);
    }
    $before = t06i_snapshot();
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row) {
        DB::table('characterizations')->where('id', $row->id)->delete(); throw new DomainException('synthetic rollback');
    }))->toThrow(DomainException::class);
    expect(t06i_snapshot())->toBe($before);
    app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->delete());
    foreach (t06i_snapshot() as $rows) { expect($rows)->toBe([]); }
    $untracked = t06i_row();
    app(CharacterizationStateTransaction::class)->runForUser($untracked->user_id, fn () => DB::table('characterizations')->where('id', $untracked->id)->delete());
    expect(t06i_snapshot()['characterizations'])->toBe([]);
})->with(['p5', 'p6', 'both', 'all']);

it('T06i controls reject invalid persisted actor before callback', function ($owner, $rawActor) {
    t06i_profile($owner); $row = t06i_row(); $raw = $row->getRawOriginal(); $raw['user_id'] = $rawActor;
    $fake = new Characterization; $fake->setRawAttributes($raw, true); $calls = 0; $before = t06i_snapshot();
    $clock = $owner === 'p5' ? new LearningP5SourceRevisionClock : new LearningP6BaseSourceRevisionClock;
    expect(fn () => DB::transaction(fn () => $clock->observe($row->user_id, fn () => $fake, function () use (&$calls) { $calls++; })))->toThrow(DomainException::class, 'invalid_integer');
    expect($calls)->toBe(0)->and(t06i_snapshot())->toBe($before);
})->with([['p5', null], ['p5', '1x'], ['p6', null], ['p6', '1x']]);

it('T06i controls retain parse shape and nonfinite rejection', function ($owner) {
    t06i_profile($owner); $row = t06i_row(); $before = t06i_snapshot();
    foreach (['{', '{"company_profile":1,"materiality_proposal_review":1}', '{"other":1e400}', "{\"other\":\"\xff\"}", str_repeat('[', 513).'0'.str_repeat(']', 513)] as $raw) {
        expect(fn () => t06i_write($row, ['form_data' => $raw]))->toThrow(DomainException::class);
        expect(t06i_snapshot())->toBe($before);
    }
})->with(['p5', 'p6']);

it('T06i controls keep default OFF compatibility', function () {
    $row = t06i_row(); $other = User::factory()->create();
    t06i_write($row, ['user_id' => $other->id, 'form_data' => '{"company_profile":{"x":18446744073709551617}}']);
    expect($row->fresh()->user_id)->toBe($other->id);
    foreach (array_slice(t06i_snapshot(), 1) as $headers) { expect($headers)->toBe([]); }
});

it('T06i controls roll back coupled owners and recover', function ($profile) {
    t06i_profile($profile); $row = t06i_row(); t06i_seed($row); $before = t06i_snapshot();
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn () =>
        DB::table('characterizations')->where('id', $row->id)->update([
            'form_data' => '{"company_profile":{"synthetic":"B"},"materiality_proposal_review":{"synthetic":"B"},"materiality_confirmation":{"synthetic":"B"}}',
            'result_data' => '{"synthetic":18446744073709551617}',
        ])))->toThrow(DomainException::class, 'unsupported_integer');
    expect(t06i_snapshot())->toBe($before);
    t06i_write($row, ['form_data' => '{"company_profile":{"synthetic":"C"},"materiality_proposal_review":{"synthetic":"C"},"materiality_confirmation":{"synthetic":"C"}}']);
    foreach (['learning_p5_source_revisions', 'learning_p6_base_source_revisions'] as $table) {
        expect(DB::table($table)->where('characterization_id', $row->id)->value('revision'))->toBe(2);
    }
})->with(['both', 'all']);

function t06i_r1_late_actor(string $profile, string $writeOwner, bool $tracked, bool $nullable, bool $invalid): void
{
    t06i_profile($profile);
    expect(config('services.learning_p8_source_clock.enabled'))->toBeFalse();
    $row = t06i_row();
    $other = User::factory()->create($invalid ? ['id' => 0] : []);
    if ($tracked) { t06i_seed($row); }
    $before = t06i_snapshot();
    $calls = 0; $faults = 0;
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $table = $writeOwner === 'p5' ? 'learning_p5_source_revisions' : 'learning_p6_base_source_revisions';
    $verb = $tracked ? 'update' : 'insert';
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($table, $verb, $row, $other, &$faults) {
        if ($faults === 0 && str_starts_with(strtolower($query->sql), $verb.' ') && str_contains($query->sql, '"'.$table.'"')) {
            $faults++;
            DB::table('characterizations')->where('id', $row->id)->update(['user_id' => $other->id]);
        }
    });
    $operation = function () use ($row, &$calls) {
        $calls++;
        DB::table('characterizations')->where('id', $row->id)->update([
            'form_data' => '{"company_profile":{"synthetic":"R1"},"materiality_proposal_review":{"synthetic":"R1"}}',
        ]);
        return 'R1';
    };
    $error = null;
    try {
        try {
            $tx = app(CharacterizationStateTransaction::class);
            $nullable ? $tx->runForUser($row->user_id, $operation) : $tx->run($row->id, $operation);
        } catch (\Throwable $caught) { $error = $caught; }
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    expect($calls)->toBe(1)->and($faults)->toBe(1);
    expect($error)->toBeInstanceOf(DomainException::class);
    $namespace = $writeOwner === 'p5' ? 'learning_p5_clock.' : 'learning_p6_base_clock.';
    expect($error->getMessage())->toBe($namespace.($nullable ? 'readback_mismatch' : ($invalid ? 'invalid_integer' : 'actor_mismatch')));
    expect(t06i_snapshot())->toBe($before);
    expect(DB::connection()->transactionLevel())->toBe(0);
    // A real subsequent commit proves listener and owner-scope cleanup.
    expect(t06i_write($row, ['form_data' => '{"company_profile":{"synthetic":"RECOVER"},"materiality_proposal_review":{"synthetic":"RECOVER"}}']))->toBe(1);
    expect($faults)->toBe(1)->and($row->fresh()->getRawOriginal('user_id'))->toBe($row->getRawOriginal('user_id'));
    foreach (['p5' => 'learning_p5_source_revisions', 'p6' => 'learning_p6_base_source_revisions', 'p8' => 'learning_p8_source_revisions'] as $owner => $headerTable) {
        $enabled = $profile === $owner || $profile === 'both' && $owner !== 'p8';
        expect(DB::table($headerTable)->where('characterization_id', $row->id)->value('revision'))->toBe($enabled ? ($tracked ? 2 : 1) : null);
    }
    expect(DB::connection()->transactionLevel())->toBe(0);
}

function t06i_r1_vectors(): array
{
    return [
        'insert run valid actor' => [false, false, false],
        'insert run invalid actor' => [false, false, true],
        'insert runForUser valid actor' => [false, true, false],
        'insert runForUser invalid actor' => [false, true, true],
        'update run valid actor' => [true, false, false],
        'update run invalid actor' => [true, false, true],
        'update runForUser valid actor' => [true, true, false],
        'update runForUser invalid actor' => [true, true, true],
    ];
}

it('T06i R1 P5 late actor actual metadata write rejects and recovers', function ($tracked, $nullable, $invalid) {
    t06i_r1_late_actor('p5', 'p5', $tracked, $nullable, $invalid);
})->with(t06i_r1_vectors());

it('T06i R1 both outer P5 late actor actual metadata write rejects and recovers', function ($tracked, $nullable, $invalid) {
    t06i_r1_late_actor('both', 'p5', $tracked, $nullable, $invalid);
})->with(t06i_r1_vectors());
it('T06i R1 P6 late actor actual metadata write rejects and recovers', function ($tracked, $nullable, $invalid) {
    t06i_r1_late_actor('p6', 'p6', $tracked, $nullable, $invalid);
})->with(t06i_r1_vectors());

it('T06i R1 PASS_EXISTING sibling P5 catches visible inner P6 actor move', function ($tracked) {
    t06i_profile('both'); $row = t06i_row(); $other = User::factory()->create();
    if ($tracked) { t06i_seed($row); }
    $before = t06i_snapshot(); $calls = 0; $faults = 0;
    $connection = DB::connection(); $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($row, $other, &$faults) {
        if ($faults === 0 && preg_match('/^(insert|update) /i', $query->sql) && str_contains($query->sql, '"learning_p6_base_source_revisions"')) {
            $faults++;
            DB::table('characterizations')->where('id', $row->id)->update(['user_id' => $other->id]);
        }
    });
    try {
        expect(function () use ($row, &$calls) {
            app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row, &$calls) {
                $calls++;
                DB::table('characterizations')->where('id', $row->id)->update(['result_data' => '{"synthetic":"R1"}']);
            });
        })->toThrow(DomainException::class);
    } finally { $connection->setEventDispatcher($dispatcher); }
    expect($calls)->toBe(1)->and($faults)->toBe(1)->and(t06i_snapshot())->toBe($before);
    t06i_write($row, ['result_data' => '{"synthetic":"RECOVER"}']);
    expect($faults)->toBe(1)->and($row->fresh()->user_id)->toBe($row->user_id);
    expect(DB::connection()->transactionLevel())->toBe(0);
})->with([false, true]);
it('T06i R1 unchanged actor and source commits without header writes', function ($profile, $nullable) {
    t06i_profile($profile); $row = t06i_row(); t06i_seed($row);
    expect(config('services.learning_p8_source_clock.enabled'))->toBeFalse();
    $before = t06i_snapshot(); $calls = 0; $writes = 0;
    $connection = DB::connection(); $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$writes) {
        if (preg_match('/^(insert|update) /i', $query->sql)
            && (str_contains($query->sql, '"learning_p5_source_revisions"') || str_contains($query->sql, '"learning_p6_base_source_revisions"'))) {
            $writes++;
        }
    });
    try {
        $operation = function () use (&$calls) { $calls++; return 'UNCHANGED'; };
        $tx = app(CharacterizationStateTransaction::class);
        expect($nullable ? $tx->runForUser($row->user_id, $operation) : $tx->run($row->id, $operation))->toBe('UNCHANGED');
        expect($calls)->toBe(1)->and($writes)->toBe(0)->and(t06i_snapshot())->toBe($before);
        expect(DB::connection()->transactionLevel())->toBe(0);
        t06i_write($row, ['form_data' => '{"company_profile":{"synthetic":"NEXT"},"materiality_proposal_review":{"synthetic":"NEXT"}}']);
        expect($writes)->toBe($profile === 'both' ? 2 : 1);
    } finally { $connection->setEventDispatcher($dispatcher); }
    expect($row->fresh()->getRawOriginal('user_id'))->toBe($row->getRawOriginal('user_id'));
    expect(DB::connection()->transactionLevel())->toBe(0);
})->with([['p5', false], ['p5', true], ['p6', false], ['p6', true], ['both', false], ['both', true]]);