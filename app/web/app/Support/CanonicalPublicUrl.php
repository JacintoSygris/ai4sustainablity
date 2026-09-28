<?php

namespace App\Support;

use LogicException;

final class CanonicalPublicUrl
{
    public static function root(): string
    {
        $root = rtrim(trim((string) config('app.url')), '/');
        $parts = parse_url($root);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '')) {
            throw new LogicException('APP_URL must be a canonical origin without credentials, path, query, or fragment.');
        }

        if (app()->environment('production') && ($parts['scheme'] ?? null) !== 'https') {
            throw new LogicException('APP_URL must use HTTPS in production.');
        }

        $host = mb_strtolower((string) $parts['host']);
        $trustedHosts = array_map(
            static fn ($value): string => mb_strtolower(trim((string) $value)),
            (array) config('app.trusted_hosts', []),
        );

        if (app()->environment('production') && ! in_array($host, $trustedHosts, true)) {
            throw new LogicException('APP_URL host must be present in TRUSTED_HOSTS.');
        }

        return $root;
    }

    /** @param array<string, scalar|null> $query */
    public static function to(string $path, array $query = []): string
    {
        $url = self::root().'/'.ltrim($path, '/');
        $encodedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $encodedQuery === '' ? $url : $url.'?'.$encodedQuery;
    }
}
