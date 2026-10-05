<?php

namespace App\Services;

use App\Models\LearningCompanyMembership;
use App\Models\User;

/**
 * Server-owned, local, read-only port. Never bind request assertions to this port.
 * Membership proves a relation, not a capability. Real authority is unavailable.
 * RuntimeException means issuer unavailable; programming errors must propagate.
 */
interface LearningAuthorizationAuthority
{
    /** @return array<string, string>|null Exact current issuer/capability/policy references. */
    public function references(string $purpose): ?array;

    /** @return array<string, mixed>|null Internal evidence; never a rights grant. */
    public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array;
}
