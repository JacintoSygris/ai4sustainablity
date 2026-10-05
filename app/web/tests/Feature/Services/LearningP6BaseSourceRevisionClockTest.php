<?php

use App\Jobs\SubmitCharacterizationJob;
use App\Models\{Characterization, EsrsTopic, User};
use App\Services\{CharacterizationStateTransaction, LearningP6BaseSourceRevisionClock};
use App\Services\Contracts\CharacterizationGateway;
use Illuminate\Support\Facades\{Bus, DB, Http};

// t06e-base-synthetic-only; synthetic_only=true; promotion_allowed=false; rights deny-all.
beforeEach(function () {
    config(['services.learning_p6_base_source_clock.enabled' => true]);
    Http::preventStrayRequests();
});

it('clocks actual legacy retry reset before dispatch without changing generation', function () {
    Bus::fake();
    $row = p6baseRow('failed');
    p6baseChange($row, ['synthetic' => 'A']);
    $before = (new LearningP6BaseSourceRevisionClock)->current($row->id);
    $request = Illuminate\Http\Request::create('/synthetic-retry', 'POST');
    $request->setUserResolver(fn () => $row->user);
    $response = app(App\Http\Controllers\CharacterizationController::class)->retry($request);
    expect($response->getStatusCode())->toBe(302);
    expect($row->fresh()->result_data)->toBeNull()->and($row->fresh()->submission_generation)->toBe(1);
    $after = (new LearningP6BaseSourceRevisionClock)->current($row->id);
    expect($after['revision'])->toBe($before['revision'] + 1);
    Bus::assertDispatched(SubmitCharacterizationJob::class, function ($job) use ($row, $after) {
        return $job->characterization->id === $row->id
            && (new LearningP6BaseSourceRevisionClock)->current($row->id) === $after;
    });
});

function p6baseRow(string $status = 'draft'): Characterization
{
    return Characterization::query()->create([
        'user_id' => User::factory()->create()->id, 'status' => $status,
        'form_data' => [], 'submission_generation' => 1,
    ])->fresh();
}

function p6baseChange(Characterization $row, array $result): mixed
{
    return app(CharacterizationStateTransaction::class)->run($row->id, function ($fresh) use ($result) {
        $fresh->update(['result_data' => $result]);
        return 'original-result';
    });
}

it('does not seed absent legacy no-op or unsaved model changes', function () {
    $clock = new LearningP6BaseSourceRevisionClock;
    expect($clock->current(999))->toBeNull();
    $row = p6baseRow();
    $tx = app(CharacterizationStateTransaction::class);
    expect($tx->runForUser($row->user_id, fn () => 42))->toBe(42);
    $tx->run($row->id, function ($fresh) { $fresh->result_data = ['unsaved' => true]; return $fresh; });
    expect($clock->current($row->id))->toBeNull();
    expect($tx->runForUser(User::factory()->create()->id, fn () => 'absent'))->toBe('absent');
    expect(p6baseChange($row, ['synthetic' => 'A']))->toBe('original-result');
    expect($clock->current($row->id)['revision'])->toBe(1);
});

it('tracks committed ABA but coalesces nesting and ignores uncommitted ABA', function () {
    $row = p6baseRow(); $clock = new LearningP6BaseSourceRevisionClock;
    p6baseChange($row, ['synthetic' => 'A']); $a = $clock->current($row->id);
    p6baseChange($row, ['synthetic' => 'B']);
    p6baseChange($row, ['synthetic' => 'A']); $again = $clock->current($row->id);
    expect($again['revision'])->toBe(3)->and($again['digest'])->toBe($a['digest'])->and($again['epoch'])->toBe($a['epoch']);
    $tx = app(CharacterizationStateTransaction::class);
    $tx->runForUser($row->user_id, function () use ($row, $tx) {
        p6baseChange($row, ['synthetic' => 'B']);
        $tx->run($row->id, fn () => p6baseChange($row, ['synthetic' => 'A']));
    });
    expect($clock->current($row->id))->toBe($again);
});

it('rolls callback and invalid after state back then clears nesting scope', function () {
    $row = p6baseRow(); $tx = app(CharacterizationStateTransaction::class);
    expect(fn () => $tx->runForUser($row->user_id, function () use ($row) {
        p6baseChange($row, ['synthetic' => 'B']); throw new RuntimeException('synthetic-fault');
    }))->toThrow(RuntimeException::class, 'synthetic-fault');
    expect($row->fresh()->result_data)->toBeNull();
    expect(fn () => $tx->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update(['result_data' => '{'])))->toThrow(DomainException::class);
    expect($row->fresh()->result_data)->toBeNull();
    p6baseChange($row, ['synthetic' => 'C']);
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id)['revision'])->toBe(1);
});

it('canonicalizes maps preserves ordered leaf lists and ignores unrelated siblings and availability', function () {
    $row = p6baseRow(); $clock = new LearningP6BaseSourceRevisionClock;
    p6baseChange($row, ['b' => ['z' => 1, 'a' => 2], 'a' => ['x', 'y']]); $header = $clock->current($row->id);
    p6baseChange($row, ['a' => ['x', 'y'], 'b' => ['a' => 2, 'z' => 1]]);
    expect($clock->current($row->id))->toBe($header);
    app(CharacterizationStateTransaction::class)->run($row->id, fn ($r) => $r->update([
        'form_data' => ['company_profile' => ['synthetic' => true], 'operations' => [], 'materiality_confirmation' => [], 'datapoint_responses' => [], 'notes' => 'SYNTHETIC'],
        'status' => 'failed', 'last_error' => 'SYNTHETIC', 'retry_count' => 9,
    ]));
    // The unknown empty-array root changed to an object: that type is source metadata.
    $header = $clock->current($row->id);
    app(CharacterizationStateTransaction::class)->run($row->id, fn ($r) => $r->update(['form_data' => ['notes' => 'SYNTHETIC_B'], 'status' => 'draft']));
    expect($clock->current($row->id))->toBe($header);
    p6baseChange($row, ['a' => ['y', 'x'], 'b' => ['z' => 1, 'a' => 2]]);
    expect($clock->current($row->id)['revision'])->toBe($header['revision'] + 1);
});

it('keeps unknown JSON containers compatible with P5 and exact BASE presence', function () {
    config(['services.learning_source_clock.enabled' => true]);
    $row = p6baseRow(); $tx = app(CharacterizationStateTransaction::class); $clock = new LearningP6BaseSourceRevisionClock;
    $digests = [];
    foreach ([null, 'null', '[]', '{}', '{"esg_focus":null}', '{"esg_focus":[]}', '{"esg_focus":{}}', '{"esg_focus":{"topic_ids":null}}', '{"esg_focus":{"topic_ids":[]}}', '{"materiality_proposal_review":null}', '{"materiality_proposal_review":[]}', '{"materiality_proposal_review":{}}'] as $raw) {
        $tx->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]));
        expect((new App\Services\LearningP5Snapshot)->project($row->fresh())['values'])->toBe(['employee_count_range' => null, 'headquarters_country' => null, 'stock_listed' => null]);
        $digests[] = $clock->current($row->id)['digest'];
    }
    expect(array_unique($digests))->toHaveCount(12);
});

it('rejects malformed raw JSON before callbacks without repairing source', function (string $field, string $raw) {
    $row = p6baseRow();
    DB::table('characterizations')->where('id', $row->id)->update([$field => $raw]);
    $called = false;
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use (&$called) { $called = true; }))->toThrow(DomainException::class);
    expect($called)->toBeFalse()->and($row->fresh()->getRawOriginal($field))->toBe($raw);
})->with([
    ['result_data', '{'], ['result_data', ''], ['result_data', 'false'], ['result_data', '[null]'],
    ['result_data', '{"x":1e999}'], 'invalid-utf8-result-json' => ['result_data', '{"x":"'."\xB1".'"}'],
    ['form_data', '0'], ['form_data', '[{}]'], ['form_data', '{"esg_focus":[null]}'],
    ['form_data', '{"materiality_proposal_review":false}'], ['form_data', '{"esg_focus":{"topic_ids":{}}}'],
    ['esrs_topic_ids', '42'], ['esrs_topic_ids', '{}'],
    ['result_data', '{"x":'.str_repeat('[', 513).'0'.str_repeat(']', 513).'}'],
]);

it('rejects genuine raw counter corruption regression and exhaustion', function () {
    $row = p6baseRow(); $tx = app(CharacterizationStateTransaction::class); $clock = new LearningP6BaseSourceRevisionClock;
    foreach (['1suffix', '1.5', -1, '9007199254740992'] as $bad) {
        DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => $bad]);
        expect(fn () => p6baseChange($row, ['denied' => true]))->toThrow(DomainException::class);
    }
    DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => 1]);
    p6baseChange($row, ['synthetic' => true]);
    expect(fn () => $tx->run($row->id, fn ($r) => $r->update(['submission_generation' => 0])))->toThrow(DomainException::class);
    expect($row->fresh()->submission_generation)->toBe(1);
    DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 9007199254740991]);
    expect(fn () => p6baseChange($row, ['denied' => true]))->toThrow(DomainException::class);
    expect($row->fresh()->result_data)->toBe(['synthetic' => true]);
    expect($clock->current($row->id)['revision'])->toBe(9007199254740991);
});

it('denies header corruption late drift and out of band source changes', function () {
    $row = p6baseRow(); p6baseChange($row, ['synthetic' => true]); $clock = new LearningP6BaseSourceRevisionClock;
    $header = $clock->current($row->id); $table = DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id);
    foreach ([['revision' => '2suffix'], ['epoch' => 'bad'], ['digest' => str_repeat('A', 64)], ['generation' => 8]] as $bad) {
        $table->update($bad);
        expect(fn () => $clock->current($row->id))->toThrow(DomainException::class);
        expect(fn () => p6baseChange($row, ['denied' => true]))->toThrow(DomainException::class);
        $table->update($header);
    }
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn () => $table->update(['revision' => 9])))->toThrow(DomainException::class);
    expect($clock->current($row->id))->toBe($header);
    DB::table('characterizations')->where('id', $row->id)->update(['result_data' => '{"drift":true}']);
    expect(fn () => $clock->current($row->id))->toThrow(DomainException::class);
});

it('guards mutation transactions and disposable access and leaves flags OFF query behavior intact', function () {
    $row = p6baseRow(); $clock = new LearningP6BaseSourceRevisionClock;
    $connection = DB::connection(); $name = $connection->getDatabaseName();
    $connection->setDatabaseName('synthetic-file.sqlite');
    try { expect(fn () => $clock->current($row->id))->toThrow(DomainException::class); }
    finally { $connection->setDatabaseName($name); }
    foreach ([null, false, 1, 'true'] as $flag) {
        config(['services.learning_p6_base_source_clock.enabled' => $flag]);
        DB::enableQueryLog(); DB::flushQueryLog();
        p6baseChange($row, ['synthetic' => $flag]);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        expect(array_filter($queries, fn ($q) => str_contains($q['query'], 'learning_p6_base_source')))->toBe([]);
        expect($clock->current($row->id))->toBeNull();
    }
    config(['services.learning_p6_base_source_clock.enabled' => true]);
    app()->instance('env', 'production');
    try { expect($clock->current($row->id))->toBeNull(); }
    finally { app()->instance('env', 'testing'); }
});

it('observes actual Recorder creation and proposal review independently from P5', function () {
    Bus::fake(); config(['services.learning_source_clock.enabled' => true]);
    $user = User::factory()->create();
    $row = app(App\Services\CharacterizationRecorder::class)->save($user, ['status' => 'draft', 'esrs_topic_ids' => [777]]);
    $clock = new LearningP6BaseSourceRevisionClock;
    expect($clock->current($row->id))->toMatchArray(['revision' => 1, 'generation' => 0]);
    $p5 = (new App\Services\LearningP5SourceRevisionClock)->current($row->id);
    app(CharacterizationStateTransaction::class)->run($row->id, fn ($r) => $r->update(['status' => 'completed']));
    $request = Illuminate\Http\Request::create('/synthetic-review', 'POST', ['expected_revision' => 0, 'topic_actions' => ['777' => 'accepted']]);
    $request->setUserResolver(fn () => $user);
    $response = app(App\Http\Controllers\Api\MaterialityProposalController::class)->update($request);
    expect($response->getStatusCode())->toBe(200);
    expect($row->fresh()->form_data['materiality_proposal_review']['revision'])->toBe(1);
    expect($clock->current($row->id)['revision'])->toBe(2);
    expect((new App\Services\LearningP5SourceRevisionClock)->current($row->id))->toBe($p5);
    $saved = app(App\Services\CharacterizationRecorder::class)->save($user, ['status' => 'submitted']);
    expect($clock->current($row->id))->toMatchArray(['revision' => 3, 'generation' => 1]);
    expect($saved->fresh()->form_data)->not->toHaveKey('materiality_proposal_review');
    Bus::assertDispatched(SubmitCharacterizationJob::class);
});

it('clocks real Job completion with a closed synthetic gateway and persisted candidate IDs', function () {
    $topic = EsrsTopic::query()->create([
        'esrs_code' => 'SYNTHETIC', 'theme_es' => 'SYNTHETIC', 'theme_en' => 'SYNTHETIC',
        'subtheme_es' => 'SYNTHETIC', 'subtheme_en' => 'SYNTHETIC', 'hash' => 't06e-synthetic-topic',
    ]);
    $row = p6baseRow('submitted');
    $gateway = new class($topic->id) implements CharacterizationGateway {
        public int $calls = 0;
        public function __construct(private int $topic) {}
        public function submit(Characterization $characterization): array {
            $this->calls++;
            return ['status' => 'completed', 'candidate_topics' => [['ar16_topic_id' => $this->topic]],
                'request' => ['synthetic' => true], 'raw_prediction' => ['synthetic' => 0.5]];
        }
    };
    (new SubmitCharacterizationJob($row))->handle($gateway);
    $fresh = $row->fresh();
    expect($gateway->calls)->toBe(1)->and($fresh->status)->toBe('completed');
    expect($fresh->esrs_topic_ids)->toBe([$topic->id]);
    expect($fresh->form_data['esg_focus']['topic_ids'])->toBe([$topic->id]);
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toMatchArray(['revision' => 1, 'generation' => 1]);
    expect(fn () => (new App\Services\LearningP6Snapshot)->project($fresh))->toThrow(InvalidArgumentException::class);
});

it('rolls metadata insert readback corruption and exact CAS failure back', function () {
    $row = p6baseRow(); $pdo = DB::connection()->getPdo();
    $pdo->exec("CREATE TRIGGER t06e_insert_fault BEFORE INSERT ON learning_p6_base_source_revisions BEGIN SELECT RAISE(ABORT, 'synthetic-fault'); END");
    expect(fn () => p6baseChange($row, ['denied' => true]))->toThrow(Illuminate\Database\QueryException::class);
    expect($row->fresh()->result_data)->toBeNull();
    $pdo->exec('DROP TRIGGER t06e_insert_fault');
    $pdo->exec("CREATE TRIGGER t06e_corrupt AFTER INSERT ON learning_p6_base_source_revisions BEGIN UPDATE learning_p6_base_source_revisions SET digest = 'corrupt' WHERE characterization_id = NEW.characterization_id; END");
    expect(fn () => p6baseChange($row, ['denied' => true]))->toThrow(DomainException::class);
    expect($row->fresh()->result_data)->toBeNull()->and((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBeNull();
    $pdo->exec('DROP TRIGGER t06e_corrupt');
    p6baseChange($row, ['synthetic' => true]); $header = (new LearningP6BaseSourceRevisionClock)->current($row->id);
    $pdo->exec('CREATE TRIGGER t06e_cas_ignore BEFORE UPDATE ON learning_p6_base_source_revisions BEGIN SELECT RAISE(IGNORE); END');
    expect(fn () => p6baseChange($row, ['denied' => true]))->toThrow(DomainException::class, 'learning_p6_base_clock.conflict');
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBe($header);
    expect($row->fresh()->result_data)->toBe(['synthetic' => true]);
    $pdo->exec('DROP TRIGGER t06e_cas_ignore');
});

it('does not treat header loss as a counter or recreate history on no-op', function () {
    $row = p6baseRow(); $clock = new LearningP6BaseSourceRevisionClock;
    p6baseChange($row, ['synthetic' => 'A']); $old = $clock->current($row->id);
    // Privileged synthetic metadata loss is outside any WORM guarantee.
    DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id)->delete();
    expect($clock->current($row->id))->toBeNull();
    p6baseChange($row, ['synthetic' => 'A']);
    expect($clock->current($row->id))->toBeNull();
    p6baseChange($row, ['synthetic' => 'B']); $new = $clock->current($row->id);
    expect($new['revision'])->toBe(1)->and($new['epoch'])->not->toBe($old['epoch']);
});

it('requires an active transaction before invoking a mutation callback', function () {
    $connection = DB::connection(); $level = $connection->transactionLevel();
    // RefreshDatabase holds the test transaction; leave it temporarily, with no source writes.
    while ($connection->transactionLevel() > 0) { $connection->rollBack(); }
    try {
        $called = false;
        expect(fn () => (new LearningP6BaseSourceRevisionClock)->observe(999, fn () => null, function () use (&$called) { $called = true; }))->toThrow(DomainException::class, 'learning_p6_base_clock.transaction_required');
        expect($called)->toBeFalse();
    } finally {
        for ($i = 0; $i < $level; $i++) { $connection->beginTransaction(); }
    }
});

it('rejects non failed retry and rolls reset failure back without dispatch', function () {
    Bus::fake(); $row = p6baseRow('completed');
    p6baseChange($row, ['synthetic' => true]); $clock = new LearningP6BaseSourceRevisionClock; $header = $clock->current($row->id);
    $request = Illuminate\Http\Request::create('/synthetic-retry', 'POST');
    $request->setUserResolver(fn () => $row->user);
    app(App\Http\Controllers\CharacterizationController::class)->retry($request);
    Bus::assertNotDispatched(SubmitCharacterizationJob::class); expect($clock->current($row->id))->toBe($header);
    app(CharacterizationStateTransaction::class)->run($row->id, fn ($r) => $r->update(['status' => 'failed']));
    $pdo = DB::connection()->getPdo();
    $pdo->exec("CREATE TRIGGER t06e_retry_fault BEFORE UPDATE ON learning_p6_base_source_revisions BEGIN SELECT RAISE(ABORT, 'synthetic-retry-fault'); END");
    try { expect(fn () => app(App\Http\Controllers\CharacterizationController::class)->retry($request))->toThrow(Illuminate\Database\QueryException::class); }
    finally { $pdo->exec('DROP TRIGGER t06e_retry_fault'); }
    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
    expect($row->fresh()->status)->toBe('failed')->and($row->fresh()->result_data)->toBe(['synthetic' => true]);
    expect($clock->current($row->id))->toBe($header);
});

// R1 late-finalization regression: real owners, disposable metadata-trigger faults.
it('R1 rejects late cross owner writes and restores exact source and both headers', function (string $wrapper, string $variant, bool $nested) {
    config(['services.learning_source_clock.enabled' => true]);
    $row = p6baseRow(); $tx = app(CharacterizationStateTransaction::class);
    $p5 = new App\Services\LearningP5SourceRevisionClock;
    $p6 = new LearningP6BaseSourceRevisionClock;
    $tx->run($row->id, fn ($r) => $r->update(['nace_code' => 'SYNTHETIC_A', 'result_data' => ['synthetic' => 'A']]));
    if ($variant === 'absent') {
        DB::table('learning_p6_base_source_revisions')->where('characterization_id', $row->id)->delete();
    }
    $source = $row->fresh()->getRawOriginal();
    $h5 = $p5->current($row->id); $h6 = $p6->current($row->id);
    $effect = $variant === 'header'
        ? 'UPDATE learning_p6_base_source_revisions SET revision=99 WHERE characterization_id=NEW.characterization_id;'
        : 'UPDATE characterizations SET result_data=\'{"synthetic":"C"}\' WHERE id=NEW.characterization_id;';
    DB::unprepared('CREATE TRIGGER r1_late AFTER UPDATE ON learning_p5_source_revisions BEGIN '.$effect.' END');
    $change = function ($r) use ($variant) {
        $values = ['nace_code' => 'SYNTHETIC_B'];
        if ($variant !== 'absent') { $values['result_data'] = ['synthetic' => 'B']; }
        $r->update($values);
        return 'preserved-result';
    };
    $operation = $nested ? function ($r) use ($tx, $row, $change) {
        $result = $tx->runForUser($row->user_id, $change);
        // Inner wrappers must leave finalization to the active outer owner.
        $r->refresh()->update(['nace_code' => 'SYNTHETIC_FINAL']);
        return $result;
    } : $change;
    $invoke = fn () => $wrapper === 'run' ? $tx->run($row->id, $operation) : $tx->runForUser($row->user_id, $operation);
    try { expect($invoke)->toThrow(DomainException::class); }
    finally { DB::unprepared('DROP TRIGGER r1_late'); }
    expect($row->fresh()->getRawOriginal())->toBe($source);
    expect($p5->current($row->id))->toBe($h5)->and($p6->current($row->id))->toBe($h6);
    expect($invoke())->toBe('preserved-result');
    expect($p5->current($row->id)['revision'])->toBe($h5['revision'] + 1);
    if ($variant === 'absent') { expect($p6->current($row->id))->toBeNull(); }
    else { expect($p6->current($row->id)['revision'])->toBe($h6['revision'] + 1); }
})->with([
    ['run', 'present', false], ['runForUser', 'present', false],
    ['run', 'header', false], ['runForUser', 'header', false],
    ['run', 'absent', false], ['runForUser', 'absent', false],
    ['run', 'present', true], ['runForUser', 'present', true],
    ['run', 'header', true], ['runForUser', 'header', true],
    ['run', 'absent', true], ['runForUser', 'absent', true],
]);
it('R1 active mode changes cannot bypass joint validation', function (string $wrapper, string $flag) {
    config(['services.learning_source_clock.enabled' => true]);
    $row = p6baseRow(); $tx = app(CharacterizationStateTransaction::class);
    $tx->run($row->id, fn ($r) => $r->update(['nace_code' => 'SYNTHETIC_A', 'result_data' => ['synthetic' => 'A']]));
    $source = $row->fresh()->getRawOriginal();
    $p5 = new App\Services\LearningP5SourceRevisionClock; $p6 = new LearningP6BaseSourceRevisionClock;
    $h5 = $p5->current($row->id); $h6 = $p6->current($row->id);
    $operation = function ($r) use ($flag, $tx, $row) {
        $r->update(['nace_code' => 'SYNTHETIC_B', 'result_data' => ['synthetic' => 'B']]);
        config([$flag => false]);
        $tx->run($row->id, fn () => 'nested');
    };
    try {
        expect(fn () => $wrapper === 'run' ? $tx->run($row->id, $operation) : $tx->runForUser($row->user_id, $operation))->toThrow(DomainException::class);
    } finally { config([$flag => true]); }
    expect($row->fresh()->getRawOriginal())->toBe($source);
    expect($p5->current($row->id))->toBe($h5)->and($p6->current($row->id))->toBe($h6);
    expect($tx->run($row->id, fn () => 'recovered'))->toBe('recovered');
})->with([
    ['run', 'services.learning_source_clock.enabled'], ['runForUser', 'services.learning_source_clock.enabled'],
    ['run', 'services.learning_p6_base_source_clock.enabled'], ['runForUser', 'services.learning_p6_base_source_clock.enabled'],
]);

it('R1 legitimate joint no op leaves an absent P6 header absent', function (string $wrapper) {
    config(['services.learning_source_clock.enabled' => true]);
    $row = p6baseRow(); $tx = app(CharacterizationStateTransaction::class);
    $operation = fn () => 'no-op';
    expect($wrapper === 'run' ? $tx->run($row->id, $operation) : $tx->runForUser($row->user_id, $operation))->toBe('no-op');
    expect((new LearningP6BaseSourceRevisionClock)->current($row->id))->toBeNull();
    expect(DB::table('learning_p6_base_source_revisions')->count())->toBe(0);
})->with(['run', 'runForUser']);