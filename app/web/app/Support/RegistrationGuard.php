<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Public-signup abuse guard: honeypot + Cloudflare Turnstile.
 *
 * Local and test environments may omit Turnstile. Production registration is
 * available only when every prerequisite is configured with non-test keys.
 */
class RegistrationGuard
{
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var list<string> */
    private const TURNSTILE_TEST_SITE_KEYS = [
        '1x00000000000000000000AA',
        '2x00000000000000000000AB',
        '3x00000000000000000000FF',
    ];

    /** @var list<string> */
    private const TURNSTILE_TEST_SECRET_KEYS = [
        '1x0000000000000000000000000000000AA',
        '2x0000000000000000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    ];

    public static function registrationAvailable(): bool
    {
        if (! config('services.auth_hardening.public_registration_enabled')) {
            return false;
        }

        if (! app()->environment('production')) {
            return true;
        }

        $siteKey = trim((string) config('services.auth_hardening.turnstile.site_key'));
        $secret = trim((string) config('services.auth_hardening.turnstile.secret'));

        return (bool) config('services.auth_hardening.require_email_verification')
            && self::isTurnstileConfigured()
            && ! in_array($siteKey, self::TURNSTILE_TEST_SITE_KEYS, true)
            && ! in_array($secret, self::TURNSTILE_TEST_SECRET_KEYS, true)
            && filled(config('services.auth_hardening.turnstile.expected_hostname'))
            && config('services.auth_hardening.turnstile.expected_action') === 'register'
            && hash_equals(self::TURNSTILE_VERIFY_URL, trim((string) config('services.auth_hardening.turnstile.verify_url')))
            && SensitiveDeliveryGuard::queueIsDurable()
            && SensitiveDeliveryGuard::mailerProtectsSecrets()
            && self::hasBoundCanonicalOrigin();
    }

    /**
     * @return array<string, string> validation-style errors (empty = passed)
     */
    public static function check(Request $request): array
    {
        $errors = [];

        $honeypotField = (string) config('services.auth_hardening.honeypot_field');
        if ($honeypotField !== '' && filled($request->input($honeypotField))) {
            // A bot filled the hidden field. Return a generic error; never reveal
            // the honeypot to the client copy.
            $errors['email'] = __('No se ha podido completar el registro. Inténtalo de nuevo.');

            return $errors;
        }

        $secret = config('services.auth_hardening.turnstile.secret');
        $siteKey = config('services.auth_hardening.turnstile.site_key');
        $verifyUrl = trim((string) config('services.auth_hardening.turnstile.verify_url'));
        if (blank($secret) || blank($siteKey)) {
            if (app()->environment('production')) {
                $errors['email'] = __('No se ha podido verificar que eres una persona. Inténtalo de nuevo.');
            }

            return $errors;
        }

        if (! hash_equals(self::TURNSTILE_VERIFY_URL, $verifyUrl)) {
            $errors['email'] = __('No se ha podido verificar que eres una persona. Inténtalo de nuevo.');

            return $errors;
        }

        if (app()->environment('production') && (
            in_array((string) $siteKey, self::TURNSTILE_TEST_SITE_KEYS, true)
            || in_array((string) $secret, self::TURNSTILE_TEST_SECRET_KEYS, true)
        )) {
            $errors['email'] = __('No se ha podido verificar que eres una persona. Inténtalo de nuevo.');

            return $errors;
        }

        $token = (string) $request->input('cf-turnstile-response');
        if ($token === '') {
            $errors['email'] = __('Confirma que no eres un robot e inténtalo de nuevo.');

            return $errors;
        }

        try {
            $response = Http::asForm()
                ->withoutRedirecting()
                ->timeout(10)
                ->post($verifyUrl, [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
            $passed = (bool) ($response->json('success') ?? false);

            if ($passed && app()->environment('production')) {
                $expectedHostname = mb_strtolower(trim((string) config('services.auth_hardening.turnstile.expected_hostname')));
                $actualHostname = mb_strtolower(trim((string) $response->json('hostname')));
                $expectedAction = trim((string) config('services.auth_hardening.turnstile.expected_action'));
                $actualAction = trim((string) $response->json('action'));

                $passed = $expectedHostname !== ''
                    && $expectedAction !== ''
                    && hash_equals($expectedHostname, $actualHostname)
                    && hash_equals($expectedAction, $actualAction);
            }
        } catch (\Throwable $e) {
            // Verification service unreachable -> fail CLOSED for a configured
            // guard (a public signup form must not silently drop protection).
            $passed = false;
        }

        if (! $passed) {
            $errors['email'] = __('No se ha podido verificar que eres una persona. Inténtalo de nuevo.');
        }

        return $errors;
    }

    public static function isTurnstileConfigured(): bool
    {
        return filled(config('services.auth_hardening.turnstile.site_key'))
            && filled(config('services.auth_hardening.turnstile.secret'))
            && hash_equals(self::TURNSTILE_VERIFY_URL, trim((string) config('services.auth_hardening.turnstile.verify_url')));
    }

    private static function hasBoundCanonicalOrigin(): bool
    {
        try {
            $host = mb_strtolower((string) parse_url(CanonicalPublicUrl::root(), PHP_URL_HOST));
            $expected = mb_strtolower(trim((string) config('services.auth_hardening.turnstile.expected_hostname')));

            return $host !== '' && hash_equals($host, $expected);
        } catch (\Throwable) {
            return false;
        }
    }
}
