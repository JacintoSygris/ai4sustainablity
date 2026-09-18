<?php

namespace App\Console\Commands;

use App\Services\Report\ReportingProfileException;
use App\Services\Report\ReportingProfileRepository;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class ValidateReportAssets extends Command
{
    protected $signature = 'report:validate-assets';

    protected $description = 'Hard release gates for the P10 guided-report assets and external taxonomy contract.';

    private const BANNED = ['iXBRL-ready', 'iXBRL ready', 'filing-ready', 'filing ready', 'official filing'];

    private const ASSETS = [
        'data/atomizer_xbrl_concepts_v1.json',
        'data/atomizer_support_rules_v1.json',
        'data/related_dr_map_esrs2023_v1.json',
    ];

    private const BANNED_TERM_SCAN_ASSETS = [
        ...self::ASSETS,
        'data/esrs_datapoints_ig3.json',
    ];

    public function handle(): int
    {
        $failures = [];

        // Gate 1: checksum integrity.
        foreach (self::ASSETS as $asset) {
            $file = base_path($asset);
            $sha = base_path($asset.'.sha256');
            if (! is_file($file) || ! is_file($sha)) {
                $failures[] = "missing_asset:{$asset}";

                continue;
            }
            if (hash('sha256', file_get_contents($file)) !== trim(file_get_contents($sha))) {
                $failures[] = "checksum_mismatch:{$asset}";
            }
        }

        // Gate 2: banned-term scan across vendored text.
        foreach (self::BANNED_TERM_SCAN_ASSETS as $asset) {
            $file = base_path($asset);
            if (! is_file($file)) {
                continue;
            }
            $text = file_get_contents($file);
            foreach (self::BANNED as $term) {
                if (stripos($text, $term) !== false) {
                    $failures[] = "banned_term:{$term}:{$asset}";
                }
            }
        }

        // Gate 3: 1:1 ID contract — every IG-3 datapoint id resolves in the concept map (mapped or explicit unmapped).
        $datapoints = null;

        try {
            $ig3 = json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($ig3) && is_array($ig3['datapoints'] ?? null) && $ig3['datapoints'] !== []) {
                $datapoints = $ig3['datapoints'];
            }
        } catch (Throwable) {
            // Fall through: $datapoints stays null, reported as malformed_ig3_data below.
        }

        if ($datapoints === null) {
            $failures[] = 'malformed_ig3_data';
        }

        $concepts = null;

        try {
            $conceptMap = json_decode(file_get_contents(base_path('data/atomizer_xbrl_concepts_v1.json')), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($conceptMap) && is_array($conceptMap['concepts'] ?? null)) {
                $concepts = $conceptMap['concepts'];
            }
        } catch (Throwable) {
            // Fall through: $concepts stays null, reported as malformed_concept_map below.
        }

        if ($concepts === null) {
            $failures[] = 'malformed_concept_map';
        }

        if ($datapoints !== null && $concepts !== null) {
            foreach ($datapoints as $dp) {
                if (! array_key_exists($dp['id'], $concepts)) {
                    $failures[] = "unreconciled_datapoint:{$dp['id']}";
                }
            }
        }

        // Gate 4: reporting profile contract and external taxonomy provisioning boundary.
        try {
            $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');

            if (($profile->taxonomy()['provisioning_mode'] ?? null) !== 'external_package_required') {
                $failures[] = 'invalid_profile_provisioning_mode';
            }

            if (! $profile->requiresExternalTaxonomyPackage()) {
                $failures[] = 'invalid_profile_provisioning_mode';
            }

            if (! $profile->supportsValidatedCandidate() || $profile->supportsFilingReady()) {
                $failures[] = 'invalid_profile_publication_states';
            }
        } catch (ReportingProfileException $e) {
            $failures[] = $e->getMessage();
        }

        // Gate 5: never vendor EFRAG ESRS taxonomy package/schema material under data.
        foreach ($this->forbiddenTaxonomyArtifacts() as $artifact) {
            $failures[] = "taxonomy_repo_artifact_forbidden:{$artifact}";
        }

        if ($failures !== []) {
            foreach ($failures as $f) {
                $this->error($f);
            }

            return self::FAILURE;
        }

        $this->info('report:validate-assets OK');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function forbiddenTaxonomyArtifacts(): array
    {
        $root = base_path('data');
        if (! is_dir($root)) {
            return [];
        }

        $forbidden = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = str_replace(base_path().'/', '', $file->getPathname());
            $normalized = str_replace('\\', '/', strtolower($relativePath));

            if (! str_contains($normalized, 'esrs-set1-2024')
                && ! str_contains($normalized, 'xbrl.efrag.org')
                && ! preg_match('/(^|\/)esrs[^\/]*\.(zip|xsd|xbrl)$/', $normalized)) {
                continue;
            }

            $forbidden[] = $relativePath;
        }

        return $forbidden;
    }
}
