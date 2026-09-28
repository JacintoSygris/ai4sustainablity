<?php

namespace App\Auth;

use Firebase\JWT\JWT;
use Illuminate\Support\Arr;
use Laravel\Socialite\Contracts\User as SocialUser;
use UnexpectedValueException;

class OAuthIdentityResolver
{
    public function resolve(string $provider, SocialUser $socialUser): OAuthIdentityDescriptor
    {
        return match ($provider) {
            'google' => $this->google($socialUser),
            'microsoft' => $this->microsoft($socialUser),
            default => throw new UnexpectedValueException('Unsupported OAuth provider.'),
        };
    }

    private function google(SocialUser $socialUser): OAuthIdentityDescriptor
    {
        $raw = is_array($socialUser->user ?? null) ? $socialUser->user : [];
        $subject = trim((string) $socialUser->getId());
        $rawSubject = trim((string) Arr::get($raw, 'sub'));

        if ($subject === '' || $rawSubject === '' || ! hash_equals($rawSubject, $subject)) {
            throw new UnexpectedValueException('Invalid Google subject.');
        }

        $rawIssuer = trim((string) Arr::get($raw, 'iss', 'https://accounts.google.com'));
        if (! in_array($rawIssuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new UnexpectedValueException('Invalid Google issuer.');
        }

        return new OAuthIdentityDescriptor('google', 'https://accounts.google.com', $subject);
    }

    private function microsoft(SocialUser $socialUser): OAuthIdentityDescriptor
    {
        $tokenResponse = is_array($socialUser->accessTokenResponseBody ?? null)
            ? $socialUser->accessTokenResponseBody
            : [];
        $idToken = trim((string) Arr::get($tokenResponse, 'id_token'));
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Missing Microsoft identity token.');
        }

        try {
            /** @var object $claims */
            $claims = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[1]));
        } catch (\Throwable) {
            throw new UnexpectedValueException('Invalid Microsoft identity token.');
        }

        $tenant = trim((string) ($claims->tid ?? ''));
        $subject = trim((string) ($claims->oid ?? ''));
        $issuer = trim((string) ($claims->iss ?? ''));
        $audience = trim((string) ($claims->aud ?? ''));
        $expiresAt = (int) ($claims->exp ?? 0);
        $graphId = trim((string) $socialUser->getId());
        $expectedIssuer = "https://login.microsoftonline.com/{$tenant}/v2.0";
        $expectedAudience = trim((string) config('services.microsoft.client_id'));

        if ($tenant === '' || $subject === '' || $graphId === '' || ! hash_equals($subject, $graphId)) {
            throw new UnexpectedValueException('Invalid Microsoft subject.');
        }

        if ($issuer === '' || ! hash_equals($expectedIssuer, $issuer)) {
            throw new UnexpectedValueException('Invalid Microsoft issuer.');
        }

        if ($expectedAudience === '' || ! hash_equals($expectedAudience, $audience)) {
            throw new UnexpectedValueException('Invalid Microsoft audience.');
        }

        if ($expiresAt <= now()->timestamp) {
            throw new UnexpectedValueException('Expired Microsoft identity token.');
        }

        return new OAuthIdentityDescriptor('microsoft', $issuer, $subject);
    }
}
