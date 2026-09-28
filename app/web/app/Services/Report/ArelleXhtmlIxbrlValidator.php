<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Cache;

class ArelleXhtmlIxbrlValidator
{
    private const TIMEOUT_SECONDS = 120;

    private const MAX_OUTPUT_BYTES = 1048576;

    private const LOCK_NAME = 'i4s:report:arelle-validation';

    public function __construct(
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        private readonly int $maxOutputBytes = self::MAX_OUTPUT_BYTES,
    ) {}

    /**
     * @param array<string, mixed> $internalManifest
     */
    public function validate(string $xhtml, ReportingProfile $profile, array $internalManifest): void
    {
        $command = config('services.report.arelle_command');
        if (! is_string($command) || trim($command) === '' || ! str_starts_with($command, '/') || ! is_file($command) || ! is_executable($command)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_unavailable');
        }

        $packagePath = $internalManifest['taxonomy_package_path'] ?? null;
        if (! is_string($packagePath) || $packagePath === '') {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }

        $lock = Cache::lock(self::LOCK_NAME, max(10, $this->timeoutSeconds + 10));
        if (! $lock->get()) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_busy');
        }

        try {
            $tmpDir = $this->temporaryDirectory();
            $xhtmlPath = $tmpDir.'/candidate.xhtml';

            try {
                file_put_contents($xhtmlPath, $xhtml, LOCK_EX);
                chmod($xhtmlPath, 0600);

                $args = [
                    $command,
                    ...$this->profileArguments($profile),
                    '--validationExitCode',
                    '--packages',
                    $packagePath,
                    '--file',
                    $xhtmlPath,
                ];

                $result = $this->run($args);
                if ($result['exit_code'] !== 0 || ! $this->outputIsCleanAndAnalyzable($result['stdout']."\n".$result['stderr'])) {
                    throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
                }
            } finally {
                if (is_file($xhtmlPath)) {
                    @unlink($xhtmlPath);
                }
                if (is_dir($tmpDir)) {
                    @rmdir($tmpDir);
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private function profileArguments(ReportingProfile $profile): array
    {
        $args = $profile->validationConfig()['arelle']['arguments'] ?? [];

        return is_array($args) ? array_values(array_filter($args, 'is_string')) : [];
    }

    private function temporaryDirectory(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/').'/i4s-arelle-'.bin2hex(random_bytes(12));
        if (! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }
        chmod($base, 0700);

        return $base;
    }

    /**
     * @param list<string> $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function run(array $args): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($args, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $this->timeoutSeconds);
        $exitCode = null;
        $timedOut = false;
        $outputExceeded = false;

        try {
            while (true) {
                if (! $this->readProcessOutput($pipes, $stdout, $stderr)) {
                    $this->terminate($process);
                    $outputExceeded = true;
                    break;
                }
                $status = proc_get_status($process);
                if (($status['exitcode'] ?? -1) !== -1) {
                    $exitCode = (int) $status['exitcode'];
                }

                if (! ($status['running'] ?? false)) {
                    break;
                }

                if (microtime(true) > $deadline) {
                    $this->terminate($process);
                    $timedOut = true;
                    break;
                }

                usleep(10000);
            }

            if (! $outputExceeded && ! $this->readProcessOutput($pipes, $stdout, $stderr)) {
                $outputExceeded = true;
            }
        } finally {
            foreach ([1, 2] as $pipe) {
                if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                    fclose($pipes[$pipe]);
                }
            }
        }

        $closedExitCode = proc_close($process);
        if ($exitCode === null && $closedExitCode !== -1) {
            $exitCode = $closedExitCode;
        }

        if ($timedOut || $outputExceeded) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }

        return ['exit_code' => $exitCode ?? -1, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function readProcessOutput(array $pipes, string &$stdout, string &$stderr): bool
    {
        $stdoutChunk = stream_get_contents($pipes[1]) ?: '';
        $stderrChunk = stream_get_contents($pipes[2]) ?: '';

        if (strlen($stdout) + strlen($stderr) + strlen($stdoutChunk) + strlen($stderrChunk) > max(1, $this->maxOutputBytes)) {
            return false;
        }

        $stdout .= $stdoutChunk;
        $stderr .= $stderrChunk;

        return true;
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        proc_terminate($process);
        usleep(100000);
        $status = proc_get_status($process);
        if ($status['running'] ?? false) {
            proc_terminate($process, 9);
        }
    }

    private function outputIsCleanAndAnalyzable(string $output): bool
    {
        if (trim($output) === '') {
            return false;
        }

        if (preg_match('/\b(?:error|fatal|invalid|fail(?:ed|ure)?|unsuccessful)\b/i', $output)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:validation\s+(?:successful(?:ly)?|passed)|successfully\s+validated|validated\s+in\s+\d+(?:[.,]\d+)?\s+secs?)\b/i',
            $output,
        );
    }
}
