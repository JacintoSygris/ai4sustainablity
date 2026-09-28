<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Runs before any web guard resolves a user, including auth-version middleware. */
class RemoveLegacyRememberCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // getRecallerName constructs the guard without calling user()/check().
        $name = Auth::guard('web')->getRecallerName();
        $present = $request->cookies->has($name);
        $request->cookies->remove($name);

        $response = $next($request);
        if ($present) {
            $response->headers->setCookie(Cookie::forget(
                $name,
                config('session.path', '/'),
                config('session.domain'),
            ));
        }

        return $response;
    }
}
