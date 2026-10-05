<?php

use App\Models\{Characterization, User};
use App\Services\{CharacterizationStateTransaction, LearningP5SourceRevisionClock};
use Illuminate\Support\Facades\{DB, Schema};

beforeEach(function () {
    config(['services.learning_source_clock.enabled' => true]);
});

function p5clockRow(User $user, string $name = 'SYNTHETIC_A'): Characterization
{
    return Characterization::query()->create([
        'user_id' => $user->id, 'status' => 'draft',
        'form_data' => ['company_profile' => ['company_name' => $name]],
    ]);
}

function p5clockChange(Characterization $row, string $name): mixed
{
    return app(CharacterizationStateTransaction::class)->run($row->id, function ($fresh) use ($name) {
        $data = $fresh->form_data;
        $data['company_profile']['company_name'] = $name;
        $fresh->update(['form_data' => $data]);
        return 'original-result';
    });
}

function p5clockHeader(Characterization $row): array
{
    return (array) DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->first();
}

it('owns an actual creation through the common transaction', function () {
    $user = User::factory()->create();
    $result = app(CharacterizationStateTransaction::class)->runForUser($user->id, function ($row, $locked) {
        p5clockRow($locked);
        return 42;
    });
    expect($result)->toBe(42);
    expect(Schema::hasTable('learning_p5_source_revisions'))->toBeTrue();
    $header = p5clockHeader($user->characterization()->firstOrFail());
    expect($header['revision'])->toBe(1)->and($header['generation'])->toBe(0);
});

it('keeps absent and legacy read no-op untracked', function () {
    $user = User::factory()->create();
    $tx = app(CharacterizationStateTransaction::class);
    expect($tx->runForUser($user->id, fn () => 'absent'))->toBe('absent');
    $row = p5clockRow($user);
    expect((new LearningP5SourceRevisionClock)->current($row->id))->toBeNull();
    $tx->run($row->id, fn ($fresh) => $fresh);
    expect(p5clockHeader($row))->toBe([]);
    expect(p5clockChange($row, 'SYNTHETIC_B'))->toBe('original-result');
    expect(p5clockHeader($row)['revision'])->toBe(1);
});

it('saves an actual Recorder draft from a persisted legacy empty root', function () {
    $user = User::factory()->create();
    $row = Characterization::query()->create([
        'user_id' => $user->id, 'status' => 'draft', 'form_data' => [],
    ])->fresh();
    expect($row->getRawOriginal('form_data'))->toBe('[]');
    $saved = app(App\Services\CharacterizationRecorder::class)->save($user, [
        'status' => 'draft',
        'form_data' => ['company_profile' => ['headquarters_country' => 'Spain']],
    ]);
    $fresh = $saved->fresh();
    expect($fresh->id)->toBe($row->id);
    expect($fresh->form_data['company_profile']['headquarters_country'])->toBe('Spain');
    expect(p5clockHeader($fresh))->toMatchArray(['revision' => 1, 'generation' => 0]);
    expect((new LearningP5SourceRevisionClock)->current($fresh->id))->toBe(p5clockHeader($fresh));
});

it('leaves query behavior unchanged when default off or production guarded', function () {
    $row = p5clockRow(User::factory()->create());
    foreach ([null, false, 1, 'true', true] as $flag) {
        config(['services.learning_source_clock.enabled' => $flag]);
        if ($flag === true) { app()->instance('env', 'production'); }
        DB::enableQueryLog();
        DB::flushQueryLog();
        p5clockChange($row, 'SYNTHETIC_OFF');
        $queries = DB::getQueryLog();
        expect(array_filter($queries, fn ($q) => str_contains($q['query'], 'learning_p5_source')))->toBe([]);
        DB::disableQueryLog();
        expect(p5clockHeader($row))->toBe([]);
    }
    app()->instance('env', 'testing');
});

it('rejects enabled non disposable connection configuration', function () {
    $row = p5clockRow(User::factory()->create());
    $connection = DB::connection();
    $old = $connection->getDatabaseName();
    $connection->setDatabaseName('synthetic-file.sqlite');
    try {
        expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(DomainException::class);
    } finally { $connection->setDatabaseName($old); }
    expect($row->fresh()->form_data['company_profile']['company_name'])->toBe('SYNTHETIC_A');
});

it('advances committed ABA within one epoch and changes identity after clock loss', function () {
    $row = p5clockRow(User::factory()->create(), 'PRE');
    p5clockChange($row, 'SYNTHETIC_A');
    $a = p5clockHeader($row);
    p5clockChange($row, 'SYNTHETIC_B');
    $b = p5clockHeader($row);
    p5clockChange($row, 'SYNTHETIC_A');
    $again = p5clockHeader($row);
    expect([$a['revision'], $b['revision'], $again['revision']])->toBe([1, 2, 3]);
    expect($again['digest'])->toBe($a['digest'])->and($again['epoch'])->toBe($a['epoch']);
    // Synthetic privileged loss is explicitly outside any WORM guarantee.
    DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->delete();
    p5clockChange($row, 'SYNTHETIC_B');
    p5clockChange($row, 'SYNTHETIC_A');
    $restart = p5clockHeader($row);
    expect($restart['epoch'])->not->toBe($a['epoch'])->and($restart['digest'])->not->toBe($a['digest']);
});

it('canonicalizes recursive maps but preserves lists and header no-op bytes', function () {
    $row = p5clockRow(User::factory()->create());
    $tx = app(CharacterizationStateTransaction::class);
    $tx->run($row->id, fn ($r) => $r->update(['form_data' => ['company_profile' => ['b' => ['z' => 1, 'a' => 2], 'a' => ['x', 'y']]]]));
    $before = p5clockHeader($row);
    $tx->run($row->id, fn ($r) => $r->update(['form_data' => ['company_profile' => ['a' => ['x', 'y'], 'b' => ['a' => 2, 'z' => 1]]]]));
    expect(p5clockHeader($row))->toBe($before);
    $tx->run($row->id, fn ($r) => $r->update(['form_data' => ['company_profile' => ['a' => ['y', 'x'], 'b' => ['a' => 2, 'z' => 1]]]]));
    expect(p5clockHeader($row)['revision'])->toBe(2);
});

it('tracks operations nace and generation without resetting revision', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $epoch = p5clockHeader($row)['epoch'];
    $tx = app(CharacterizationStateTransaction::class);
    $tx->run($row->id, function ($r) { $d = $r->form_data; $d['operations'] = ['regions' => ['SYNTHETIC_REGION']]; $r->update(['form_data' => $d]); });
    $tx->run($row->id, fn ($r) => $r->update(['nace_code' => '01.1']));
    $tx->run($row->id, fn ($r) => $r->update(['submission_generation' => 1]));
    expect(p5clockHeader($row))->toMatchArray(['revision' => 4, 'generation' => 1, 'epoch' => $epoch]);
});

it('ignores unrelated P6 P7 P8 P9 and user notes', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $header = p5clockHeader($row);
    app(CharacterizationStateTransaction::class)->run($row->id, function ($r) {
        $d = $r->form_data;
        foreach (['materiality_proposal_review', 'double_materiality_guide', 'materiality_confirmation', 'datapoint_responses', 'notes'] as $key) { $d[$key] = ['synthetic' => true]; }
        $r->update(['form_data' => $d, 'result_data' => ['synthetic' => true], 'esrs_topic_ids' => ['E1'], 'status' => 'completed']);
    });
    expect(p5clockHeader($row))->toBe($header);
});

it('keeps null missing and explicit negative source presence distinct', function () {
    $row = p5clockRow(User::factory()->create());
    $tx = app(CharacterizationStateTransaction::class);
    $digests = [];
    foreach ([null, '{}', '{"company_profile":null}', '{"company_profile":{"synthetic":false}}'] as $raw) {
        $tx->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]));
        $digests[] = p5clockHeader($row)['digest'];
    }
    expect(array_unique($digests))->toHaveCount(4);
});

it('rejects raw generation and malformed source siblings before callback', function () {
    $row = p5clockRow(User::factory()->create());
    $original = $row->getRawOriginal();
    foreach (['1suffix', '1.5', -1, '9007199254740992'] as $bad) {
        DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => $bad]);
        $called = false;
        expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use (&$called) { $called = true; }))->toThrow(DomainException::class);
        expect($called)->toBeFalse();
    }
    DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => 0]);
    foreach (['{', 'false', '[null]', '{"company_profile":[null]}', '{"operations":42}', '{"company_profile":{"x":1e999}}'] as $bad) {
        DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $bad]);
        expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(DomainException::class);
    }
    DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $original['form_data']]);
    p5clockChange($row, 'SYNTHETIC_RECOVERED');
    expect(p5clockHeader($row)['revision'])->toBe(1);
});

it('admits persisted unknown roots and optional maps without feature defaults', function () {
    $roots = [null, 'null', '[]', '{}'];
    foreach (['null', '[]', '{}'] as $empty) {
        $roots[] = '{"company_profile":'.$empty.'}';
        $roots[] = '{"operations":'.$empty.'}';
        $roots[] = '{"company_profile":'.$empty.',"operations":'.$empty.'}';
    }
    foreach ($roots as $raw) {
        $row = p5clockRow(User::factory()->create())->fresh();
        DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]);
        $row = $row->fresh();
        expect($row->getRawOriginal('form_data'))->toBe($raw);
        expect((new App\Services\LearningP5Snapshot)->project($row)['values'])->toBe([
            'employee_count_range' => null, 'headquarters_country' => null, 'stock_listed' => null,
        ]);
        $tx = app(CharacterizationStateTransaction::class);
        $tx->run($row->id, fn () => null);
        expect(p5clockHeader($row))->toBe([]);
        $tx->run($row->id, fn ($fresh) => $fresh->update(['nace_code' => '01']));
        expect($row->fresh()->getRawOriginal('form_data'))->toBe($raw);
        $header = p5clockHeader($row);
        expect($header)->toMatchArray(['revision' => 1, 'generation' => 0]);
        expect((new LearningP5SourceRevisionClock)->current($row->id))->toBe($header);
        $tx->run($row->id, fn () => null);
        expect(p5clockHeader($row))->toBe($header);
    }
});

it('preserves persisted null empty and missing section presence in source metadata', function () {
    $row = p5clockRow(User::factory()->create())->fresh();
    $tx = app(CharacterizationStateTransaction::class);
    $digests = [];
    foreach ([null, 'null', '{}', '{"company_profile":null}', '{"company_profile":[]}', '{"company_profile":{}}'] as $raw) {
        $tx->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]));
        expect($row->fresh()->getRawOriginal('form_data'))->toBe($raw);
        $header = p5clockHeader($row);
        expect((new LearningP5SourceRevisionClock)->current($row->id))->toBe($header);
        $digests[] = $header['digest'];
    }
    expect($digests[0])->not->toBe($digests[1]); // SQL missing versus JSON present.
    expect($digests[1])->toBe($digests[2]); // Both have missing optional sections.
    expect(array_unique($digests))->toHaveCount(5);
    expect(p5clockHeader($row)['revision'])->toBe(5);
});

it('denies persisted nonempty lists scalars invalid UTF8 and excessive parse depth', function () {
    $row = p5clockRow(User::factory()->create())->fresh();
    $original = $row->getRawOriginal('form_data');
    $badInputs = [
        '', '0', 'false', '"unknown"', '[{}]', '[null]',
        '{"company_profile":[{}]}', '{"operations":[null]}',
        '{"company_profile":false}', '{"operations":0}', '{"operations":"unknown"}',
        '{"company_profile":"'."\xB1".'"}',
        '{"company_profile":{"nested":'.str_repeat('[', 513).'0'.str_repeat(']', 513).'}}',
    ];
    foreach ($badInputs as $raw) {
        DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]);
        $row = $row->fresh();
        expect($row->getRawOriginal('form_data'))->toBe($raw);
        $called = false;
        expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use (&$called) {
            $called = true;
        }))->toThrow(DomainException::class);
        expect($called)->toBeFalse()->and(p5clockHeader($row))->toBe([]);
    }
    DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $original]);
    p5clockChange($row->fresh(), 'SYNTHETIC_RECOVERED');
    expect(p5clockHeader($row)['revision'])->toBe(1);
});

it('rejects invalid after state and rolls mutation back', function () {
    $row = p5clockRow(User::factory()->create());
    foreach ([['submission_generation' => 'bad'], ['form_data' => '{']] as $bad) {
        expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update($bad)))->toThrow(DomainException::class);
        expect($row->fresh()->getRawOriginal('form_data'))->toBe($row->getRawOriginal('form_data'));
        expect(p5clockHeader($row))->toBe([]);
    }
});

it('denies raw header corruption and out of band source drift without repair', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $header = p5clockHeader($row);
    foreach ([['revision' => '2suffix'], ['epoch' => 'bad'], ['generation' => 9], ['digest' => str_repeat('0', 64)]] as $bad) {
        DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->update($bad);
        expect(fn () => (new LearningP5SourceRevisionClock)->current($row->id))->toThrow(DomainException::class);
        expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(DomainException::class);
        DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->update($header);
    }
    DB::table('characterizations')->where('id', $row->id)->update(['nace_code' => '02']);
    expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(DomainException::class);
    expect(p5clockHeader($row))->toBe($header);
});

it('rejects late header drift including a no-op and rolls it back', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $header = p5clockHeader($row);
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 9])))->toThrow(DomainException::class);
    expect(p5clockHeader($row))->toBe($header);
});

it('rejects generation regression and saturated revision before mutation commits', function () {
    $row = p5clockRow(User::factory()->create());
    app(CharacterizationStateTransaction::class)->run($row->id, fn ($r) => $r->update(['submission_generation' => 2]));
    $header = p5clockHeader($row);
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn ($r) => $r->update(['submission_generation' => 1])))->toThrow(DomainException::class);
    expect($row->fresh()->submission_generation)->toBe(2);
    DB::table('learning_p5_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 9007199254740991]);
    expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(DomainException::class);
    expect($row->fresh()->form_data['company_profile']['company_name'])->toBe('SYNTHETIC_A');
    expect(p5clockHeader($row)['revision'])->toBe(9007199254740991);
});

it('uses fresh persisted state instead of unsaved callback model or scalar result', function () {
    $row = p5clockRow(User::factory()->create());
    $tx = app(CharacterizationStateTransaction::class);
    $returned = $tx->run($row->id, function ($r) { $r->form_data = ['company_profile' => ['unsaved' => true]]; return $r; });
    expect($returned->form_data)->toHaveKey('company_profile.unsaved');
    expect(p5clockHeader($row))->toBe([]);
    $other = p5clockRow(User::factory()->create());
    expect($tx->run($row->id, function ($r) use ($other) { $r->update(['nace_code' => '01']); return $other; })->id)->toBe($other->id);
    expect(p5clockHeader($row)['revision'])->toBe(1);
    expect(p5clockHeader($other))->toBe([]);
});

it('isolates users and exposes metadata only', function () {
    $a = p5clockRow(User::factory()->create());
    $b = p5clockRow(User::factory()->create());
    p5clockChange($a, 'SYNTHETIC_B');
    expect(p5clockHeader($b))->toBe([]);
    $header = (new LearningP5SourceRevisionClock)->current($a->id);
    expect(array_keys($header))->toBe(['characterization_id', 'generation', 'revision', 'epoch', 'digest']);
    expect(json_encode($header))->not->toContain('SYNTHETIC_B', 'company_profile', 'operations', 'nace_code');
});

it('rolls callback failures back and cleans the scope', function () {
    $row = p5clockRow(User::factory()->create());
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function ($r) { $r->update(['nace_code' => '01']); throw new RuntimeException('synthetic-failure'); }))->toThrow(RuntimeException::class, 'synthetic-failure');
    expect($row->fresh()->nace_code)->toBeNull()->and(p5clockHeader($row))->toBe([]);
    p5clockChange($row, 'SYNTHETIC_B');
    expect(p5clockHeader($row)['revision'])->toBe(1);
});

it('rolls before and after SQL faults and post write corruption back', function () {
    $row = p5clockRow(User::factory()->create());
    $pdo = DB::connection()->getPdo();
    $pdo->exec("CREATE TRIGGER synthetic_clock_insert_failure BEFORE INSERT ON learning_p5_source_revisions BEGIN SELECT RAISE(ABORT, 'synthetic-clock-fault'); END");
    expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(Illuminate\Database\QueryException::class);
    expect($row->fresh()->form_data['company_profile']['company_name'])->toBe('SYNTHETIC_A');
    $pdo->exec('DROP TRIGGER synthetic_clock_insert_failure');
    $pdo->exec("CREATE TRIGGER synthetic_clock_corruption AFTER INSERT ON learning_p5_source_revisions BEGIN UPDATE learning_p5_source_revisions SET digest = 'corrupt' WHERE characterization_id = NEW.characterization_id; END");
    expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(DomainException::class);
    expect(p5clockHeader($row))->toBe([]);
    $pdo->exec('DROP TRIGGER synthetic_clock_corruption');
    p5clockChange($row, 'SYNTHETIC_B');
    $header = p5clockHeader($row);
    $pdo->exec("CREATE TRIGGER synthetic_clock_update_failure BEFORE UPDATE ON learning_p5_source_revisions BEGIN SELECT RAISE(ABORT, 'synthetic-clock-fault'); END");
    expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(Illuminate\Database\QueryException::class);
    expect(p5clockHeader($row))->toBe($header);
    expect($row->fresh()->form_data['company_profile']['company_name'])->toBe('SYNTHETIC_B');
    $pdo->exec('DROP TRIGGER synthetic_clock_update_failure');
});

it('coalesces outer mutation nested no-op and nested mutation', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $tx = app(CharacterizationStateTransaction::class);
    $tx->run($row->id, function ($r) use ($row, $tx) {
        $r->update(['nace_code' => '01']);
        $tx->runForUser($row->user_id, fn () => null);
        $tx->run($row->id, fn ($inner) => $inner->update(['nace_code' => '02']));
    });
    expect(p5clockHeader($row)['revision'])->toBe(2);
    expect($row->fresh()->nace_code)->toBe('02');
});

it('recovers after failed nesting and ignores uncommitted ABA', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $header = p5clockHeader($row);
    $tx = app(CharacterizationStateTransaction::class);
    expect(fn () => $tx->runForUser($row->user_id, function () use ($row, $tx) {
        $tx->run($row->id, function ($r) { $r->update(['nace_code' => '01']); throw new RuntimeException('nested'); });
    }))->toThrow(RuntimeException::class, 'nested');
    expect(p5clockHeader($row))->toBe($header);
    $tx->run($row->id, function ($r) use ($row) {
        p5clockChange($row, 'TEMP');
        p5clockChange($row, 'SYNTHETIC_B');
    });
    expect(p5clockHeader($row))->toBe($header);
    p5clockChange($row, 'SYNTHETIC_C');
    expect(p5clockHeader($row)['revision'])->toBe(2);
});

it('cascades parent deletion and does not resurrect absent after callback', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    app(CharacterizationStateTransaction::class)->runForUser($row->user_id, fn ($r) => $r->delete());
    expect(p5clockHeader($row))->toBe([])->and($row->fresh())->toBeNull();
});

it('rejects before and readback SQL boundary failures atomically then recovers', function () {
    $row = p5clockRow(User::factory()->create());
    $armed = false;
    $seen = 0;
    $failAt = 1;
    DB::connection()->beforeExecuting(function ($query) use (&$armed, &$seen, &$failAt) {
        if ($armed && str_starts_with($query, 'select') && str_contains($query, 'learning_p5_source_revisions') && ++$seen === $failAt) {
            throw new RuntimeException('synthetic-clock-query-boundary');
        }
    });
    foreach ([1, 3] as $fault) {
        $seen = 0; $failAt = $fault; $armed = true;
        try {
            expect(fn () => p5clockChange($row, 'DENIED'))->toThrow(RuntimeException::class, 'synthetic-clock-query-boundary');
        } finally { $armed = false; }
        expect(p5clockHeader($row))->toBe([]);
        expect($row->fresh()->form_data['company_profile']['company_name'])->toBe('SYNTHETIC_A');
    }
    p5clockChange($row, 'SYNTHETIC_RECOVERED');
    expect(p5clockHeader($row)['revision'])->toBe(1);
});

it('allows caught nested rollback without leaking or counting inner writes', function () {
    $row = p5clockRow(User::factory()->create());
    p5clockChange($row, 'SYNTHETIC_B');
    $tx = app(CharacterizationStateTransaction::class);
    $tx->run($row->id, function ($r) use ($row, $tx) {
        $r->update(['nace_code' => '01']);
        try {
            $tx->runForUser($row->user_id, function ($inner) {
                $inner->update(['nace_code' => 'DENIED']);
                throw new RuntimeException('synthetic-nested');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('synthetic-nested');
        }
        $tx->run($row->id, fn () => null);
    });
    expect($row->fresh()->nace_code)->toBe('01')->and(p5clockHeader($row)['revision'])->toBe(2);
});

it('accepts persisted canonical decimal generation and maximum without coercion', function () {
    $row = p5clockRow(User::factory()->create());
    app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => '9007199254740991']));
    expect(p5clockHeader($row)['generation'])->toBe(9007199254740991);
    $header = p5clockHeader($row);
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => '9007199254740992'])))->toThrow(DomainException::class);
    expect(p5clockHeader($row))->toBe($header);
});
