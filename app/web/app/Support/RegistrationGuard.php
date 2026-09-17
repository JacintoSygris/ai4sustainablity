<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Public-signup abuse guard: honeypot + Cloudflare Turnstile.
 *
 * Both checks FAIL-OPEN when unconfigured (no Turnstile secret) so local/CI
 * registration keeps working; production sets TURNSTILE_SITE_KEY/SECRET and the
 * checks become enforcing.
 */
class RegistrationGuard
{
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
        if (blank($secret) || blank($siteKey)) {
            return $errors; // fail-open: Turnstile not configured
        }

        $token = (string) $request->input('cf-turnstile-response');
        if ($token === '') {
            $errors['email'] = __('Confirma que no eres un robot e inténtalo de nuevo.');

            return $errors;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post((string) config('services.auth_hardening.turnstile.verify_url'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
            $passed = (bool) ($response->json('success') ?? false);
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
            && filled(config('services.auth_hardening.turnstile.secret'));
    }
}
