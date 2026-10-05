<?php

use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use App\Models\User;
use App\Services\DenyLearningAuthorizationAuthority;
use App\Services\LearningAuthorization;
use App\Services\LearningAuthorizationAuthority;
use Illuminate\Support\Facades\DB;

// Every authority/policy/capability here is synthetic-only and non-promotable.
function t05aContext(): array
{
    $actor = User::factory()->create(['name' => 'SYNTHETIC_ACTOR', 'email' => 'synthetic@example.invalid']);
    $group = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256', 'synthetic-group')]);
    $membership = LearningCompanyMembership::query()->forceCreate([
        'learning_company_group_id' => $group->id, 'user_id' => $actor->id,
        'subject_type' => 'account', 'subject_identifier' => (string) $actor->id,
        'evidence_digest' => hash('sha256', 'synthetic-membership'), 'evidence_type' => 'synthetic-only',
        'verification_status' => 'verified', 'verified_at' => now(), 'revoked_at' => null,
    ]);
    $case = DB::table('learning_cases')->insertGetId([
        'learning_company_group_id' => $group->id, 'case_id' => 'synthetic-case',
        'period_key' => 'synthetic-period', 'perimeter_key' => 'synthetic-perimeter',
        'revision_tuple_digest' => hash('sha256', 'revision'), 'identity_digest' => hash('sha256', 'identity'),
        'case_hash' => hash('sha256', 'case'),
        'payload_text' => '{"provenance":{"source_kind":"synthetic"},"rights":{"state":"granted","policy_status":"approved"}}',
    ]);
    DB::table('learning_case_states')->insert(['learning_case_id' => $case, 'status' => 'stored', 'state_version' => 0]);

    return [$actor, $group, $membership];
}

function t05aTables(): array
{
    return collect(['users', 'characterizations', 'learning_company_groups', 'learning_company_memberships', 'learning_cases', 'learning_case_states'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

function t05aReferences(): array
{
    return [
        'issuer_ref' => 'synthetic:issuer', 'issuer_version' => 'synthetic-v1', 'issuer_digest' => hash('sha256', 'synthetic-issuer'),
        'capability_ref' => 'synthetic:capability', 'capability_version' => 'synthetic-v1', 'capability_digest' => hash('sha256', 'synthetic-capability'),
        'policy_version' => 'synthetic-policy-v1', 'policy_digest' => hash('sha256', 'synthetic-policy'),
    ];
}

function t05aFake(array $changes = [], bool $absent = false, ?Throwable $error = null, ?array $references = [], ?Closure $duringResolve = null): LearningAuthorizationAuthority
{
    return new class($changes, $absent, $error, $references, $duringResolve) implements LearningAuthorizationAuthority
    {
        public int $calls = 0;
        public function __construct(private array $changes, private bool $absent, private ?Throwable $error, private ?array $references, private ?Closure $duringResolve) {}
        public function references(string $purpose): ?array { return $this->references === null ? null : array_replace(t05aReferences(), $this->references); }
        public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array
        {
            $this->calls++;
            if ($this->error) { throw $this->error; }
            if ($this->absent) { return null; }
            if ($this->duringResolve) { ($this->duringResolve)(); }

            return array_replace(t05aReferences(), [
                'actor_id' => $actor->id, 'group_id' => $membership->learning_company_group_id,
                'membership_id' => $membership->id, 'purpose' => $purpose, 'state' => 'available',
                'provenance' => 'synthetic-only', 'promotion_allowed' => false,
            ], $this->changes);
        }
    };
}

it('binds production to deny-all despite verified membership and fabricated approved granted claims', function () {
    [$actor, $group] = t05aContext();
    $before = t05aTables();
    $actor->setAttribute('rights', ['state' => 'granted', 'policy_status' => 'approved']);
    $actor->setAttribute('company_name', 'synthetic:issuer');
    expect(app(LearningAuthorizationAuthority::class))->toBeInstanceOf(DenyLearningAuthorizationAuthority::class)
        ->and(app(LearningAuthorization::class)->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBeNull()
        ->and(t05aTables())->toBe($before)
        ->and(DB::table('learning_case_states')->value('status'))->toBe('stored');
});

it('resolves only the explicit synthetic trusted fake without changing any stored state', function () {
    [$actor, $group, $membership] = t05aContext();
    $before = t05aTables();
    $fake = t05aFake();
    $guard = new LearningAuthorization($fake);
    $expected = array_merge(t05aReferences(), [
        'actor_id' => $actor->id, 'group_id' => $group->id, 'membership_id' => $membership->id,
        'purpose' => 'synthetic:learning', 'state' => 'available', 'provenance' => 'synthetic-only', 'promotion_allowed' => false,
    ]);
    expect($guard->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBe($expected)
        ->and($fake->calls)->toBe(1)->and(t05aTables())->toBe($before)
        ->and(app(LearningAuthorizationAuthority::class))->toBeInstanceOf(DenyLearningAuthorizationAuthority::class);
});

it('rejects current invalid membership or account context before calling the issuer', function (array $change, string $target) {
    [$actor, $group, $loadedMembership] = t05aContext();
    if ($target === 'account') { DB::table('users')->where('id', $actor->id)->update($change); }
    else { DB::table('learning_company_memberships')->where('id', $loadedMembership->id)->update($change); }
    $before = t05aTables();
    $fake = t05aFake();
    expect((new LearningAuthorization($fake))->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBeNull()
        ->and($fake->calls)->toBe(0)->and(t05aTables())->toBe($before)
        ->and($loadedMembership->verification_status)->toBe('verified');
})->with([
    'unresolved' => [['verification_status' => 'unresolved'], 'membership'],
    'revoked' => [['verification_status' => 'revoked'], 'membership'],
    'revoked timestamp' => [['revoked_at' => '2026-10-02 00:00:00'], 'membership'],
    'missing verification' => [['verified_at' => null], 'membership'],
    'wrong subject' => [['subject_identifier' => '999999'], 'membership'],
    'wrong account' => [['user_id' => null], 'membership'],
    'source relation' => [['subject_type' => 'source'], 'membership'],
    'unverified email' => [['email_verified_at' => null], 'account'],
]);

it('rejects another group and nonexistent actor regardless of display names', function () {
    [$actor, $group] = t05aContext();
    $other = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256', 'other-synthetic-group')]);
    $before = t05aTables();
    $fake = t05aFake();
    $guard = new LearningAuthorization($fake);
    expect($guard->resolveForAccount($actor->id, $other->id, 'synthetic:learning'))->toBeNull()
        ->and($guard->resolveForAccount(999999, $group->id, 'synthetic:learning'))->toBeNull()
        ->and($fake->calls)->toBe(0)->and(t05aTables())->toBe($before);
});

it('fails closed on absent evidence', function () {
    [$actor, $group] = t05aContext();
    $before = t05aTables();
    expect((new LearningAuthorization(t05aFake(absent: true)))->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBeNull()
        ->and(t05aTables())->toBe($before);
});

it('rejects incompatible bound witnesses and non-exact references', function (array $change) {
    [$actor, $group] = t05aContext();
    $before = t05aTables();
    expect((new LearningAuthorization(t05aFake($change)))->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBeNull()
        ->and(t05aTables())->toBe($before);
})->with([
    'actor' => [['actor_id' => 999999]], 'group' => [['group_id' => 999999]],
    'membership' => [['membership_id' => 999999]], 'purpose' => [['purpose' => 'synthetic:other']],
    'revoked' => [['state' => 'revoked']], 'policy version' => [['policy_version' => 'other']],
    'policy digest' => [['policy_digest' => hash('sha256', 'other')]],
    'issuer ref' => [['issuer_ref' => 'synthetic:other']], 'issuer version' => [['issuer_version' => 'other']],
    'issuer digest' => [['issuer_digest' => hash('sha256', 'other')]],
    'capability ref' => [['capability_ref' => 'synthetic:other']], 'capability version' => [['capability_version' => 'other']],
    'capability digest' => [['capability_digest' => hash('sha256', 'other')]],
    'short SHA' => [['policy_digest' => 'abc']], 'uppercase SHA' => [['policy_digest' => str_repeat('A', 64)]],
    'non hex SHA' => [['issuer_digest' => str_repeat('z', 64)]], 'nonstring SHA' => [['policy_digest' => 123]],
    'missing evidence' => [['capability_ref' => null]], 'operational source' => [['provenance' => 'human_product']],
    'promotable' => [['promotion_allowed' => true]], 'extra assertion' => [['granted' => true]],
]);

it('reports issuer unavailability precisely and propagates programming errors', function () {
    [$actor, $group] = t05aContext();
    $before = t05aTables();
    $error = new RuntimeException('synthetic issuer unavailable');
    try {
        (new LearningAuthorization(t05aFake(error: $error)))->resolveForAccount($actor->id, $group->id, 'synthetic:learning');
        $this->fail('expected issuer exception');
    } catch (RuntimeException $caught) {
        expect($caught->getMessage())->toBe('learning_authorization.authority_unavailable')->and($caught->getPrevious())->toBe($error);
    }
    expect(fn () => (new LearningAuthorization(t05aFake(error: new LogicException('synthetic setup error'))))->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))
        ->toThrow(LogicException::class, 'synthetic setup error');
    expect(t05aTables())->toBe($before);
});

it('rejects absent or malformed trusted references before requesting evidence', function (?array $references) {
    [$actor, $group] = t05aContext();
    $before = t05aTables();
    $fake = t05aFake(references: $references);
    expect((new LearningAuthorization($fake))->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBeNull()
        ->and($fake->calls)->toBe(0)->and(t05aTables())->toBe($before);
})->with([
    'absent' => [null], 'short' => [['issuer_digest' => 'abc']],
    'empty version' => [['policy_version' => '']], 'nonexact' => [['capability_digest' => str_repeat('A', 64)]],
]);

it('rechecks revocation made during synthetic issuer resolution', function () {
    [$actor, $group, $membership] = t05aContext();
    $fake = t05aFake(duringResolve: function () use ($membership) {
        DB::table('learning_company_memberships')->where('id', $membership->id)->update(['verification_status' => 'revoked']);
    });
    $cases = DB::table('learning_cases')->get()->toJson();
    $states = DB::table('learning_case_states')->get()->toJson();
    expect((new LearningAuthorization($fake))->resolveForAccount($actor->id, $group->id, 'synthetic:learning'))->toBeNull()
        ->and($fake->calls)->toBe(1)->and($membership->verification_status)->toBe('verified')
        ->and(DB::table('learning_company_memberships')->value('verification_status'))->toBe('revoked')
        ->and(DB::table('learning_cases')->get()->toJson())->toBe($cases)
        ->and(DB::table('learning_case_states')->get()->toJson())->toBe($states);
});
