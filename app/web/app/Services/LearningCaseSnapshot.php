<?php

namespace App\Services;

use App\Models\LearningCase;
use App\Models\LearningCaseState;
use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LearningCaseSnapshot
{
    private const INITIAL_OPERATIONAL_STATUS = 'stored';

    public function __construct(private readonly LearningCaseContract $contract) {}

    public function createForAccount(int $userId, string $caseJson, string $authorityJson): LearningCase
    {
        $membership = $this->verifiedAccountMembership($userId);
        $canonicalPayload = $this->validatedCanonicalPayload($caseJson, $authorityJson);
        $payload = $this->decodeCanonicalPayload($canonicalPayload);
        $this->assertSourceKind(
            $payload,
            'human_product',
            'learning_case.source_kind_invalid_for_account',
        );

        return $this->persistValidated($membership, $payload, $canonicalPayload);
    }

    public function createForSource(
        int $userId,
        string $sourceRecordDigest,
        string $caseJson,
        string $authorityJson,
    ): LearningCase {
        $this->assertDigest($sourceRecordDigest, 'learning_company.source_digest_invalid');
        $accountMembership = $this->verifiedAccountMembership($userId);
        $sourceMembership = $this->verifiedMembership(
            LearningCompanyMembership::SUBJECT_SOURCE,
            $sourceRecordDigest,
            'learning_company.source_membership_ineligible',
        );
        if ($sourceMembership->learning_company_group_id !== $accountMembership->learning_company_group_id) {
            throw new InvalidArgumentException('learning_company.source_group_mismatch');
        }

        $canonicalPayload = $this->validatedCanonicalPayload($caseJson, $authorityJson);
        $payload = $this->decodeCanonicalPayload($canonicalPayload);
        $this->assertSourceKind(
            $payload,
            'report',
            'learning_case.source_kind_invalid_for_source',
        );
        if (($payload['provenance']['source_record_digest'] ?? null) !== $sourceRecordDigest) {
            throw new InvalidArgumentException('learning_case.source_digest_mismatch');
        }

        return $this->persistValidated(
            $accountMembership,
            $payload,
            $canonicalPayload,
            $sourceRecordDigest,
        );
    }

    public function findForUser(int $userId, int $caseId): LearningCase
    {
        $case = LearningCase::query()
            ->whereKey($caseId)
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')
                    ->from('learning_company_memberships')
                    ->whereColumn(
                        'learning_company_memberships.learning_company_group_id',
                        'learning_cases.learning_company_group_id',
                    )
                    ->where('learning_company_memberships.subject_type', LearningCompanyMembership::SUBJECT_ACCOUNT)
                    ->where('learning_company_memberships.subject_identifier', (string) $userId)
                    ->where('learning_company_memberships.user_id', $userId)
                    ->where(
                        'learning_company_memberships.verification_status',
                        LearningCompanyMembership::STATUS_VERIFIED,
                    )
                    ->whereNotNull('learning_company_memberships.verified_at')
                    ->whereNull('learning_company_memberships.revoked_at');
            })
            ->first();

        if (! $case instanceof LearningCase) {
            throw new InvalidArgumentException('learning_case.not_accessible');
        }

        return $case;
    }

    public function updateOperationalStateForUser(int $userId, int $caseId, string $status): LearningCaseState
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $status) !== 1) {
            throw new InvalidArgumentException('learning_case.state_invalid');
        }

        return DB::transaction(function () use ($userId, $caseId, $status): LearningCaseState {
            $membership = $this->verifiedAccountMembership($userId, lock: true);
            $case = LearningCase::query()
                ->whereKey($caseId)
                ->where('learning_company_group_id', $membership->learning_company_group_id)
                ->lockForUpdate()
                ->first();
            if (! $case instanceof LearningCase) {
                throw new InvalidArgumentException('learning_case.not_accessible');
            }

            $state = LearningCaseState::query()
                ->where('learning_case_id', $case->id)
                ->lockForUpdate()
                ->firstOrFail();
            $state->forceFill([
                'status' => $status,
                'state_version' => $state->state_version + 1,
            ])->save();

            return $state->refresh();
        }, 3);
    }

    private function validatedCanonicalPayload(string $caseJson, string $authorityJson): string
    {
        $this->contract->assertEligibleLearningCase($caseJson, $authorityJson);
        $this->contract->assertLearningCaseHash($caseJson);

        return LearningCaseContract::canonicalLearningCasePayload($caseJson);
    }

    /** @param array<string, mixed> $payload */
    private function persistValidated(
        LearningCompanyMembership $membership,
        array $payload,
        string $canonicalPayload,
        ?string $sourceRecordDigest = null,
    ): LearningCase {
        $periodKey = $payload['period_scope']['period_key'];
        $perimeterKey = $payload['period_scope']['perimeter_key'];
        if (strlen($payload['case_id']) > 128
            || strlen($periodKey) > 64
            || strlen($perimeterKey) > 64) {
            throw new InvalidArgumentException('learning_case.storage_identity_invalid');
        }
        $revisionTupleDigest = $this->canonicalValueDigest($payload['source_revisions']);
        $caseHash = $payload['case_hash'];

        return DB::transaction(function () use (
            $membership,
            $payload,
            $canonicalPayload,
            $periodKey,
            $perimeterKey,
            $revisionTupleDigest,
            $caseHash,
            $sourceRecordDigest,
        ): LearningCase {
            $lockedMembership = $this->verifiedAccountMembership(
                (int) $membership->user_id,
                lock: true,
            );
            if ($lockedMembership->id !== $membership->id
                || $lockedMembership->learning_company_group_id !== $membership->learning_company_group_id) {
                throw new InvalidArgumentException('learning_company.account_membership_ineligible');
            }
            $groupKey = LearningCompanyGroup::query()
                ->whereKey($lockedMembership->learning_company_group_id)
                ->lockForUpdate()
                ->value('company_group_key');
            if (! is_string($groupKey)
                || preg_match('/\A[a-f0-9]{64}\z/', $groupKey) !== 1
                || ($payload['company_group_key'] ?? null) !== $groupKey) {
                throw new InvalidArgumentException('learning_case.company_group_mismatch');
            }
            $identityDigest = $this->identityDigest(
                $groupKey,
                $periodKey,
                $perimeterKey,
                $revisionTupleDigest,
            );
            if ($sourceRecordDigest !== null) {
                $lockedSourceMembership = $this->verifiedMembership(
                    LearningCompanyMembership::SUBJECT_SOURCE,
                    $sourceRecordDigest,
                    'learning_company.source_membership_ineligible',
                    lock: true,
                );
                if ($lockedSourceMembership->learning_company_group_id
                    !== $lockedMembership->learning_company_group_id) {
                    throw new InvalidArgumentException('learning_company.source_group_mismatch');
                }
            }

            LearningCase::query()->insertOrIgnore([[
                'learning_company_group_id' => $lockedMembership->learning_company_group_id,
                'case_id' => $payload['case_id'],
                'period_key' => $periodKey,
                'perimeter_key' => $perimeterKey,
                'revision_tuple_digest' => $revisionTupleDigest,
                'identity_digest' => $identityDigest,
                'case_hash' => $caseHash,
                'payload_text' => $canonicalPayload,
                'created_at' => now(),
            ]]);

            $case = LearningCase::query()
                ->where('learning_company_group_id', $lockedMembership->learning_company_group_id)
                ->where('identity_digest', $identityDigest)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($case->period_key, $periodKey)
                || ! hash_equals($case->perimeter_key, $perimeterKey)
                || ! hash_equals($case->revision_tuple_digest, $revisionTupleDigest)
                || ! hash_equals($case->case_hash, $caseHash)
                || ! hash_equals($case->payload_text, $canonicalPayload)) {
                throw new InvalidArgumentException('learning_case.revision_identity_conflict');
            }

            LearningCaseState::query()->insertOrIgnore([[
                'learning_case_id' => $case->id,
                'status' => self::INITIAL_OPERATIONAL_STATUS,
                'state_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
            $case->setRelation('state', LearningCaseState::query()
                ->where('learning_case_id', $case->id)
                ->firstOrFail());

            return $case;
        }, 3);
    }

    private function verifiedAccountMembership(int $userId, bool $lock = false): LearningCompanyMembership
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('learning_company.account_membership_ineligible');
        }

        return $this->verifiedMembership(
            LearningCompanyMembership::SUBJECT_ACCOUNT,
            (string) $userId,
            'learning_company.account_membership_ineligible',
            $userId,
            $lock,
        );
    }

    private function verifiedMembership(
        string $subjectType,
        string $subjectIdentifier,
        string $errorCode,
        ?int $userId = null,
        bool $lock = false,
    ): LearningCompanyMembership {
        $query = LearningCompanyMembership::query()
            ->where('subject_type', $subjectType)
            ->where('subject_identifier', $subjectIdentifier)
            ->where('verification_status', LearningCompanyMembership::STATUS_VERIFIED)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        $membership = $query->first();
        if (! $membership instanceof LearningCompanyMembership) {
            throw new InvalidArgumentException($errorCode);
        }

        return $membership;
    }

    /** @return array<string, mixed> */
    private function decodeCanonicalPayload(string $canonicalPayload): array
    {
        return json_decode($canonicalPayload, true, 512, JSON_THROW_ON_ERROR);
    }

    private function canonicalValueDigest(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_LINE_TERMINATORS
            | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function identityDigest(
        string $groupKey,
        string $periodKey,
        string $perimeterKey,
        string $revisionTupleDigest,
    ): string {
        $bytes = "learning-case-identity-v1\0";
        foreach ([$groupKey, $periodKey, $perimeterKey, $revisionTupleDigest] as $value) {
            $bytes .= strlen($value).':'.$value;
        }

        return hash('sha256', $bytes);
    }

    /** @param array<string, mixed> $payload */
    private function assertSourceKind(array $payload, string $expected, string $code): void
    {
        if (($payload['provenance']['source_kind'] ?? null) !== $expected) {
            throw new InvalidArgumentException($code);
        }
    }

    private function assertDigest(string $value, string $code): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidArgumentException($code);
        }
    }
}
