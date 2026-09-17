<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces email verification ONLY when
 * `services.auth_hardening.require_email_verification` is on (production).
 * Default-off so local/CI and the existing 241 tests are unaffected until the
 * operator flips AUTH_REQUIRE_EMAIL_VERIFICATION=true. Fails safe: an
 * unverified user hitting a protected route gets 409 (JSON) or the verification
 * notice (web), never silent access.
 */
class EnsureEmailVerifiedWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.auth_hardening.require_email_verification')) {
            return $next($request);
        }

        $user = $request->user();
        if ($user && ! $user->hasVerifiedEmail()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Confirma tu correo electrónico para continuar.',
                    'code' => 'email_unverified',
                ], 409);
            }

            return Redirect::route('verification.notice');
        }

        return $next($request);
    }
}
