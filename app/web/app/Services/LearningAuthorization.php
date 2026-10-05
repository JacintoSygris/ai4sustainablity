<?php

namespace App\Services;

use App\Models\LearningCompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Resolution only: no persistence, grant, eligibility, closure or operational caller. */
final class LearningAuthorization
{
    private const REFERENCES = [
        'issuer_ref', 'issuer_version', 'issuer_digest',
        'capability_ref', 'capability_version', 'capability_digest',
        'policy_version', 'policy_digest',
    ];

    public function __construct(private readonly LearningAuthorizationAuthority $authority) {}

    /**
     * Identifiers select server context; no client witness or policy is accepted.
     * A non-null result is a synthetic mechanism witness, NEVER operational rights.
     * Null means denied; issuer outages throw with a stable message and original cause.
     *
     * @return array<string, mixed>|null
     */
    public function resolveForAccount(int $actorId, int $groupId, string $purpose): ?array
    {
        if ($actorId < 1 || $groupId < 1 || $purpose === '' || trim($purpose) !== $purpose) {
            return null;
        }

        return DB::transaction(function () use ($actorId, $groupId, $purpose): ?array {
            // Existing lifecycle order: user first, then dependent learning context.
            $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
            if ($actor === null || ! $actor->hasVerifiedEmail()) {
                return null;
            }
            $membership = $this->membership($actorId, $groupId);
            if ($membership === null) {
                return null;
            }

            try {
                $references = $this->authority->references($purpose);
                if (! $this->validReferences($references)) {
                    return null;
                }
                $witness = $this->authority->resolve($actor, $membership, $purpose);
                $currentReferences = $this->authority->references($purpose);
            } catch (RuntimeException $error) {
                throw new RuntimeException('learning_authorization.authority_unavailable', 0, $error);
            }

            $context = [
                'actor_id' => $actorId, 'group_id' => $groupId, 'membership_id' => $membership->id,
                'purpose' => $purpose, 'state' => 'available',
                'provenance' => 'synthetic-only', 'promotion_allowed' => false,
            ];
            if (! is_array($witness) || ! $this->validReferences($currentReferences)) {
                return null;
            }
            $expected = array_merge($references, $context);
            if (count($witness) !== count($expected)) {
                return null;
            }
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $witness) || $witness[$key] !== $value) {
                    return null;
                }
            }
            foreach ($references as $key => $value) {
                if ($currentReferences[$key] !== $value) {
                    return null;
                }
            }

            // Do not reuse a previously loaded model, including after issuer resolution.
            $currentMembership = $this->membership($actorId, $groupId);
            $currentActor = User::query()->whereKey($actorId)->first();
            if ($currentMembership === null || $currentMembership->id !== $membership->id
                || $currentActor === null || ! $currentActor->hasVerifiedEmail()) {
                return null;
            }

            return $expected;
        });
    }

    private function membership(int $actorId, int $groupId): ?LearningCompanyMembership
    {
        return LearningCompanyMembership::query()
            ->where('user_id', $actorId)->where('subject_type', LearningCompanyMembership::SUBJECT_ACCOUNT)
            ->where('subject_identifier', (string) $actorId)->where('learning_company_group_id', $groupId)
            ->where('verification_status', LearningCompanyMembership::STATUS_VERIFIED)
            ->whereNotNull('verified_at')->whereNull('revoked_at')->whereHas('group')
            ->lockForUpdate()->first();
    }

    private function validReferences(?array $references): bool
    {
        if ($references === null || count($references) !== count(self::REFERENCES)) {
            return false;
        }
        foreach (self::REFERENCES as $key) {
            $value = $references[$key] ?? null;
            if (! is_string($value) || $value === '' || trim($value) !== $value) {
                return false;
            }
            if (str_ends_with($key, '_digest') && preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}
