<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentAuthVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $currentVersion = (int) $user->auth_version;
        $sessionVersion = $request->session()->get('auth_version');

        if ($sessionVersion === null && $currentVersion === 0) {
            $request->session()->put('auth_version', 0);

            return $next($request);
        }

        if (! is_int($sessionVersion) || $sessionVersion !== $currentVersion) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('La sesión ya no es válida. Vuelve a iniciar sesión.'),
                    'code' => 'session_revoked',
                ], 401);
            }

            return redirect()->route('login')->with('status', __('Tu sesión ha caducado. Vuelve a iniciar sesión.'));
        }

        return $next($request);
    }
}
