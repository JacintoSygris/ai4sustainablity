<?php

namespace App\Support;

final class P6DocumentUploadGuard
{
    /** @var list<string> */
    private const DISTRIBUTED_LOCK_DRIVERS = ['database', 'redis', 'memcached', 'dynamodb'];

    public static function available(): bool
    {
        if (! (bool) config('services.p6_document_upload.enabled')) {
            return false;
        }

        if (! app()->environment('production')) {
            return true;
        }

        $queueName = (string) config('queue.default');
        $queue = config("queue.connections.{$queueName}");
        if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
            return false;
        }

        $workerTimeout = self::strictPositiveInt(config('services.p6_document_upload.ai_worker_timeout'));
        $requestTimeout = self::strictPositiveInt(config('services.p6_document_upload.extract_timeout'));
        $jobTimeout = self::strictPositiveInt(config('services.p6_document_upload.job_timeout'));
        $retryAfter = self::strictPositiveInt($queue['retry_after'] ?? null);
        if ($workerTimeout === null
            || $requestTimeout === null
            || $jobTimeout === null
            || $retryAfter === null
            || $workerTimeout >= $requestTimeout
            || $requestTimeout >= $jobTimeout
            || $jobTimeout >= $retryAfter
            || $retryAfter > 900) {
            return false;
        }

        if (! (bool) config('services.p6_document_upload.scan.enabled')
            || ! hash_equals('clamscan', trim((string) config('services.p6_document_upload.scan.binary')))
            || self::apiBaseUrl() === null
            || trim((string) config('services.characterization.api.token')) === '') {
            return false;
        }

        $cacheName = (string) config('cache.default');
        $cache = config("cache.stores.{$cacheName}");

        return is_array($cache)
            && in_array((string) ($cache['driver'] ?? ''), self::DISTRIBUTED_LOCK_DRIVERS, true);
    }

    public static function apiBaseUrl(): ?string
    {
        $raw = trim((string) config('services.characterization.api.base_url'));
        $parts = parse_url($raw);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '')) {
            return null;
        }

        $host = mb_strtolower((string) $parts['host']);
        $privateHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host) === 1
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
        if (! $privateHost) {
            return null;
        }

        return rtrim($raw, '/');
    }

    private static function strictPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
