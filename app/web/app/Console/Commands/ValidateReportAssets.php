<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class ValidateReportAssets extends Command
{
    protected $signature = 'report:validate-assets';

    protected $description = 'Validate the vendored assets required by the P10 report package.';

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

        if ($failures !== []) {
            foreach ($failures as $f) {
                $this->error($f);
            }

            return self::FAILURE;
        }

        $this->info('report:validate-assets OK');

        return self::SUCCESS;
    }
}
