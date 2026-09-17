<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Fail-closed virus scan for public uploads via ClamAV (`clamdscan`/`clamscan`).
 *
 * Config-gated (services.p6_document_upload.scan). Default DISABLED. When
 * enabled:
 *   - a clean scan -> allowed;
 *   - an infected file -> rejected;
 *   - the scanner missing/erroring -> REJECTED (fail-closed): a public upload
 *     surface must never silently accept unscanned files.
 *
 * The configured scanner binary must be installed and executable in the web
 * runtime. `clamdscan` is preferred; the scanner falls back to `clamscan` when
 * available.
 */
class UploadVirusScanner
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const UNAVAILABLE = 'unavailable';

    public static function isEnabled(): bool
    {
        return (bool) config('services.p6_document_upload.scan.enabled', false);
    }

    /**
     * @return array{ok: bool, result: string, detail: string}
     */
    public static function scan(UploadedFile $file): array
    {
        if (! self::isEnabled()) {
            return ['ok' => true, 'result' => self::CLEAN, 'detail' => 'scan disabled'];
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return self::fail('unreadable upload path');
        }

        $binary = (string) config('services.p6_document_upload.scan.binary', 'clamdscan');
        if (self::which($binary) === null) {
            // Try the non-daemon fallback before failing.
            $fallback = 'clamscan';
            if ($binary !== $fallback && self::which($fallback) !== null) {
                $binary = $fallback;
            } else {
                return self::fail("scanner binary '{$binary}' not found");
            }
        }

        // clamdscan/clamscan: exit 0 = clean, 1 = infected, 2 = error.
        $cmd = escapeshellcmd($binary).' --no-summary '.escapeshellarg($path).' 2>&1';
        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);

        return match ($exitCode) {
            0 => ['ok' => true, 'result' => self::CLEAN, 'detail' => 'clean'],
            1 => ['ok' => false, 'result' => self::INFECTED, 'detail' => 'malware detected'],
            default => self::fail('scanner exit '.$exitCode),
        };
    }

    private static function fail(string $detail): array
    {
        // Fail-closed. Log the operational reason (never file content).
        Log::warning('upload virus scan failed-closed', ['detail' => $detail]);

        return ['ok' => false, 'result' => self::UNAVAILABLE, 'detail' => $detail];
    }

    private static function which(string $binary): ?string
    {
        $probe = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'command -v';
        $out = [];
        $code = 1;
        exec($probe.' '.escapeshellarg($binary).' 2>&1', $out, $code);

        return $code === 0 && ! empty($out) ? trim($out[0]) : null;
    }
}
