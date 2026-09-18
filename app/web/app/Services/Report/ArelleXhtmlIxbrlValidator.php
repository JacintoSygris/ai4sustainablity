<?php

namespace App\Services\Report;

class ArelleXhtmlIxbrlValidator
{
    private const TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
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

        $tmpDir = $this->temporaryDirectory();
        $xhtmlPath = $tmpDir.'/candidate.xhtml';

        try {
            file_put_contents($xhtmlPath, $xhtml, LOCK_EX);
            chmod($xhtmlPath, 0600);

            $args = [
                $command,
                ...$this->profileArguments($profile),
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

        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                $status = proc_get_status($process);
                if (($status['exitcode'] ?? -1) !== -1) {
                    $exitCode = (int) $status['exitcode'];
                }

                if (! ($status['running'] ?? false)) {
                    break;
                }

                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    $timedOut = true;
                    break;
                }

                usleep(10000);
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
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

        if ($timedOut) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed');
        }

        return ['exit_code' => $exitCode ?? -1, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function outputIsCleanAndAnalyzable(string $output): bool
    {
        if (trim($output) === '') {
            return false;
        }

        if (preg_match('/\b(?:error|fatal)\b/i', $output)) {
            return false;
        }

        return (bool) preg_match('/\b(?:info|warning|valid|validation|success|successful|passed)\b/i', $output);
    }
}
