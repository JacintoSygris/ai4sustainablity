<?php

namespace App\Console\Commands;

use App\Models\LearningCompanyGroup;
use App\Models\LearningCompanyMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AssignLearningCompany extends Command
{
    protected $signature = 'learning:assign-company
        {action : create, assign, or revoke}
        {subject-type : account or source}
        {subject-id : Exact numeric account ID or lowercase source SHA-256}
        {--group-id= : Existing numeric group ID for assign}
        {--evidence-digest= : SHA-256 of private verification evidence}
        {--evidence-type= : Non-personal evidence type token}';

    protected $description = 'Create, assign, or revoke verified learning-company memberships by exact IDs and digests';

    public function handle(): int
    {
        try {
            [$action, $subjectType, $subjectIdentifier, $userId, $evidenceDigest, $evidenceType] =
                $this->validatedInputs();

            $membership = DB::transaction(function () use (
                $action,
                $subjectType,
                $subjectIdentifier,
                $userId,
                $evidenceDigest,
                $evidenceType,
            ): LearningCompanyMembership {
                $membership = LearningCompanyMembership::query()
                    ->where('subject_type', $subjectType)
                    ->where('subject_identifier', $subjectIdentifier)
                    ->lockForUpdate()
                    ->first();

                if ($action === 'revoke') {
                    if (! $membership instanceof LearningCompanyMembership) {
                        throw new InvalidArgumentException('membership_not_found');
                    }

                    if ($membership->verification_status === LearningCompanyMembership::STATUS_REVOKED) {
                        if ($membership->revoked_at !== null
                            && $membership->revocation_evidence_digest === $evidenceDigest
                            && $membership->revocation_evidence_type === $evidenceType) {
                            return $membership;
                        }

                        throw new InvalidArgumentException('membership_revocation_conflict');
                    }

                    if (! $this->isActiveVerifiedMembership($membership)
                        || $membership->revocation_evidence_digest !== null
                        || $membership->revocation_evidence_type !== null) {
                        throw new InvalidArgumentException('membership_not_active');
                    }

                    $membership->forceFill([
                        'revocation_evidence_digest' => $evidenceDigest,
                        'revocation_evidence_type' => $evidenceType,
                        'verification_status' => LearningCompanyMembership::STATUS_REVOKED,
                        'revoked_at' => now(),
                    ])->save();

                    return $membership;
                }

                if ($action === 'create') {
                    if ($membership instanceof LearningCompanyMembership) {
                        $this->assertExactActiveReplay(
                            $membership,
                            null,
                            $userId,
                            $evidenceDigest,
                            $evidenceType,
                        );

                        return $membership;
                    }
                    $group = $this->createPseudonymousGroup();
                } else {
                    $group = LearningCompanyGroup::query()
                        ->whereKey((int) $this->option('group-id'))
                        ->lockForUpdate()
                        ->firstOrFail();
                    if ($membership instanceof LearningCompanyMembership) {
                        $this->assertExactActiveReplay(
                            $membership,
                            $group->id,
                            $userId,
                            $evidenceDigest,
                            $evidenceType,
                        );

                        return $membership;
                    }
                }

                $membership = new LearningCompanyMembership;
                $membership->forceFill([
                    'learning_company_group_id' => $group->id,
                    'subject_type' => $subjectType,
                    'subject_identifier' => $subjectIdentifier,
                    'user_id' => $userId,
                    'evidence_digest' => $evidenceDigest,
                    'evidence_type' => $evidenceType,
                    'revocation_evidence_digest' => null,
                    'revocation_evidence_type' => null,
                    'verification_status' => LearningCompanyMembership::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'revoked_at' => null,
                ])->save();

                return $membership;
            }, 3);
        } catch (\Throwable $exception) {
            $this->error('learning_company_assignment_failed:'.$this->safeErrorCode($exception));

            return self::FAILURE;
        }

        $this->info(
            "membership_id={$membership->id} group_id={$membership->learning_company_group_id} "
            ."status={$membership->verification_status}",
        );

        return self::SUCCESS;
    }

    /** @return array{string, string, string, int|null, string, string} */
    private function validatedInputs(): array
    {
        $action = (string) $this->argument('action');
        $subjectType = (string) $this->argument('subject-type');
        $subjectIdentifier = (string) $this->argument('subject-id');
        $evidenceDigest = (string) $this->option('evidence-digest');
        $evidenceType = (string) $this->option('evidence-type');
        $groupId = $this->option('group-id');

        if (! in_array($action, ['create', 'assign', 'revoke'], true)) {
            throw new InvalidArgumentException('action_invalid');
        }
        if (! in_array($subjectType, [
            LearningCompanyMembership::SUBJECT_ACCOUNT,
            LearningCompanyMembership::SUBJECT_SOURCE,
        ], true)) {
            throw new InvalidArgumentException('subject_type_invalid');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $evidenceDigest) !== 1) {
            throw new InvalidArgumentException('evidence_digest_invalid');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/', $evidenceType) !== 1) {
            throw new InvalidArgumentException('evidence_type_invalid');
        }

        $userId = null;
        if ($subjectType === LearningCompanyMembership::SUBJECT_ACCOUNT) {
            if (preg_match('/\A[1-9][0-9]*\z/', $subjectIdentifier) !== 1) {
                throw new InvalidArgumentException('account_id_invalid');
            }
            $userId = (int) $subjectIdentifier;
            if ((string) $userId !== $subjectIdentifier || ! User::query()->whereKey($userId)->exists()) {
                throw new InvalidArgumentException('account_id_invalid');
            }
        } elseif (preg_match('/\A[a-f0-9]{64}\z/', $subjectIdentifier) !== 1) {
            throw new InvalidArgumentException('source_digest_invalid');
        }

        if ($action === 'assign') {
            if (! is_string($groupId)
                || preg_match('/\A[1-9][0-9]*\z/', $groupId) !== 1
                || (string) ((int) $groupId) !== $groupId) {
                throw new InvalidArgumentException('group_id_required');
            }
        } elseif ($groupId !== null) {
            throw new InvalidArgumentException('group_id_not_allowed');
        }

        return [$action, $subjectType, $subjectIdentifier, $userId, $evidenceDigest, $evidenceType];
    }

    private function createPseudonymousGroup(): LearningCompanyGroup
    {
        $group = new LearningCompanyGroup;
        $group->forceFill([
            'company_group_key' => hash('sha256', Str::uuid()->toString().random_bytes(32)),
        ])->save();

        return $group;
    }

    private function assertExactActiveReplay(
        LearningCompanyMembership $membership,
        ?int $expectedGroupId,
        ?int $userId,
        string $evidenceDigest,
        string $evidenceType,
    ): void {
        if (! $this->isActiveVerifiedMembership($membership)
            || ($expectedGroupId !== null && $membership->learning_company_group_id !== $expectedGroupId)
            || $membership->user_id !== $userId
            || $membership->evidence_digest !== $evidenceDigest
            || $membership->evidence_type !== $evidenceType
            || $membership->revocation_evidence_digest !== null
            || $membership->revocation_evidence_type !== null) {
            throw new InvalidArgumentException('membership_replay_conflict');
        }
    }

    private function isActiveVerifiedMembership(LearningCompanyMembership $membership): bool
    {
        return $membership->verification_status === LearningCompanyMembership::STATUS_VERIFIED
            && $membership->verified_at !== null
            && $membership->revoked_at === null;
    }

    private function safeErrorCode(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/\A[a-z0-9_]+\z/', $message) === 1 ? $message : 'operation_failed';
    }
}
