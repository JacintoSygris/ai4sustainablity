<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportSnapshot;
use App\Services\Ar16MatterDrMappingRepository;
use App\Services\EsrsDatapointCorpusBuilder;
use stdClass;
use Throwable;

/** A request-local immutable capture. Hashes and scope consume these exact decoded inputs. */
final class ReportScopeAssets
{
    private static ?string $corpusKey = null;
    private static ?array $cachedCorpus = null;

    private function __construct(
        public readonly array $corpus,
        public readonly array $mapping,
        public readonly string $corpusSha256,
        public readonly string $mappingSha256,
    ) {}

    public static function capture(): self
    {
        try {
            [$corpus, $corpusHash] = self::corpusInput();
            $decoded = self::readJson(config('services.esrs_datapoints.matter_dr_mapping_path'));
            $payload = self::toArray($decoded);
            if (! is_array($payload) || ($payload['source']['status'] ?? null) !== 'approved'
                || ! is_array($payload['mappings'] ?? null) || ! array_is_list($payload['mappings']) || $payload['mappings'] === []) {
                throw new ReportScopeException('scope_assets_unavailable');
            }
            if (isset($payload['version']) && ! is_string($payload['version'])) throw new ReportScopeException('scope_assets_unavailable');
            foreach (['name', 'approved_at'] as $key) {
                if (isset($payload['source'][$key]) && ! is_string($payload['source'][$key])) throw new ReportScopeException('scope_assets_unavailable');
            }
            $seen = [];
            foreach ($payload['mappings'] as $row) {
                if (! is_array($row) || ! is_int($row['ar16_topic_id'] ?? null) || $row['ar16_topic_id'] <= 0
                    || isset($seen[$row['ar16_topic_id']]) || ! is_string($row['esrs_code'] ?? null) || trim($row['esrs_code']) === ''
                    || ! is_array($row['disclosure_requirements'] ?? null) || ! array_is_list($row['disclosure_requirements']) || $row['disclosure_requirements'] === []) {
                    throw new ReportScopeException('scope_assets_unavailable');
                }
                foreach ($row['disclosure_requirements'] as $dr) {
                    if (! is_string($dr) || trim($dr) === '') throw new ReportScopeException('scope_assets_unavailable');
                }
                $seen[$row['ar16_topic_id']] = true;
            }
            $mapping = (new Ar16MatterDrMappingRepository)->normalize($payload);
            if ($mapping === null) throw new ReportScopeException('scope_assets_unavailable');
            return new self($corpus, $mapping, $corpusHash, self::hash($decoded));
        } catch (Throwable) {
            // Neither paths nor parser messages cross the application boundary.
            throw new ReportScopeException('scope_assets_unavailable');
        }
    }

    public static function readCorpus(): array
    {
        return self::corpusInput()[0];
    }

    private static function corpusInput(): array
    {
        try {
            // Read every time, even in a worker. The bounded cache only avoids repeated validation.
            $decoded = self::readJson(config('report_scope.corpus_path', base_path('data/esrs_datapoints_ig3.json')));
            $hash = self::hash($decoded);
            if (self::$corpusKey !== $hash || self::$cachedCorpus === null) {
                $corpus = self::toArray($decoded);
                if (! is_array($corpus) || ! is_array($corpus['datapoints'] ?? null)
                    || ! array_is_list($corpus['datapoints']) || $corpus['datapoints'] === []) {
                    throw new ReportScopeException('scope_assets_unavailable');
                }
                foreach (['name', 'url', 'sha256', 'workbook_version', 'downloaded_at', 'note'] as $key) {
                    if (! is_string($corpus['source'][$key] ?? null)) throw new ReportScopeException('scope_assets_unavailable');
                }
                $seen = [];
                foreach ($corpus['datapoints'] as $row) {
                    if (! is_array($row)) throw new ReportScopeException('scope_assets_unavailable');
                    foreach (['id', 'esrs', 'name', 'inclusion_type'] as $key) {
                        if (! is_string($row[$key] ?? null) || trim($row[$key]) === '') throw new ReportScopeException('scope_assets_unavailable');
                    }
                    foreach (['data_type', 'dr', 'paragraph', 'related_ar', 'conditional_or_alternative', 'appendix_b', 'phase_in_less_than_750', 'phase_in_all'] as $key) {
                        if (! array_key_exists($key, $row) || (! is_null($row[$key]) && ! is_string($row[$key]))) throw new ReportScopeException('scope_assets_unavailable');
                    }
                    if (! is_bool($row['may_disclose'] ?? null) || isset($seen[$row['id']])
                        || ! in_array($row['inclusion_type'], ['always_required', 'materiality_based', 'minimum_disclosure_requirement'], true)) {
                        throw new ReportScopeException('scope_assets_unavailable');
                    }
                    $seen[$row['id']] = true;
                }
                self::$cachedCorpus = $corpus;
                self::$corpusKey = $hash;
            }
            return [self::$cachedCorpus, $hash];
        } catch (Throwable) {
            throw new ReportScopeException('scope_assets_unavailable');
        }
    }

    private static function readJson(mixed $path): stdClass
    {
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) throw new ReportScopeException('scope_assets_unavailable');
        $bytes = @file_get_contents($path);
        if ($bytes === false) throw new ReportScopeException('scope_assets_unavailable');
        $decoded = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof stdClass) throw new ReportScopeException('scope_assets_unavailable');
        return $decoded;
    }

    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) $value = get_object_vars($value);
        return is_array($value) ? array_map(self::toArray(...), $value) : $value;
    }

    /** Object keys sorted recursively; list order, empty objects and numeric types preserved. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $keys = get_object_vars($value);
            ksort($keys);
            return (object) array_map(self::canonicalize(...), $keys);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) ksort($value);
            return array_map(self::canonicalize(...), $value);
        }
        return $value;
    }

    public function manifest(array $scope, int $generation = 0): array
    {
        $topics = $scope['material_topic_ids'];
        sort($topics, SORT_NUMERIC);
        return [
            'version' => 'report_scope_v1',
            'corpus_sha256' => $this->corpusSha256,
            'mapping_sha256' => $this->mappingSha256,
            'mapping_snapshot_digest' => $scope['mapping_snapshot_digest'],
            'scope_sha256' => self::hash([
                'topic_ids' => $topics,
                'datapoint_ids' => (new ReportContentScope)->datapointIds($scope),
                'coverage_status' => $scope['generation']['coverage_status'],
            ]),
            'generation' => $generation,
        ];
    }

    public static function assertManifest(mixed $scope): void
    {
        $keys = is_array($scope) ? array_keys($scope) : [];
        sort($keys);
        if ($keys !== ['corpus_sha256', 'generation', 'mapping_sha256', 'mapping_snapshot_digest', 'scope_sha256', 'version']
            || $scope['version'] !== 'report_scope_v1' || ! is_int($scope['generation']) || $scope['generation'] < 0) {
            throw new ReportScopeException('approved_snapshot_invalid');
        }
        foreach (['corpus_sha256', 'mapping_sha256', 'mapping_snapshot_digest', 'scope_sha256'] as $key) {
            if (! is_string($scope[$key]) || preg_match('/\A[a-f0-9]{64}\z/', $scope[$key]) !== 1) throw new ReportScopeException('approved_snapshot_invalid');
        }
    }

    public static function required(ReportSnapshot $snapshot): bool
    {
        return config('report_scope.enabled', false) || ($snapshot->snapshot_json['schema_version'] ?? null) === 'report_snapshot_v2';
    }

    /** Caller must validate both frozen manifest copies before comparing these identities. */
    public function differences(array $frozen, array $scope): array
    {
        self::assertManifest($frozen);
        $live = $this->manifest($scope);
        $reasons = [];
        foreach (['corpus_sha256' => 'ig3_corpus_changed', 'mapping_sha256' => 'ar16_dr_mapping_changed', 'scope_sha256' => 'report_scope_changed'] as $key => $reason) {
            if (! hash_equals($frozen[$key], $live[$key])) $reasons[] = $reason;
        }
        if (! hash_equals($frozen['mapping_snapshot_digest'], $live['mapping_snapshot_digest']) && ! in_array('ar16_dr_mapping_changed', $reasons, true)) $reasons[] = 'ar16_dr_mapping_changed';
        return $reasons;
    }
}
