<?php

use App\Models\LearningCase;
use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use App\Models\User;
use App\Services\LearningCaseContract;
use App\Services\LearningCaseSnapshot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('guards the T02 family ordinary UPDATE matrix', function (string $class, string $operation) {
    $user = User::factory()->create();
    $group = t02Group('family-update-matrix');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $service = app(LearningCaseSnapshot::class);
    $json = t02CaseJson($group->company_group_key);
    $case = $service->createForAccount($user->id, $json, t02AuthorityJson());
    $model = $class === LearningCase::class ? $case : $group;
    $extra = $class === LearningCase::class
        ? ['payload_text' => '{}']
        : ['company_group_key' => hash('sha256', 'changed-family-key')];
    $message = $class === LearningCase::class
        ? 'learning_case.snapshot_immutable' : 'learning_company.group_key_immutable';
    $table = $model->getTable();
    $before = (array) DB::table($table)->where('id', $model->id)->first();
    $count = DB::table($table)->count();
    $query = $class::query()->whereKey($model->id);
    $target = str_starts_with($operation, 'new-') ? new $class : $model;
    $attributes = $target->getAttributes();
    $this->travel(2)->minutes();
    $error = null;
    try {
        match ($operation) {
            'builder-update' => $query->update($extra),
            'updateOrInsert' => $query->updateOrInsert(['id' => $model->id], $extra),
            'upsert' => $query->upsert([array_replace($before, $extra)], ['id'], array_keys($extra)),
            'updateFrom' => $query->updateFrom($extra),
            'incrementEach' => $query->incrementEach(['id' => 0], $extra),
            'decrementEach' => $query->decrementEach(['id' => 0], $extra),
            'mixed-forward' => $query->InCrEmEnTeAcH(['id' => 0], $extra),
            'touch' => $query->touch('created_at'),
            'save' => $model->forceFill($extra)->save(),
            'saveQuietly' => $model->forceFill($extra)->saveQuietly(),
            'builder-increment' => $query->increment('id', 0, $extra),
            'builder-decrement' => $query->decrement('id', 0, $extra),
            'static-increment' => $class::increment('id', 0, $extra),
            'static-decrement' => $class::decrement('id', 0, $extra),
            'static-incrementQuietly' => $class::incrementQuietly('id', 0, $extra),
            'static-decrementQuietly' => $class::decrementQuietly('id', 0, $extra),
            default => $target->{str_replace(['instance-', 'new-'], '', $operation)}('id', 0, $extra),
        };
    } catch (Throwable $exception) {
        $error = [get_class($exception), $exception->getMessage()];
    }
    $this->travelBack();
    // Setup/type/unsupported-engine errors are distinct from the model guard.
    expect([
        'guard' => $error,
        'row_unchanged' => (array) DB::table($table)->where('id', $model->id)->first() === $before,
        'count_unchanged' => DB::table($table)->count() === $count,
        'arithmetic_attributes_unchanged' => in_array($operation, ['save', 'saveQuietly'], true)
            || $target->getAttributes() === $attributes,
    ])->toBe([
        'guard' => [LogicException::class, $message],
        'row_unchanged' => true,
        'count_unchanged' => true,
        'arithmetic_attributes_unchanged' => true,
    ]);
    $read = $service->findForUser($user->id, $case->id);
    $replay = $service->createForAccount($user->id, $json, t02AuthorityJson());
    expect($read->payload_text)->toBe($case->getRawOriginal('payload_text'))
        ->and($replay->id)->toBe($case->id)
        ->and($read->group->id)->toBe($group->id)
        ->and($read->state->status)->toBe('stored')
        ->and($group->cases()->firstOrFail()->id)->toBe($case->id)
        ->and($group->memberships()->firstOrFail()->user_id)->toBe($user->id);
})->with([[LearningCase::class], [LearningCompanyGroup::class]])->with([
    'builder-update', 'updateOrInsert', 'upsert', 'updateFrom',
    'incrementEach', 'decrementEach', 'mixed-forward', 'touch', 'save', 'saveQuietly',
    'builder-increment', 'builder-decrement',
    'instance-increment', 'instance-decrement', 'instance-incrementQuietly', 'instance-decrementQuietly',
    'static-increment', 'static-decrement', 'static-incrementQuietly', 'static-decrementQuietly',
    'new-increment', 'new-decrement', 'new-incrementQuietly', 'new-decrementQuietly',
]);

it('rejects unresolved and revoked account or source memberships', function () {
    $user = User::factory()->create();
    $group = t02Group('unresolved-group');
    t02Membership($group, 'account', (string) $user->id, $user->id, 'unresolved');

    expect(fn () => app(LearningCaseSnapshot::class)->createForAccount(
        $user->id,
        t02CaseJson($group->company_group_key),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.account_membership_ineligible');

    $account = LearningCompanyMembership::query()->firstOrFail();
    $account->forceFill([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'revoked_at' => now(),
    ])->save();

    expect(fn () => app(LearningCaseSnapshot::class)->createForAccount(
        $user->id,
        t02CaseJson($group->company_group_key),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.account_membership_ineligible');

    $account->forceFill(['revoked_at' => null])->save();
    $sourceDigest = hash('sha256', 'unresolved-source');
    t02Membership($group, 'source', $sourceDigest, null, 'unresolved');

    expect(fn () => app(LearningCaseSnapshot::class)->createForSource(
        $user->id,
        $sourceDigest,
        t02CaseJson($group->company_group_key, sourceKind: 'report', sourceDigest: $sourceDigest),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.source_membership_ineligible');

    $source = LearningCompanyMembership::query()->where('subject_type', 'source')->firstOrFail();
    $source->forceFill([
        'verification_status' => 'revoked',
        'verified_at' => now()->subMinute(),
        'revoked_at' => now(),
    ])->save();

    expect(fn () => app(LearningCaseSnapshot::class)->createForSource(
        $user->id,
        $sourceDigest,
        t02CaseJson($group->company_group_key, sourceKind: 'report', sourceDigest: $sourceDigest),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.source_membership_ineligible');
});

it('preserves T02 insert-only creation and inherited deletion with FK lifecycle', function (string $operation) {
    $user = User::factory()->create();
    $group = t02Group('family-lifecycle');
    $membership = t02Membership($group, 'account', (string) $user->id, $user->id);
    $case = app(LearningCaseSnapshot::class)->createForAccount(
        $user->id, t02CaseJson($group->company_group_key), t02AuthorityJson(),
    );
    $delete = fn ($model) => match ($operation) {
        'delete' => $model->delete(),
        'forceDelete' => $model->forceDelete(),
        'builder-delete' => $model->newQuery()->whereKey($model->id)->delete(),
        'builder-forceDelete' => $model->newQuery()->whereKey($model->id)->forceDelete(),
        'truncate' => $model->newQuery()->truncate(),
    };
    // Membership FK still restricts group deletion; no model retention ban.
    expect(fn () => $delete($group))->toThrow(\Illuminate\Database\QueryException::class);
    expect($group->fresh()->company_group_key)->toBe($group->company_group_key);
    $delete($case);
    expect(LearningCase::query()->count())->toBe(0)
        ->and(\App\Models\LearningCaseState::query()->count())->toBe(0);
    $membership->delete();
    $delete($group);
    expect(LearningCompanyGroup::query()->count())->toBe(0);
    $newGroup = new LearningCompanyGroup;
    $newGroup->forceFill(['company_group_key' => hash('sha256', 'quiet-new-group')])->saveQuietly();
    expect($newGroup->fresh()->company_group_key)->toBe($newGroup->company_group_key);
})->with(['delete', 'forceDelete', 'builder-delete', 'builder-forceDelete', 'truncate']);

it('binds account and source creation to the exact allowed provenance kind and source mapping', function () {
    $user = User::factory()->create();
    $accountGroup = t02Group('path-binding-account-group');
    $otherGroup = t02Group('path-binding-other-group');
    t02Membership($accountGroup, 'account', (string) $user->id, $user->id);
    $sourceDigest = hash('sha256', 'path-binding-source');
    $otherDigest = hash('sha256', 'path-binding-other-source');
    t02Membership($accountGroup, 'source', $sourceDigest);
    t02Membership($otherGroup, 'source', $otherDigest);
    $service = app(LearningCaseSnapshot::class);

    expect(fn () => $service->createForAccount(
        $user->id,
        t02CaseJson(
            $accountGroup->company_group_key,
            sourceKind: 'report',
            sourceDigest: hash('sha256', 'unmapped-report-source'),
        ),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.source_kind_invalid_for_account');

    expect(fn () => $service->createForSource(
        $user->id,
        $sourceDigest,
        t02CaseJson($accountGroup->company_group_key, sourceKind: 'human_product'),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.source_kind_invalid_for_source');

    expect(fn () => $service->createForSource(
        $user->id,
        $sourceDigest,
        t02CaseJson(
            $accountGroup->company_group_key,
            sourceKind: 'synthetic',
            sourceDigest: $sourceDigest,
        ),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.synthetic_not_eligible');

    expect(fn () => $service->createForSource(
        $user->id,
        $sourceDigest,
        t02CaseJson(
            $accountGroup->company_group_key,
            sourceKind: 'report',
            sourceDigest: hash('sha256', 'wrong-payload-source'),
        ),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.source_digest_mismatch');

    expect(fn () => $service->createForSource(
        $user->id,
        $otherDigest,
        t02CaseJson(
            $accountGroup->company_group_key,
            sourceKind: 'report',
            sourceDigest: $otherDigest,
        ),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.source_group_mismatch')
        ->and(LearningCase::query()->count())->toBe(0);
});

it('rechecks account and source revocation after prevalidation before writing', function () {
    $accountUser = User::factory()->create();
    $accountGroup = t02Group('interleaved-account-revocation-group');
    $account = t02Membership(
        $accountGroup,
        'account',
        (string) $accountUser->id,
        $accountUser->id,
    );
    $revokeAccountAfterRead = true;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (
        $account,
        &$revokeAccountAfterRead,
    ): void {
        if ($revokeAccountAfterRead
            && str_contains($query->sql, 'learning_company_memberships')
            && in_array(LearningCompanyMembership::SUBJECT_ACCOUNT, $query->bindings, true)
            && in_array($account->subject_identifier, $query->bindings, true)) {
            $revokeAccountAfterRead = false;
            DB::table('learning_company_memberships')->where('id', $account->id)->update([
                'verification_status' => LearningCompanyMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
        }
    });

    expect(fn () => app(LearningCaseSnapshot::class)->createForAccount(
        $accountUser->id,
        t02CaseJson($accountGroup->company_group_key),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.account_membership_ineligible');

    $sourceUser = User::factory()->create();
    $sourceGroup = t02Group('interleaved-source-revocation-group');
    t02Membership($sourceGroup, 'account', (string) $sourceUser->id, $sourceUser->id);
    $sourceDigest = hash('sha256', 'interleaved-source-revocation');
    $source = t02Membership($sourceGroup, 'source', $sourceDigest);
    $revokeSourceAfterRead = true;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (
        $source,
        &$revokeSourceAfterRead,
    ): void {
        if ($revokeSourceAfterRead
            && str_contains($query->sql, 'learning_company_memberships')
            && in_array(LearningCompanyMembership::SUBJECT_SOURCE, $query->bindings, true)
            && in_array($source->subject_identifier, $query->bindings, true)) {
            $revokeSourceAfterRead = false;
            DB::table('learning_company_memberships')->where('id', $source->id)->update([
                'verification_status' => LearningCompanyMembership::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
        }
    });

    expect(fn () => app(LearningCaseSnapshot::class)->createForSource(
        $sourceUser->id,
        $sourceDigest,
        t02CaseJson(
            $sourceGroup->company_group_key,
            sourceKind: 'report',
            sourceDigest: $sourceDigest,
        ),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.source_membership_ineligible')
        ->and(LearningCase::query()->count())->toBe(0);
});

it('isolates case creation and reads through active verified account memberships', function () {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $ownerGroup = t02Group('owner-group');
    $foreignGroup = t02Group('foreign-group');
    t02Membership($ownerGroup, 'account', (string) $owner->id, $owner->id);
    t02Membership($foreignGroup, 'account', (string) $foreign->id, $foreign->id);

    $service = app(LearningCaseSnapshot::class);
    $case = $service->createForAccount(
        $owner->id,
        t02CaseJson($ownerGroup->company_group_key),
        t02AuthorityJson(),
    );

    expect($service->findForUser($owner->id, $case->id)->is($case))->toBeTrue()
        ->and(fn () => $service->findForUser($foreign->id, $case->id))
        ->toThrow(InvalidArgumentException::class, 'learning_case.not_accessible');

    expect(fn () => $service->createForAccount(
        $foreign->id,
        t02CaseJson($ownerGroup->company_group_key),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.company_group_mismatch');
});

it('reads a case with one live membership-qualified SQL statement and fails after revoke or reassignment', function () {
    $user = User::factory()->create();
    $group = t02Group('atomic-read-group');
    $otherGroup = t02Group('atomic-read-other-group');
    $membership = t02Membership($group, 'account', (string) $user->id, $user->id);
    $service = app(LearningCaseSnapshot::class);
    $case = $service->createForAccount(
        $user->id,
        t02CaseJson($group->company_group_key),
        t02AuthorityJson(),
    );

    DB::flushQueryLog();
    DB::enableQueryLog();
    $read = $service->findForUser($user->id, $case->id);
    $learningQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'learning_'))
        ->values();
    DB::disableQueryLog();

    expect($read->is($case))->toBeTrue()
        ->and($learningQueries)->toHaveCount(1)
        ->and(strtolower($learningQueries[0]['query']))
        ->toContain('learning_cases', 'learning_company_memberships');

    $membership->forceFill([
        'verification_status' => LearningCompanyMembership::STATUS_REVOKED,
        'revoked_at' => now(),
    ])->save();
    expect(fn () => $service->findForUser($user->id, $case->id))
        ->toThrow(InvalidArgumentException::class, 'learning_case.not_accessible');

    $membership->forceFill([
        'learning_company_group_id' => $otherGroup->id,
        'verification_status' => LearningCompanyMembership::STATUS_VERIFIED,
        'verified_at' => now(),
        'revoked_at' => null,
    ])->save();
    expect(fn () => $service->findForUser($user->id, $case->id))
        ->toThrow(InvalidArgumentException::class, 'learning_case.not_accessible');
});

it('keeps the pseudonymous company group key stable', function () {
    $group = t02Group('stable-group');

    expect(fn () => $group->forceFill([
        'company_group_key' => hash('sha256', 'replacement-group-key'),
    ])->save())->toThrow(LogicException::class, 'learning_company.group_key_immutable');
});

it('rejects overlong indexed snapshot identity fields before persistence', function () {
    $user = User::factory()->create();
    $group = t02Group('bounded-identity-group');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $payload = t02CasePayload($group->company_group_key);
    $payload['case_id'] = str_repeat('c', 129);

    expect(fn () => app(LearningCaseSnapshot::class)->createForAccount(
        $user->id,
        t02CaseJsonFromPayload($payload),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.storage_identity_invalid');

    $payload = t02CasePayload($group->company_group_key);
    $payload['period_scope']['period_key'] = str_repeat('p', 65);

    expect(fn () => app(LearningCaseSnapshot::class)->createForAccount(
        $user->id,
        t02CaseJsonFromPayload($payload),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.storage_identity_invalid');
});

it('uses a portable exact-byte identity digest for case, space, and Unicode variants', function () {
    $user = User::factory()->create();
    $group = t02Group('exact-identity-group');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $service = app(LearningCaseSnapshot::class);
    $variants = [
        ['FY2025', 'entity-only'],
        ['fy2025', 'entity-only'],
        ['FY2025 ', 'entity-only'],
        ['FY2025', "caf\u{00E9}"],
        ['FY2025', "cafe\u{0301}"],
    ];
    $cases = [];

    foreach ($variants as [$periodKey, $perimeterKey]) {
        $payload = t02CasePayload($group->company_group_key);
        $payload['period_scope']['period_key'] = $periodKey;
        $payload['period_scope']['perimeter_key'] = $perimeterKey;
        $cases[] = $service->createForAccount(
            $user->id,
            t02CaseJsonFromPayload($payload),
            t02AuthorityJson(),
        );
    }

    $retryPayload = t02CasePayload($group->company_group_key);
    $retryPayload['period_scope']['period_key'] = 'FY2025';
    $retry = $service->createForAccount(
        $user->id,
        t02CaseJsonFromPayload($retryPayload),
        t02AuthorityJson(),
    );
    $identityDigests = LearningCase::query()->orderBy('id')->pluck('identity_digest')->all();

    expect($retry->id)->toBe($cases[0]->id)
        ->and(LearningCase::query()->count())->toBe(5)
        ->and(array_unique(array_column($cases, 'id')))->toHaveCount(5)
        ->and(array_unique($identityDigests))->toHaveCount(5)
        ->and($identityDigests[0])->toBe('d0eb5136fe59b334e2f0f6b964962341b6c6f80f9954a60d200ecae3822aa00a');

    foreach ($identityDigests as $identityDigest) {
        expect($identityDigest)->toMatch('/\A[a-f0-9]{64}\z/');
    }
});

it('reuses raw JSON validation and rejects payload hash mismatch or mutation', function () {
    $user = User::factory()->create();
    $group = t02Group('hash-group');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $validJson = t02CaseJson($group->company_group_key);
    $mutated = json_decode($validJson, true, 512, JSON_THROW_ON_ERROR);
    $mutated['p5_snapshot']['digest'] = hash('sha256', 'mutated-p5');

    expect(fn () => app(LearningCaseSnapshot::class)->createForAccount(
        $user->id,
        t02Encode($mutated),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.case_hash_mismatch');

    $payload = json_decode($validJson, true, 512, JSON_THROW_ON_ERROR);
    $reordered = array_reverse($payload, true);
    $arrayMutated = $payload;
    $arrayMutated['topic_universe']['reviewed_topic_ids'] = array_reverse(
        $arrayMutated['topic_universe']['reviewed_topic_ids'],
    );

    expect(LearningCaseContract::learningCaseDigest(t02Encode($reordered)))
        ->toBe($payload['case_hash'])
        ->and(LearningCaseContract::learningCaseDigest(t02Encode($arrayMutated)))
        ->not->toBe($payload['case_hash']);
});

it('deduplicates exact retries and creates a new case for a changed revision tuple', function () {
    $user = User::factory()->create();
    $group = t02Group('revision-group');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $service = app(LearningCaseSnapshot::class);
    $json = t02CaseJson($group->company_group_key);

    $first = $service->createForAccount($user->id, $json, t02AuthorityJson());
    $retry = $service->createForAccount($user->id, $json, t02AuthorityJson());
    $changedPayload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $changedPayload['source_revisions']['p9']['revision'] = 6;
    $changedPayload['source_revisions']['p9']['digest'] = hash('sha256', 'p9-r6');
    $changedJson = t02CaseJsonFromPayload($changedPayload);
    $changed = $service->createForAccount($user->id, $changedJson, t02AuthorityJson());

    expect($retry->id)->toBe($first->id)
        ->and($changed->id)->not->toBe($first->id)
        ->and(LearningCase::query()->count())->toBe(2);
});

it('rejects a conflicting payload under the same revision identity', function () {
    $user = User::factory()->create();
    $group = t02Group('collision-group');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $service = app(LearningCaseSnapshot::class);
    $json = t02CaseJson($group->company_group_key);
    $service->createForAccount($user->id, $json, t02AuthorityJson());
    $conflict = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $conflict['case_id'] = 'case-t02-conflict';
    $conflict['p5_snapshot']['digest'] = hash('sha256', 'different-snapshot-same-revisions');

    expect(fn () => $service->createForAccount(
        $user->id,
        t02CaseJsonFromPayload($conflict),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_case.revision_identity_conflict')
        ->and(LearningCase::query()->count())->toBe(1);
});

it('keeps snapshots immutable while operational state changes separately', function () {
    $user = User::factory()->create();
    $group = t02Group('immutable-group');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    $service = app(LearningCaseSnapshot::class);
    $case = $service->createForAccount(
        $user->id,
        t02CaseJson($group->company_group_key),
        t02AuthorityJson(),
    );
    $payloadBefore = $case->payload_text;
    $hashBefore = $case->case_hash;

    expect(fn () => $case->forceFill(['payload_text' => '{}'])->save())
        ->toThrow(LogicException::class, 'learning_case.snapshot_immutable');

    $state = $service->updateOperationalStateForUser($user->id, $case->id, 'reviewed');
    $case->refresh();

    expect($state->status)->toBe('reviewed')
        ->and($state->state_version)->toBe(1)
        ->and($case->payload_text)->toBe($payloadBefore)
        ->and($case->case_hash)->toBe($hashBefore)
        ->and(LearningCaseContract::learningCaseDigest($case->payload_text))->toBe($hashBefore);
});

it('supports account and exact verified source paths without resolving by name', function () {
    $user = User::factory()->create();
    $group = t02Group('source-group');
    $sourceDigest = hash('sha256', 'verified-source-bytes');
    t02Membership($group, 'account', (string) $user->id, $user->id);
    t02Membership($group, 'source', $sourceDigest);
    $service = app(LearningCaseSnapshot::class);

    $accountCase = $service->createForAccount(
        $user->id,
        t02CaseJson($group->company_group_key),
        t02AuthorityJson(),
    );
    $sourcePayload = t02CasePayload(
        $group->company_group_key,
        sourceKind: 'report',
        sourceDigest: $sourceDigest,
    );
    $sourcePayload['case_id'] = 'case-t02-source';
    $sourcePayload['period_scope']['period_key'] = '2024';
    $sourceCase = $service->createForSource(
        $user->id,
        $sourceDigest,
        t02CaseJsonFromPayload($sourcePayload),
        t02AuthorityJson(),
    );

    expect($accountCase->learning_company_group_id)->toBe($group->id)
        ->and($sourceCase->learning_company_group_id)->toBe($group->id);

    expect(fn () => $service->createForSource(
        $user->id,
        hash('sha256', 'other-source'),
        t02CaseJsonFromPayload($sourcePayload),
        t02AuthorityJson(),
    ))->toThrow(InvalidArgumentException::class, 'learning_company.source_membership_ineligible');
});

it('operator CLI requires exact IDs and evidence digests and can assign then revoke', function () {
    $user = User::factory()->create([
        'name' => 'Synthetic Operator Fixture',
        'email' => 'synthetic-operator-fixture@example.test',
    ]);
    $evidenceDigest = hash('sha256', 'account-evidence');

    expect(Artisan::call('learning:assign-company', [
        'action' => 'create',
        'subject-type' => 'account',
        'subject-id' => (string) $user->id,
        '--evidence-type' => 'synthetic_registry_receipt',
    ]))->toBe(1)
        ->and(Artisan::output())->not->toContain($user->name, $user->email);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'create',
        'subject-type' => 'account',
        'subject-id' => $user->name,
        '--evidence-digest' => $evidenceDigest,
        '--evidence-type' => 'synthetic_registry_receipt',
    ]))->toBe(1);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'create',
        'subject-type' => 'source',
        'subject-id' => 'synthetic-report.pdf',
        '--evidence-digest' => $evidenceDigest,
        '--evidence-type' => 'synthetic_registry_receipt',
    ]))->toBe(1);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'create',
        'subject-type' => 'account',
        'subject-id' => (string) $user->id,
        '--evidence-digest' => $evidenceDigest,
        '--evidence-type' => 'synthetic_registry_receipt',
    ]))->toBe(0);
    $output = Artisan::output();
    $membership = LearningCompanyMembership::query()->where('subject_type', 'account')->firstOrFail();

    expect($output)->toContain("membership_id={$membership->id}", "group_id={$membership->learning_company_group_id}", 'status=verified')
        ->not->toContain($user->name, $user->email, $evidenceDigest, 'subject_id=', 'subject_identifier=')
        ->and($membership->evidence_digest)->toBe($evidenceDigest)
        ->and($membership->verified_at)->not->toBeNull()
        ->and($membership->revoked_at)->toBeNull();

    $sourceDigest = hash('sha256', 'source-bytes');
    expect(Artisan::call('learning:assign-company', [
        'action' => 'assign',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--group-id' => (string) $membership->learning_company_group_id,
        '--evidence-digest' => hash('sha256', 'source-assignment-evidence'),
        '--evidence-type' => 'synthetic_source_receipt',
    ]))->toBe(0);
    $sourceMembership = LearningCompanyMembership::query()->where('subject_type', 'source')->firstOrFail();

    expect(Artisan::call('learning:assign-company', [
        'action' => 'revoke',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--evidence-digest' => hash('sha256', 'source-revocation-evidence'),
        '--evidence-type' => 'synthetic_revocation_receipt',
    ]))->toBe(0);

    $sourceMembership->refresh();
    expect($sourceMembership->verification_status)->toBe('revoked')
        ->and($sourceMembership->revoked_at)->not->toBeNull();
});

it('operator CLI permits only exact idempotent replays and never moves reactivates or overwrites evidence', function () {
    $user = User::factory()->create();
    $accountEvidence = hash('sha256', 'idempotent-account-evidence');
    $sourceEvidence = hash('sha256', 'idempotent-source-evidence');
    $sourceDigest = hash('sha256', 'idempotent-source-record');
    $evidenceType = 'synthetic_registry_receipt';

    expect(Artisan::call('learning:assign-company', [
        'action' => 'create',
        'subject-type' => 'account',
        'subject-id' => (string) $user->id,
        '--evidence-digest' => $accountEvidence,
        '--evidence-type' => $evidenceType,
    ]))->toBe(0);
    $account = LearningCompanyMembership::query()->where('subject_type', 'account')->firstOrFail();
    $accountBeforeReplay = $account->getAttributes();

    expect(Artisan::call('learning:assign-company', [
        'action' => 'create',
        'subject-type' => 'account',
        'subject-id' => (string) $user->id,
        '--evidence-digest' => $accountEvidence,
        '--evidence-type' => $evidenceType,
    ]))->toBe(0)
        ->and($account->refresh()->getAttributes())->toBe($accountBeforeReplay);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'assign',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--group-id' => (string) $account->learning_company_group_id,
        '--evidence-digest' => $sourceEvidence,
        '--evidence-type' => $evidenceType,
    ]))->toBe(0);
    $source = LearningCompanyMembership::query()->where('subject_type', 'source')->firstOrFail();
    $sourceBeforeReplay = $source->getAttributes();

    expect(Artisan::call('learning:assign-company', [
        'action' => 'assign',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--group-id' => (string) $account->learning_company_group_id,
        '--evidence-digest' => $sourceEvidence,
        '--evidence-type' => $evidenceType,
    ]))->toBe(0)
        ->and($source->refresh()->getAttributes())->toBe($sourceBeforeReplay);

    $otherGroup = t02Group('forbidden-cli-move-group');
    expect(Artisan::call('learning:assign-company', [
        'action' => 'assign',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--group-id' => (string) $otherGroup->id,
        '--evidence-digest' => $sourceEvidence,
        '--evidence-type' => $evidenceType,
    ]))->toBe(1)
        ->and($source->refresh()->getAttributes())->toBe($sourceBeforeReplay);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'assign',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--group-id' => (string) $account->learning_company_group_id,
        '--evidence-digest' => hash('sha256', 'forbidden-evidence-overwrite'),
        '--evidence-type' => $evidenceType,
    ]))->toBe(1)
        ->and($source->refresh()->getAttributes())->toBe($sourceBeforeReplay);

    $revocationEvidence = hash('sha256', 'source-revocation-evidence');
    expect(Artisan::call('learning:assign-company', [
        'action' => 'revoke',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--evidence-digest' => $revocationEvidence,
        '--evidence-type' => 'synthetic_revocation_receipt',
    ]))->toBe(0);
    $source->refresh();

    expect($source->verification_status)->toBe(LearningCompanyMembership::STATUS_REVOKED)
        ->and($source->evidence_digest)->toBe($sourceEvidence)
        ->and($source->evidence_type)->toBe($evidenceType)
        ->and($source->revocation_evidence_digest)->toBe($revocationEvidence)
        ->and($source->revocation_evidence_type)->toBe('synthetic_revocation_receipt')
        ->and($source->revoked_at)->not->toBeNull();
    $revokedAttributes = $source->getAttributes();

    expect(Artisan::call('learning:assign-company', [
        'action' => 'revoke',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--evidence-digest' => $revocationEvidence,
        '--evidence-type' => 'synthetic_revocation_receipt',
    ]))->toBe(0)
        ->and($source->refresh()->getAttributes())->toBe($revokedAttributes);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'revoke',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--evidence-digest' => hash('sha256', 'different-revocation-evidence'),
        '--evidence-type' => 'synthetic_revocation_receipt',
    ]))->toBe(1)
        ->and($source->refresh()->getAttributes())->toBe($revokedAttributes);

    expect(Artisan::call('learning:assign-company', [
        'action' => 'assign',
        'subject-type' => 'source',
        'subject-id' => $sourceDigest,
        '--group-id' => (string) $account->learning_company_group_id,
        '--evidence-digest' => $sourceEvidence,
        '--evidence-type' => $evidenceType,
    ]))->toBe(1)
        ->and($source->refresh()->getAttributes())->toBe($revokedAttributes);
});

function t02Group(string $seed): LearningCompanyGroup
{
    $group = new LearningCompanyGroup;
    $group->forceFill(['company_group_key' => hash('sha256', $seed)])->save();

    return $group;
}

function t02Membership(
    LearningCompanyGroup $group,
    string $subjectType,
    string $subjectIdentifier,
    ?int $userId = null,
    string $status = 'verified',
): LearningCompanyMembership {
    $membership = new LearningCompanyMembership;
    $membership->forceFill([
        'learning_company_group_id' => $group->id,
        'subject_type' => $subjectType,
        'subject_identifier' => $subjectIdentifier,
        'user_id' => $userId,
        'evidence_digest' => hash('sha256', "evidence-{$subjectType}-{$subjectIdentifier}"),
        'evidence_type' => 'synthetic_test_receipt',
        'verification_status' => $status,
        'verified_at' => $status === 'verified' ? now() : null,
        'revoked_at' => $status === 'revoked' ? now() : null,
    ])->save();

    return $membership;
}

function t02CaseJson(
    string $companyGroupKey,
    string $sourceKind = 'human_product',
    ?string $sourceDigest = null,
): string {
    return t02CaseJsonFromPayload(t02CasePayload($companyGroupKey, $sourceKind, $sourceDigest));
}

/** @param array<string, mixed> $payload */
function t02CaseJsonFromPayload(array $payload): string
{
    unset($payload['case_hash']);
    $payload['case_hash'] = hash('sha256', t02CanonicalJson($payload));

    return t02Encode($payload);
}

/** @return array<string, mixed> */
function t02CasePayload(
    string $companyGroupKey,
    string $sourceKind = 'human_product',
    ?string $sourceDigest = null,
): array {
    return [
        'schema_version' => 'learning-case-v1',
        'case_id' => 'case-t02-001',
        'case_hash' => str_repeat('0', 64),
        'company_group_key' => $companyGroupKey,
        'period_scope' => [
            'period_key' => '2025',
            'perimeter_key' => 'entity-only',
        ],
        'authority' => [
            'framework_version' => 'esrs-2023',
            'catalog_version' => 'ar16-v1',
            'catalog_digest' => str_repeat('a', 64),
            'mapping_version' => 'ar16-python-v1',
            'mapping_digest' => str_repeat('b', 64),
        ],
        'provenance' => [
            'source_kind' => $sourceKind,
            'source_record_digest' => $sourceDigest ?? str_repeat('c', 64),
            'source_revision' => 'source-r1',
        ],
        'source_revisions' => [
            'p5' => ['generation' => 1, 'revision' => 2, 'digest' => str_repeat('d', 64)],
            'p6' => ['generation' => 2, 'revision' => 3, 'digest' => str_repeat('e', 64)],
            'p8' => ['generation' => 3, 'revision' => 4, 'digest' => str_repeat('f', 64)],
            'p9' => ['generation' => 4, 'revision' => 5, 'digest' => str_repeat('0', 64)],
        ],
        'p5_snapshot' => [
            'schema_version' => 'p5-learning-input-v1',
            'digest' => str_repeat('4', 64),
        ],
        'p6_snapshot' => [
            'model_profile' => 'candidate-profile',
            'model_digest' => str_repeat('5', 64),
            'policy_digest' => str_repeat('6', 64),
        ],
        'topic_universe' => [
            'reviewed_topic_ids' => ['101', '102'],
            'outside_scope_topic_ids' => ['103'],
        ],
        'topic_labels' => [
            ['topic_id' => '101', 'value' => 1, 'observed_mask' => 1],
            ['topic_id' => '102', 'value' => 0, 'observed_mask' => 1],
        ],
        'datapoint_universe' => [
            'reviewed_datapoint_ids' => ['E1.IRO-1_01'],
            'outside_scope_datapoint_ids' => ['E1.IRO-1_02'],
        ],
        'datapoint_decisions' => [[
            'datapoint_id' => 'E1.IRO-1_01',
            'relevant' => true,
            'selected_to_answer' => false,
            'reason_codes' => ['scope'],
            'note' => null,
        ]],
        'rights' => [
            'policy_version' => 'learning-rights-v1',
            'policy_digest' => str_repeat('7', 64),
            'policy_status' => 'approved',
            'state' => 'granted',
            'authorization_generation' => 4,
        ],
        'closure_evidence' => [
            'declaration_version' => 'technical-closure-v1',
            'declaration_status' => 'accepted',
            'reviewed_universe' => true,
            'final_for_period_scope' => true,
            'server_actor_id' => 'system:laravel',
            'recorded_at' => '2026-10-02T09:00:00Z',
        ],
    ];
}

function t02AuthorityJson(): string
{
    return t02Encode([
        'framework_version' => 'esrs-2023',
        'catalog_version' => 'ar16-v1',
        'catalog_digest' => str_repeat('a', 64),
        'mapping_version' => 'ar16-python-v1',
        'mapping_digest' => str_repeat('b', 64),
        'topic_ids' => ['101', '102', '103'],
        'datapoint_ids' => ['E1.IRO-1_01', 'E1.IRO-1_02'],
        'ambiguous_topic_ids' => [],
        'ambiguous_datapoint_ids' => [],
    ]);
}

function t02CanonicalJson(mixed $value): string
{
    if (is_array($value) && ! array_is_list($value)) {
        ksort($value, SORT_STRING);
        $members = [];
        foreach ($value as $key => $member) {
            $members[] = t02Encode((string) $key).':'.t02CanonicalJson($member);
        }

        return '{'.implode(',', $members).'}';
    }

    if (is_array($value)) {
        return '['.implode(',', array_map(t02CanonicalJson(...), $value)).']';
    }

    return t02Encode($value);
}

function t02Encode(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION,
    );
}
