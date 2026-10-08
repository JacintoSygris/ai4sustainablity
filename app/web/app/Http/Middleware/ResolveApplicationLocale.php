<?php

namespace App\Http\Middleware;

use App\Support\ApplicationLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveApplicationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // A request never inherits the previous request's locale in long-lived workers.
        // Browser language and query parameters are deliberately not preferences.
        app()->setLocale(ApplicationLocale::normalize(ApplicationLocale::preference($request)));
        $response = $next($request);
        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', app()->getLocale());
        }
        $response->setVary('Cookie', false);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
