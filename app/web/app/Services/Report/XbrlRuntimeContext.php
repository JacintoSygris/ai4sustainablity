<?php

namespace App\Services\Report;

use App\Models\ReportingFact;
use RuntimeException;

/** Server-selected, checksum-bound inventory. No network taxonomy resolution. */
class XbrlRuntimeContext
{
    /** Closed Set 1 contexts; training priors do not define canonical membership. */
    public static function supportedContext(array $indicator): bool
    {
        $counts = ['E1' => 9, 'E2' => 6, 'E3' => 5, 'E4' => 6, 'E5' => 6,
            'S1' => 17, 'S2' => 5, 'S3' => 5, 'S4' => 5, 'G1' => 6];
        $standard = $indicator['standard'] ?? null;
        $dr = $indicator['dr'] ?? null;
        if ($standard !== null && $standard !== 'ESRS 2' && ! isset($counts[$standard])) return false;
        if ($dr === null) return true;
        $general = ['BP-1', 'BP-2', 'GOV-1', 'GOV-2', 'GOV-3', 'GOV-4', 'GOV-5',
            'SBM-1', 'SBM-2', 'SBM-3', 'IRO-1', 'IRO-2', 'MDR-P', 'MDR-A', 'MDR-M', 'MDR-T'];
        if (in_array($dr, $general, true)) return $standard === null || $standard === 'ESRS 2';
        if (! preg_match('/\A(E[1-5]|S[1-4]|G1)-([1-9][0-9]?)\z/', $dr, $matches)) return false;
        return (int) $matches[2] <= $counts[$matches[1]] && ($standard === null || $standard === $matches[1]);
    }

    public function capture(): array
    {
        $profile = app(ReportingProfileRepository::class)->load(ReportingFact::PROFILE_ID);
        $registry = app(TaxonomyProfileRegistry::class);
        $taxonomy = $registry->candidate($registry->selectedId(), $profile);
        $map = app(XbrlConceptMap::class)->capture(false, $taxonomy['concept_mapping'])->frozen();
        $manifestBytes = self::read((string) config('xbrl_suggestions.runtime_manifest_path'), 65536);
        $manifest = json_decode($manifestBytes, true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 'xbrl-runtime-identification-v1'
            || ($manifest['taxonomy_profile'] ?? null) !== $taxonomy['id']
            || ($manifest['inputs']['concept_map_sha256'] ?? null) !== $map['sha256']
            || ($manifest['inputs']['ig3_sha256'] ?? null) !== hash_file('sha256', base_path('data/esrs_datapoints_ig3.json'))
            || ! is_string($manifest['model_version'] ?? null)
            || ! preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/', $manifest['model_version'])) {
            throw new RuntimeException('xbrl_runtime_manifest_invalid');
        }
        if (! is_array($manifest['languages'] ?? null) || $manifest['languages'] === [] || array_diff($manifest['languages'], ['en', 'es']) !== []) throw new RuntimeException('xbrl_runtime_manifest_invalid');
        foreach (['min_score', 'min_margin'] as $key) {
            $threshold = $manifest['thresholds'][$key] ?? null;
            if ((! is_float($threshold) && ! is_int($threshold)) || ! is_finite((float) $threshold) || $threshold < 0 || $threshold > 1) throw new RuntimeException('xbrl_runtime_manifest_invalid');
        }
        $catalogHash = $manifest['catalog_sha256'] ?? null;
        if (! is_string($catalogHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $catalogHash)) throw new RuntimeException('xbrl_runtime_manifest_invalid');
        $inventory = [];
        foreach ($map['concepts'] as $entry) {
            if (is_string($entry['concept_id'] ?? null)) $inventory[$entry['concept_id']] = true;
        }
        $metadata = [];
        $package = null;
        $configured = trim((string) config('services.report.external_taxonomy_manifest_path')) !== '';
        if ($configured) {
            $external = app(ExternalTaxonomyManifestRepository::class)->loadInternalForProfile($profile);
            $metadata = app(TaxonomyConceptMetadata::class)->load($taxonomy, $external);
            $package = $external['taxonomy_package_checksum'];
        }
        $catalogPath = (string) config('xbrl_suggestions.runtime_catalog_path');
        if ($catalogPath !== '') {
            if (! $configured) throw new RuntimeException('xbrl_runtime_catalog_requires_taxonomy');
            $bytes = self::read($catalogPath, 16777216);
            $catalog = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            if (hash('sha256', $bytes) !== $catalogHash || ($catalog['package_sha256'] ?? null) !== $package
                || ($catalog['taxonomy_profile'] ?? null) !== $taxonomy['id']
                || ($catalog['schema_version'] ?? null) !== 'xbrl-runtime-identification-v1'
                || ! is_array($catalog['concepts'] ?? null) || count($catalog['concepts']) > 20000) {
                throw new RuntimeException('xbrl_runtime_catalog_invalid');
            }
            foreach ($catalog['concepts'] as $qname => $entry) {
                if (! is_string($qname) || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9_]*\z/', $qname) || ! isset($metadata[$qname])) {
                    throw new RuntimeException('xbrl_runtime_catalog_invalid');
                }
                foreach (['type', 'period_type', 'abstract'] as $key) {
                    if (($entry[$key] ?? null) !== $metadata[$qname][$key]) throw new RuntimeException('xbrl_runtime_catalog_invalid');
                }
                $inventory[$qname] = true;
            }
        } elseif ($catalogHash !== $map['sha256'] || ($manifest['package_sha256'] ?? null) !== null) {
            throw new RuntimeException('xbrl_runtime_catalog_missing');
        }
        if ($catalogPath !== '' && ($manifest['package_sha256'] ?? null) !== $package) throw new RuntimeException('xbrl_runtime_package_mismatch');
        return ['identity' => [
            'model_version' => $manifest['model_version'], 'artifact_sha256' => hash('sha256', $manifestBytes),
            'catalog_sha256' => $catalogHash, 'concept_map_sha256' => $map['sha256'],
            'taxonomy_profile' => $taxonomy['id'], 'package_sha256' => $manifest['package_sha256'] ?? null,
        ], 'inventory' => $inventory, 'metadata' => $metadata, 'verified_taxonomy' => $configured,
            'validation_package_sha256' => $package, 'thresholds' => $manifest['thresholds'], 'languages' => $manifest['languages']];
    }

    public static function read(string $path, int $limit): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) throw new RuntimeException('xbrl_runtime_file_unavailable');
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('xbrl_runtime_file_unavailable');
        try { $bytes = stream_get_contents($handle, $limit + 1); } finally { fclose($handle); }
        if ($bytes === false || strlen($bytes) > $limit) throw new RuntimeException('xbrl_runtime_file_size');
        return $bytes;
    }

    public static function compatibility(?array $metadata, array $input): array
    {
        if ($metadata === null) return ['map_only', ['taxonomy_unconfigured']];
        if ($metadata['abstract']) return ['incompatible', ['abstract_concept']];
        if (($input['period_type'] ?? null) !== null && $input['period_type'] !== $metadata['period_type']) return ['incompatible', ['period_mismatch']];
        $types = ['stringItemType' => 'text', 'textBlockItemType' => 'text', 'booleanItemType' => 'boolean', 'dateItemType' => 'date',
            'monetaryItemType' => 'monetary', 'integerItemType' => 'integer', 'decimalItemType' => 'number', 'pureItemType' => 'number', 'percentItemType' => 'number'];
        $parts = explode('}', $metadata['type'], 2);
        $kind = in_array($parts[0], ['{http://www.xbrl.org/2003/instance', '{http://www.xbrl.org/dtr/type/non-numeric', '{http://www.xbrl.org/dtr/type/numeric'], true)
            ? ($types[$parts[1] ?? ''] ?? null) : null;
        if ($kind === null || empty($input['value_type']) || empty($input['period_type'])) return ['unverified', ['type_or_context_unverified']];
        if ($kind !== $input['value_type']) return ['incompatible', ['type_mismatch']];
        $unit = $input['unit'] ?? null;
        if ($kind === 'monetary' && (! is_string($unit) || ! preg_match('/\A[A-Z]{3}\z/', $unit))) return ['unverified', ['unit_unverified']];
        if (in_array($kind, ['number', 'integer'], true) && $unit !== 'pure') return ['unverified', ['unit_unverified']];
        if (in_array($kind, ['text', 'boolean', 'date'], true) && $unit) return ['incompatible', ['unit_mismatch']];
        return ['verified_taxonomy', []];
    }
}
