<?php

namespace App\Services\Report;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ArelleIxbrlCandidateValidator
{
    private const DEFAULT_BINARY = '/opt/arelle/bin/arelleCmdLine';
    private const OUTPUT_LIMIT = 20000;
    private const TIMEOUT_SECONDS = 45;

    public function __construct(
        private readonly EsrsTaxonomyPackageRepository $packages,
        private readonly XbrlCountryTaxonomyPackage $countryPackage,
        private readonly XbrlCodelistCommonTaxonomyPackage $codelistPackage,
    ) {}

    /**
     * @return array{status: string, reason_codes: list<string>, diagnostics: list<string>}
     */
    public function validate(IxbrlCandidateDocument $document): array
    {
        $binary = (string) config('services.arelle.binary', self::DEFAULT_BINARY);
        if ($binary === '' || ! is_file($binary) || ! is_executable($binary)) {
            return $this->failed(['arelle_binary_missing']);
        }

        try {
            $countryZip = $this->countryPackage->verifiedPath();
            $codelistZip = $this->codelistPackage->verifiedPath();
            $esrsPackage = $this->packages->verified(EsrsConceptResolver::TAXONOMY_VERSION);
        } catch (Throwable) {
            return $this->failed(['arelle_taxonomy_package_integrity_failed']);
        }

        $workDir = $this->makeWorkDir();
        try {
            $candidatePath = $workDir.'/candidate.xhtml';
            if (file_put_contents($candidatePath, $document->bytes) === false) {
                return $this->failed(['arelle_workdir_failed']);
            }

            $taxonomyTarget = $workDir.'/taxonomies/'.EsrsConceptResolver::TAXONOMY_VERSION;
            if (! is_dir(dirname($taxonomyTarget)) && ! mkdir(dirname($taxonomyTarget), 0700, true)) {
                return $this->failed(['arelle_workdir_failed']);
            }
            if (! symlink(dirname(dirname($esrsPackage->catalogPath())), $taxonomyTarget)) {
                return $this->failed(['arelle_workdir_failed']);
            }

            $process = new Process([
                $binary,
                '--file', $candidatePath,
                '--validate',
                '--validationExitCode',
                '--internetConnectivity', 'offline',
                '--formula=none',
                '--package', $countryZip,
                '--package', $codelistZip,
            ], $workDir);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            $output = $this->boundedOutput($process->getOutput()."\n".$process->getErrorOutput());
            if (! $process->isSuccessful()) {
                return $this->failed(['arelle_validation_failed'], $this->diagnostics($output));
            }

            return [
                'status' => 'passed',
                'reason_codes' => [],
                'diagnostics' => $this->diagnostics($output),
            ];
        } catch (ProcessTimedOutException) {
            return $this->failed(['arelle_validation_timeout']);
        } catch (Throwable) {
            return $this->failed(['arelle_validation_failed']);
        } finally {
            $this->removeTree($workDir);
        }
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $diagnostics
     * @return array{status: string, reason_codes: list<string>, diagnostics: list<string>}
     */
    private function failed(array $reasonCodes, array $diagnostics = []): array
    {
        return [
            'status' => 'failed',
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'diagnostics' => $diagnostics,
        ];
    }

    private function makeWorkDir(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/').'/ixbrl-arelle-'.bin2hex(random_bytes(8));
        if (! mkdir($root, 0700, true)) {
            throw new RuntimeException('arelle_workdir_failed');
        }

        return $root;
    }

    private function boundedOutput(string $output): string
    {
        return substr($output, 0, self::OUTPUT_LIMIT);
    }

    /** @return list<string> */
    private function diagnostics(string $output): array
    {
        preg_match_all('/\\b(?:[A-Za-z][A-Za-z0-9_.-]*:)?[A-Za-z][A-Za-z0-9_.-]{2,}\\b/', $output, $matches);

        return collect($matches[0] ?? [])
            ->filter(fn (string $token): bool => strlen($token) <= 120)
            ->reject(fn (string $token): bool => str_contains($token, '/') || str_contains($token, '\\'))
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.'/'.$item;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
