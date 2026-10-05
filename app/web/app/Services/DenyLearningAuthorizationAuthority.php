<?php

namespace App\Services;

use App\Models\LearningCompanyMembership;
use App\Models\User;

final class DenyLearningAuthorizationAuthority implements LearningAuthorizationAuthority
{
    public function references(string $purpose): ?array
    {
        return null;
    }

    public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array
    {
        return null;
    }
}
