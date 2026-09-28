<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fail-closed virus scan for public uploads via a direct `clamscan` process.
 *
 * Config-gated (services.p6_document_upload.scan). Default DISABLED so local/CI
 * stay green. When enabled in production:
 *   - a clean scan -> allowed;
 *   - an infected file -> rejected;
 *   - the scanner missing/erroring -> REJECTED (fail-closed): a public upload
 *     surface must never silently accept unscanned files.
 *
 * Enable with SCAN env + clamav installed on the VPS. Daemon mode is rejected
 * because killing its client cannot bound daemon-side work.
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
            if (app()->environment('production')) {
                return self::fail('scanner disabled in production');
            }

            return ['ok' => true, 'result' => self::CLEAN, 'detail' => 'scan disabled'];
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return self::fail('unreadable upload path');
        }

        $binary = trim((string) config('services.p6_document_upload.scan.binary', 'clamscan'));
        if ($binary === '') {
            $binary = 'clamscan';
        }
        $executable = str_contains($binary, DIRECTORY_SEPARATOR)
            && is_file($binary)
            && is_executable($binary)
                ? realpath($binary)
                : (new ExecutableFinder)->find($binary);
        if ($executable === null) {
            return self::fail("scanner binary '{$binary}' not found");
        }

        if (basename((string) $executable) !== 'clamscan') {
            return self::fail('only the bounded clamscan executable is supported');
        }

        $setsid = (new ExecutableFinder)->find('setsid');
        if ($setsid === null || ! function_exists('posix_kill')) {
            return self::fail('scanner process-group controls are unavailable');
        }

        // Direct clamscan: exit 0 = clean, 1 = infected/limit alert, 2 = error.
        $timeout = max(0.1, min(
            60.0,
            (float) config('services.p6_document_upload.scan.timeout_seconds', 30)
        ));
        $maxBytes = max(1, (int) config('services.p6_document_upload.max_bytes', 50 * 1024 * 1024));
        $maxMegabytes = max(1, (int) ceil($maxBytes / 1024 / 1024));
        $process = new Process([
            $setsid,
            $executable,
            '--no-summary',
            '--alert-exceeds-max=yes',
            "--max-filesize={$maxMegabytes}M",
            "--max-scansize={$maxMegabytes}M",
            $path,
        ]);
        $process->setTimeout(null);

        try {
            $process->start();
            $pid = $process->getPid();
            $deadline = microtime(true) + $timeout;
            while ($process->isRunning()) {
                if (microtime(true) >= $deadline) {
                    self::terminateProcessGroup($process, $pid);

                    return self::fail('scanner timed out');
                }
                usleep(10_000);
            }
        } catch (Throwable $exception) {
            return self::fail('scanner could not start: '.$exception::class);
        }

        $exitCode = $process->getExitCode();

        return match ($exitCode) {
            0 => ['ok' => true, 'result' => self::CLEAN, 'detail' => 'clean'],
            1 => ['ok' => false, 'result' => self::INFECTED, 'detail' => 'malware detected'],
            default => self::fail('scanner exit '.($exitCode ?? 'unknown')),
        };
    }

    private static function fail(string $detail): array
    {
        // Fail-closed. Log the operational reason (never file content).
        Log::warning('upload virus scan failed-closed', ['detail' => $detail]);

        return ['ok' => false, 'result' => self::UNAVAILABLE, 'detail' => $detail];
    }

    private static function terminateProcessGroup(Process $process, ?int $pid): void
    {
        if ($pid === null || $pid <= 0) {
            $process->stop(0, 9);

            return;
        }

        // Let the group leader reap children where Linux exposes its process tree.
        foreach (array_reverse(self::descendantPids($pid)) as $childPid) {
            @posix_kill($childPid, 15);
        }
        $reapDeadline = microtime(true) + 0.5;
        while ($process->isRunning() && microtime(true) < $reapDeadline) {
            usleep(10_000);
        }

        if ($process->isRunning()) {
            @posix_kill(-$pid, 15);
            usleep(50_000);
        }
        if ($process->isRunning()) {
            @posix_kill(-$pid, 9);
        }
        $process->stop(0, 9);
    }

    /** @return list<int> */
    private static function descendantPids(int $pid): array
    {
        $childrenFile = "/proc/{$pid}/task/{$pid}/children";
        $raw = @file_get_contents($childrenFile);
        if (! is_string($raw)) {
            return [];
        }

        $descendants = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $child) {
            $childPid = filter_var($child, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($childPid === false) {
                continue;
            }
            $descendants[] = $childPid;
            array_push($descendants, ...self::descendantPids($childPid));
        }

        return array_values(array_unique($descendants));
    }
}
