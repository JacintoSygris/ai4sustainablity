<?php

use App\Models\LearningAuthorizationRecord;
use App\Models\LearningAuthorizationState;
use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use App\Models\User;
use App\Services\LearningAuthorization;
use App\Services\LearningAuthorizationAuthority;
use App\Services\LearningAuthorizationLedger;
use Illuminate\Support\Facades\DB;

// This issuer exists exclusively in the synthetic test namespace.
function t05bContext(): array
{
    $user = User::factory()->create();
    $group = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256', 't05b-synthetic')]);
    $member = LearningCompanyMembership::query()->forceCreate([
        'learning_company_group_id' => $group->id, 'user_id' => $user->id,
        'subject_type' => 'account', 'subject_identifier' => (string) $user->id,
        'evidence_digest' => hash('sha256', 't05b-membership'), 'evidence_type' => 'synthetic-only',
        'verification_status' => 'verified', 'verified_at' => now(),
    ]);
    $fake = new class implements LearningAuthorizationAuthority {
        public int $calls = 0;
        public bool $denied = false;
        public ?Closure $hook = null;
        public string $policy = 'synthetic-v1';
        public function references(string $purpose): ?array
        {
            return $this->denied ? null : [
                'issuer_ref' => 'synthetic:issuer', 'issuer_version' => 'synthetic-v1', 'issuer_digest' => hash('sha256', 'issuer'),
                'capability_ref' => 'synthetic:capability', 'capability_version' => 'synthetic-v1', 'capability_digest' => hash('sha256', 'capability'),
                'policy_version' => $this->policy, 'policy_digest' => hash('sha256', $this->policy),
            ];
        }
        public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array
        {
            $this->calls++;
            $refs = $this->references($purpose);
            if ($this->hook) { ($this->hook)($this); }
            return $refs === null ? null : array_merge($refs, [
                'actor_id' => $actor->id, 'group_id' => $membership->learning_company_group_id,
                'membership_id' => $membership->id, 'purpose' => $purpose, 'state' => 'available',
                'provenance' => 'synthetic-only', 'promotion_allowed' => false,
            ]);
        }
    };
    return [$user, $group, $member, $fake, new LearningAuthorizationLedger(new LearningAuthorization($fake), app(App\Services\CharacterizationStateTransaction::class))];
}
function t05bCommand(string $name): string { return hash('sha256', 'synthetic-command:'.$name); }
function t05bRows(): array { return [LearningAuthorizationRecord::count(), LearningAuthorizationState::count()]; }

function t05bPersistedBytes(): array
{
    return [DB::table('learning_authorization_records')->orderBy('id')->get()->toJson(),
        DB::table('learning_authorization_states')->orderBy('id')->get()->toJson()];
}

it('R2 reads current authority when replaying an old revoke without changing persisted bytes', function (string $authority) {
    [$u, $g, $m, $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    $receipt = $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2'));
    $fake->policy = 'synthetic-v2';
    $l->grant($u->id, $g->id, 'synthetic:learning', 2, t05bCommand('r2-g3'));
    if ($authority === 'denied') { $fake->denied = true; }
    if ($authority === 'policy') { $fake->policy = 'synthetic-v1'; }
    if ($authority === 'membership') { $m->forceFill(['revoked_at' => now(), 'verification_status' => 'revoked'])->save(); }
    $before = t05bPersistedBytes();
    $current = $l->current($u->id, $g->id, 'synthetic:learning');
    $replay = $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2'));
    expect($replay['status'])->toBe($authority === 'valid' ? 'granted' : 'denied')
        ->and($replay['generation'])->toBe(3)->and($replay['stored_status'])->toBe('granted')
        ->and($replay['receipt_generation'])->toBe(2)->and($replay['receipt_digest'])->toBe($receipt['receipt_digest'])
        ->and($replay['replayed'])->toBeTrue()->and($replay['promotion_allowed'])->toBeFalse();
    unset($replay['receipt_generation'], $replay['receipt_digest']);
    $replay['replayed'] = false;
    expect($replay)->toBe($current)->and(t05bRows())->toBe([3, 1])->and(t05bPersistedBytes())->toBe($before);
})->with(['valid', 'denied', 'policy', 'membership']);

it('R2 binds historical grant replay to the latest grant policy witness', function (string $policy) {
    [$u, $g, , $fake, $l] = t05bContext();
    $receipt = $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2'));
    $fake->policy = 'synthetic-v2';
    $l->grant($u->id, $g->id, 'synthetic:learning', 2, t05bCommand('r2-g3'));
    $fake->policy = $policy;
    $before = t05bPersistedBytes();
    $current = $l->current($u->id, $g->id, 'synthetic:learning');
    $replay = $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    expect($replay['status'])->toBe($policy === 'synthetic-v2' ? 'granted' : 'denied')
        ->and($replay['status'])->toBe($current['status'])->and($replay['generation'])->toBe(3)
        ->and($replay['receipt_generation'])->toBe(1)->and($replay['receipt_digest'])->toBe($receipt['receipt_digest'])
        ->and(t05bRows())->toBe([3, 1])->and(t05bPersistedBytes())->toBe($before);
})->with(['synthetic-v1', 'synthetic-v2']);

it('R2 reads and replays terminal states without consulting an unavailable issuer', function (string $operation, string $status) {
    [$u, $g, , $fake, $l] = t05bContext();
    $grant = $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    $terminal = $l->$operation($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-terminal'));
    $fake->hook = fn () => throw new RuntimeException('synthetic-issuer-unavailable');
    $calls = $fake->calls;
    $before = t05bPersistedBytes();
    expect($l->current($u->id, $g->id, 'synthetic:learning')['status'])->toBe($status);
    foreach ([['grant', 0, 'r2-g1', $grant], [$operation, 1, 'r2-terminal', $terminal]] as [$op, $gen, $cmd, $receipt]) {
        $replay = $l->$op($u->id, $g->id, 'synthetic:learning', $gen, t05bCommand($cmd));
        expect($replay['status'])->toBe($status)->and($replay['generation'])->toBe(2)
            ->and($replay['receipt_generation'])->toBe($receipt['receipt_generation'])
            ->and($replay['receipt_digest'])->toBe($receipt['receipt_digest'])->and($replay['replayed'])->toBeTrue();
    }
    expect($fake->calls)->toBe($calls)->and(t05bRows())->toBe([2, 1])->and(t05bPersistedBytes())->toBe($before);
})->with([['revoke', 'revoked'], ['tombstone', 'deleted']]);

it('R2 allows new withdrawals with unavailable issuer and optional membership loss', function (string $operation, bool $lost) {
    [$u, $g, $m, $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    if ($lost) { $m->forceFill(['revoked_at' => now(), 'verification_status' => 'revoked'])->save(); }
    $fake->hook = fn () => throw new RuntimeException('synthetic-issuer-unavailable');
    $calls = $fake->calls;
    $result = $l->$operation($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-withdraw'));
    expect($result['status'])->toBe($operation === 'revoke' ? 'revoked' : 'deleted')
        ->and($result['generation'])->toBe(2)->and($fake->calls)->toBe($calls)->and(t05bRows())->toBe([2, 1]);
})->with([['revoke', false], ['revoke', true], ['tombstone', false], ['tombstone', true]]);

it('R2 fails closed and rolls back unexpected projection drift during fresh authority read', function () {
    [$u, $g, , $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2'));
    $l->grant($u->id, $g->id, 'synthetic:learning', 2, t05bCommand('r2-g3'));
    $before = t05bPersistedBytes();
    $fake->hook = fn () => DB::table('learning_authorization_states')->update(['generation' => 4]);
    expect(fn () => $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2')))
        ->toThrow(DomainException::class, 'learning_ledger.incoherent');
    expect(t05bRows())->toBe([3, 1])->and(t05bPersistedBytes())->toBe($before);
});

it('R2 rejects conflicting old revoke intent before authority lookup or receipt disclosure', function (string $change) {
    [$u, $g, , $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2'));
    $l->grant($u->id, $g->id, 'synthetic:learning', 2, t05bCommand('r2-g3'));
    $actor = $change === 'actor' ? User::factory()->create()->id : $u->id;
    $group = $change === 'group' ? $g->id + 1 : $g->id;
    $purpose = $change === 'purpose' ? 'synthetic:other' : 'synthetic:learning';
    $operation = $change === 'operation' ? 'grant' : 'revoke';
    $generation = $change === 'generation' ? 3 : 1;
    $fake->hook = fn () => throw new RuntimeException('synthetic-must-not-consult');
    $calls = $fake->calls;
    $before = t05bPersistedBytes();
    expect(fn () => $l->$operation($actor, $group, $purpose, $generation, t05bCommand('r2-r2')))
        ->toThrow(DomainException::class, 'learning_ledger.command_conflict');
    expect($fake->calls)->toBe($calls)->and(t05bRows())->toBe([3, 1])->and(t05bPersistedBytes())->toBe($before);
})->with(['actor', 'group', 'purpose', 'operation', 'generation']);

it('R2 propagates unavailable current authority for an active grant without writing on current or replay', function () {
    [$u, $g, , $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r2-g1'));
    $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2'));
    $l->grant($u->id, $g->id, 'synthetic:learning', 2, t05bCommand('r2-g3'));
    $fake->hook = fn () => throw new RuntimeException('synthetic-issuer-unavailable');
    $before = t05bPersistedBytes();
    expect(fn () => $l->current($u->id, $g->id, 'synthetic:learning'))
        ->toThrow(RuntimeException::class, 'learning_authorization.authority_unavailable');
    expect(fn () => $l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r2-r2')))
        ->toThrow(RuntimeException::class, 'learning_authorization.authority_unavailable');
    expect(t05bRows())->toBe([3, 1])->and(t05bPersistedBytes())->toBe($before);
});

it('denies actual application binding without creating a header or event', function () {
    [$u, $g] = t05bContext();
    expect(app(LearningAuthorizationLedger::class)->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('default'))['status'])->toBe('denied');
    expect(t05bRows())->toBe([0, 0]);
});

it('persists bound synthetic evidence and exact replay without advancing current authority', function () {
    [$u, $g, , $fake, $ledger] = t05bContext();
    $a = $ledger->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('grant'));
    expect($a['status'])->toBe('granted')->and($a['generation'])->toBe(1)->and($a['promotion_allowed'])->toBeFalse();
    expect($ledger->current($u->id, $g->id, 'synthetic:learning')['status'])->toBe('granted');
    $replay = $ledger->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('grant'));
    expect($replay['replayed'])->toBeTrue()->and($replay['receipt_generation'])->toBe(1)->and(t05bRows())->toBe([1, 1]);
    $fake->denied = true;
    expect($ledger->current($u->id, $g->id, 'synthetic:learning')['status'])->toBe('denied');
});

it('rejects reused commands with different intent or context without leaking receipt', function (string $change) {
    [$u, $g, , , $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('same'));
    $actor = $change === 'actor' ? User::factory()->create()->id : $u->id;
    $group = $change === 'group' ? $g->id + 1 : $g->id;
    $purpose = $change === 'purpose' ? 'synthetic:other' : 'synthetic:learning';
    $op = $change === 'operation' ? 'revoke' : 'grant';
    $gen = $change === 'generation' ? 1 : 0;
    expect(fn () => $l->$op($actor, $group, $purpose, $gen, t05bCommand('same')))->toThrow(DomainException::class, 'learning_ledger.command_conflict');
    expect(t05bRows())->toBe([1, 1]);
})->with(['actor', 'group', 'purpose', 'operation', 'generation']);

it('requires exact primitive tokens and generations without coercion', function ($generation, $command, $actor, $purpose) {
    [$u, $g, , , $l] = t05bContext();
    expect(fn () => $l->grant($actor ?? $u->id, $g->id, $purpose, $generation, $command))->toThrow(InvalidArgumentException::class);
    expect(t05bRows())->toBe([0, 0]);
})->with([
    ['0', t05bCommand('a'), null, 'synthetic:learning'], [false, t05bCommand('a'), null, 'synthetic:learning'],
    [0, strtoupper(t05bCommand('a')), null, 'synthetic:learning'], [0, ' '.t05bCommand('a'), null, 'synthetic:learning'],
    [0, t05bCommand('a'), '1', 'synthetic:learning'], [0, t05bCommand('a'), null, ' synthetic:learning'],
]);

it('rejects stale CAS and denies absent or unrelated subject', function () {
    [$u, $g, , , $l] = t05bContext();
    expect($l->current($u->id, $g->id, 'synthetic:learning')['status'])->toBe('denied');
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
    expect(fn () => $l->revoke($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('r')))->toThrow(DomainException::class, 'learning_ledger.stale_generation');
    $other = User::factory()->create();
    expect($l->revoke($other->id, $g->id, 'synthetic:learning', 0, t05bCommand('x'))['status'])->toBe('denied');
    expect($l->current($u->id, $g->id + 1, 'synthetic:learning')['status'])->toBe('denied')->and(t05bRows())->toBe([1, 1]);
});

it('withdraws after issuer and membership loss and late replay stays revoked', function () {
    [$u, $g, $m, $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
    $m->forceFill(['revoked_at' => now(), 'verification_status' => 'revoked'])->save();
    $fake->denied = true;
    expect($l->revoke($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('r'))['status'])->toBe('revoked');
    $r = $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
    expect($r['status'])->toBe('revoked')->and($r['generation'])->toBe(2)->and($r['receipt_generation'])->toBe(1)->and(t05bRows())->toBe([2, 1]);
});

it('keeps terminal tombstone through policy replacement replay and synthetic account deletion', function () {
    [$u, $g, , $fake, $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
    $l->tombstone($u->id, $g->id, 'synthetic:learning', 1, t05bCommand('d'));
    $fake->policy = 'synthetic-v2';
    expect($l->grant($u->id, $g->id, 'synthetic:learning', 2, t05bCommand('late'))['status'])->toBe('deleted');
    expect($l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'))['status'])->toBe('deleted');
    $id = $u->id;
    $u->delete();
    expect(LearningAuthorizationState::first()->user_id)->toBeNull()->and(t05bRows())->toBe([2, 1]);
    expect($l->grant($id, $g->id, 'synthetic:learning', 2, t05bCommand('after'))['status'])->toBe('denied');
});

it('rejects fresh policy or membership drift before commit with rollback', function (string $drift) {
    [$u, $g, $m, $fake, $l] = t05bContext();
    $fake->hook = function ($f) use ($drift, $m) {
        if ($f->calls === 2) {
            if ($drift === 'policy') { $f->policy = 'synthetic-v2'; }
            else { DB::table('learning_company_memberships')->where('id', $m->id)->update(['revoked_at' => now()]); }
        }
    };
    expect(fn () => $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g')))->toThrow(DomainException::class, 'learning_ledger.witness_changed');
    expect(t05bRows())->toBe([0, 0])->and($m->fresh()->revoked_at)->toBeNull();
})->with(['policy', 'membership']);

it('preserves original failure after event insert and rolls back both tables', function () {
    [$u, $g, , , $l] = t05bContext();
    $error = new LogicException('synthetic-injected-after-event');
    LearningAuthorizationRecord::created(fn () => throw $error);
    try {
        $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
        $this->fail('expected injected failure');
    } catch (LogicException $caught) { expect($caught)->toBe($error); }
    finally { LearningAuthorizationRecord::flushEventListeners(); }
    expect(t05bRows())->toBe([0, 0]);
});

it('rejects ordinary immutable event mutations', function (string $operation) {
    [$u, $g, , , $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
    $r = LearningAuthorizationRecord::first();
    expect(fn () => $operation === 'delete' ? $r->delete() : $r->forceFill(['payload_text' => '{}'])->save())->toThrow(LogicException::class);
    expect($l->current($u->id, $g->id, 'synthetic:learning')['status'])->toBe('granted');
})->with(['update', 'delete']);

it('fails closed on forged projection or event history', function (string $target) {
    [$u, $g, , , $l] = t05bContext();
    $l->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('g'));
    if ($target === 'header') { DB::table('learning_authorization_states')->update(['generation' => 4]); }
    else { DB::table('learning_authorization_records')->update(['payload_text' => '{}']); }
    expect(fn () => $l->current($u->id, $g->id, 'synthetic:learning'))->toThrow(DomainException::class, 'learning_ledger.incoherent');
})->with(['header', 'event']);

it('preserves persisted event bytes across the immutable mutation matrix', function (string $dispatch, string $method) {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    [$u, $g, , , $ledger] = t05bContext();
    $grant = $ledger->grant($u->id, $g->id, 'synthetic:learning', 0, t05bCommand('matrix'));
    expect($grant['status'])->toBe('granted')->and($grant['promotion_allowed'])->toBeFalse();
    $record = LearningAuthorizationRecord::query()->firstOrFail();
    $before = (array) DB::table('learning_authorization_records')->where('id', $record->id)->first();
    $count = DB::table('learning_authorization_records')->count();
    expect($before['provenance'])->toBe('synthetic-only')->and((bool) $before['promotion_allowed'])->toBeFalse();
    $target = match ($dispatch) {
        'model' => $record,
        'new' => new LearningAuthorizationRecord,
        'static' => LearningAuthorizationRecord::class,
        default => LearningAuthorizationRecord::query()->whereKey($record->id),
    };
    $instanceBefore = is_string($target) ? null : ($target instanceof LearningAuthorizationRecord ? $target->getAttributes() : null);
    $args = match (strtolower($method)) {
        'increment', 'decrement', 'incrementquietly', 'decrementquietly' => ['generation', 1, ['payload_text' => '{}']],
        'incrementeach', 'decrementeach' => [['generation' => 1], ['payload_text' => '{}']],
        'touch' => ['created_at'],
        'update', 'updatefrom' => [['payload_text' => '{}']],
        'updateorinsert' => [['id' => $record->id], ['payload_text' => '{}']],
        'upsert' => [[array_merge($before, ['payload_text' => '{}'])], ['id'], ['payload_text']],
        default => [],
    };
    // Save variants need a dirty attribute to exercise performUpdate.
    if (in_array($method, ['save', 'saveQuietly'], true)) {
        $target->forceFill(['payload_text' => '{}']);
    }
    $caught = null;
    try {
        if (is_string($target)) { $target::$method(...$args); }
        else { $target->$method(...$args); }
    } catch (Throwable $error) { $caught = $error; }
    // Check storage even on RED, rather than stopping at the missing exception.
    expect((array) DB::table('learning_authorization_records')->where('id', $record->id)->first())->toBe($before);
    expect(DB::table('learning_authorization_records')->count())->toBe($count);
    if (str_contains(strtolower($method), 'crement') && $instanceBefore !== null) {
        expect($target->getAttributes())->toBe($instanceBefore);
    }
    expect($caught)->toBeInstanceOf(LogicException::class);
    expect($caught->getMessage())->toBe('learning_ledger.event_immutable');
    $current = $ledger->current($u->id, $g->id, 'synthetic:learning');
    expect($current['status'])->toBe('granted')->and($current['generation'])->toBe(1)->and($current['promotion_allowed'])->toBeFalse();
    expect(LearningAuthorizationRecord::query()->whereKey($record->id)->firstOrFail()->getRawOriginal())->toBe($before);
})->with([
    'model increment' => ['model', 'increment'],
    'model decrement' => ['model', 'decrement'],
    'model increment quiet' => ['model', 'incrementQuietly'],
    'model decrement quiet' => ['model', 'decrementQuietly'],
    'new increment' => ['new', 'increment'],
    'new decrement' => ['new', 'decrement'],
    'new increment quiet' => ['new', 'incrementQuietly'],
    'new decrement quiet' => ['new', 'decrementQuietly'],
    'static increment' => ['static', 'increment'],
    'static decrement' => ['static', 'decrement'],
    'static increment quiet' => ['static', 'incrementQuietly'],
    'static decrement quiet' => ['static', 'decrementQuietly'],
    'model save' => ['model', 'save'],
    'model save quiet' => ['model', 'saveQuietly'],
    'model delete' => ['model', 'delete'],
    'model delete quiet' => ['model', 'deleteQuietly'],
    'model force delete' => ['model', 'forceDelete'],
    'builder increment' => ['builder', 'increment'],
    'builder decrement' => ['builder', 'decrement'],
    'builder increment each' => ['builder', 'incrementEach'],
    'builder decrement each' => ['builder', 'decrementEach'],
    'builder explicit touch' => ['builder', 'touch'],
    'builder matching update or insert' => ['builder', 'updateOrInsert'],
    'builder truncate' => ['builder', 'truncate'],
    'builder update' => ['builder', 'update'],
    'builder delete' => ['builder', 'delete'],
    'builder force delete' => ['builder', 'forceDelete'],
    'builder upsert' => ['builder', 'upsert'],
    'builder update from' => ['builder', 'updateFrom'],
    'builder mixed case forwarded' => ['builder', 'InCrEmEnTeAcH'],
    'static matching update or insert' => ['static', 'updateOrInsert'],
    'new truncate' => ['new', 'truncate'],
]);
