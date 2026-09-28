<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTrustedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('app.enforce_trusted_hosts', false)) {
            // Symfony stores trusted hosts statically; clear a previous request's
            // production policy when a long-lived worker enters an unenforced profile.
            Request::setTrustedHosts([]);

            return $next($request);
        }

        $host = mb_strtolower($request->getHost());
        $trustedHosts = array_filter(array_map(
            static fn ($value): string => mb_strtolower(trim((string) $value)),
            (array) config('app.trusted_hosts', []),
        ));

        abort_unless(in_array($host, $trustedHosts, true), 400);

        return $next($request);
    }
}
