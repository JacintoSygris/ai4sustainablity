<?php

namespace App\Auth;

final readonly class OAuthIdentityDescriptor
{
    public function __construct(
        public string $provider,
        public string $issuer,
        public string $subject,
    ) {}
}
