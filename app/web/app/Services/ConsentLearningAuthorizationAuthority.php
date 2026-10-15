<?php

namespace App\Services;

use App\Models\LearningCompanyMembership;
use App\Models\User;

/** Explicit purpose-specific consent, never inferred from P8 attestation. */
final class ConsentLearningAuthorizationAuthority implements LearningAuthorizationAuthority
{
    public static function enabled(): bool
    {
        return config('learning_engine.enabled') === true
            && config('learning_engine.authority') === 'consent';
    }

    public function permits(User $user): bool
    {
        return self::enabled() && $user->hasVerifiedEmail()
            && $user->learning_consent_at !== null && $user->learning_subject !== null
            && $user->learning_consent_version === config('learning_engine.consent_version')
            && (! config('learning_engine.synthetic_only') || $user->learning_synthetic === true);
    }

    public function membership(User $user): ?LearningCompanyMembership
    {
        if (! $this->permits($user)) { return null; }
        $membership = LearningCompanyMembership::where('user_id', $user->id)
            ->where('subject_type', 'account')->where('subject_identifier', (string) $user->id)
            ->where('verification_status', 'verified')->whereNotNull('verified_at')->whereNull('revoked_at')
            ->whereNull('revocation_evidence_digest')->whereNull('revocation_evidence_type')
            ->with('group')->first();
        return $membership?->group === null ? null : $membership;
    }

    /** Bind the exported grant to the exact verified membership and company group. */
    public function binding(User $user, LearningCompanyMembership $membership): string
    {
        return hash('sha256', json_encode([
            $user->learning_subject, $membership->id, $membership->learning_company_group_id,
            $membership->group->company_group_key, $membership->evidence_digest,
            $membership->evidence_type, $membership->getRawOriginal('verified_at'),
        ], JSON_THROW_ON_ERROR));
    }

    public function references(string $purpose): ?array
    {
        if (! self::enabled() || $purpose !== 'feedback:supervised-learning') {
            return null;
        }
        $version = config('learning_engine.consent_version');
        return [
            'issuer_ref' => 'laravel:recorded-consent', 'issuer_version' => 'v1',
            'issuer_digest' => hash('sha256', 'laravel:recorded-consent:v1'),
            'capability_ref' => $purpose, 'capability_version' => 'v1',
            'capability_digest' => hash('sha256', $purpose),
            'policy_version' => $version, 'policy_digest' => hash('sha256', $version),
        ];
    }

    public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array
    {
        $references = $this->references($purpose);
        if ($references === null || ! $this->permits($actor) || $membership->user_id !== $actor->id
            || $this->membership($actor)?->id !== $membership->id) {
            return null;
        }
        return array_merge($references, [
            'actor_id' => $actor->id, 'group_id' => $membership->learning_company_group_id,
            'membership_id' => $membership->id, 'purpose' => $purpose, 'state' => 'available',
            'provenance' => $actor->learning_synthetic ? 'synthetic-only' : 'consented-feedback',
            'promotion_allowed' => false, // Consent is not a model promotion decision.
        ]);
    }
}
