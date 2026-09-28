<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReportSnapshotBuilder
{
    public const SNAPSHOT_SCHEMA_VERSION = 'report_snapshot_v1';

    public function __construct(
        private readonly ReportingProfileRepository $profiles,
    ) {}

    /**
     * @param Collection<int, ReportingFact>|iterable<int, ReportingFact>|null $facts
     * @return array<string, mixed>
     */
    public function buildCanonicalState(Characterization $characterization, iterable|null $facts = null): array
    {
        $profile = $this->profiles->load(ReportingFact::PROFILE_ID);
        $canonicalCharacterization = $this->canonicalCharacterization($characterization);
        $canonicalFacts = $this->canonicalFacts($facts ?? $this->persistedFacts($characterization));
        $stalenessFacts = $this->factsForStaleness($canonicalFacts);

        $characterizationHash = $this->hashCanonical($canonicalCharacterization);
        $factsHash = $this->hashCanonical($stalenessFacts);
        $sourceManifest = [
            'profile' => [
                'profile_id' => $profile->profileId(),
                'profile_hash' => $profile->hash(),
            ],
            'characterization' => [
                'characterization_id' => $characterization->id,
                'characterization_hash' => $characterizationHash,
            ],
            'facts' => [
                'fact_count' => count($canonicalFacts),
                'fact_ids' => array_map(fn (array $fact): string => $fact['fact_id'], $canonicalFacts),
                'facts_hash' => $factsHash,
            ],
        ];

        $frozenState = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'profile' => [
                'profile_id' => $profile->profileId(),
                'profile_hash' => $profile->hash(),
            ],
            'characterization' => $canonicalCharacterization,
            'facts' => $canonicalFacts,
            'source_manifest' => $sourceManifest,
        ];

        return $frozenState + [
            'profile_hash' => $profile->hash(),
            'facts_hash' => $factsHash,
            'characterization_hash' => $characterizationHash,
            'snapshot_hash' => $this->hashCanonical($frozenState),
        ];
    }

    public function assertFrozenSnapshotIntegrity(ReportSnapshot $snapshot): void
    {
        $payload = $snapshot->snapshot_json;
        if (! is_array($payload)) {
            throw new DomainException('report_snapshot_payload_invalid');
        }

        $expectedKeys = ['characterization', 'facts', 'profile', 'schema_version', 'snapshot_hash', 'source_manifest'];
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys || ($payload['schema_version'] ?? null) !== self::SNAPSHOT_SCHEMA_VERSION) {
            throw new DomainException('report_snapshot_payload_schema_mismatch');
        }

        $profile = $payload['profile'] ?? null;
        $characterization = $payload['characterization'] ?? null;
        $facts = $payload['facts'] ?? null;
        $sourceManifest = $payload['source_manifest'] ?? null;
        if (! is_array($profile)
            || ! is_array($characterization)
            || ! is_array($facts)
            || ! array_is_list($facts)
            || ! is_array($sourceManifest)) {
            throw new DomainException('report_snapshot_payload_invalid');
        }

        if (($profile['profile_id'] ?? null) !== $snapshot->profile_id
            || ($profile['profile_hash'] ?? null) !== $snapshot->profile_hash) {
            throw new DomainException('report_snapshot_payload_profile_hash_mismatch');
        }

        if (($characterization['id'] ?? null) !== $snapshot->characterization_id
            || ($characterization['user_id'] ?? null) !== $snapshot->user_id) {
            throw new DomainException('report_snapshot_characterization_identity_mismatch');
        }

        foreach ($facts as $fact) {
            if (! is_array($fact) || ! is_string($fact['fact_id'] ?? null) || $fact['fact_id'] === '') {
                throw new DomainException('report_snapshot_facts_invalid');
            }
        }

        $canonicalCharacterization = $this->canonicalize($characterization);
        $canonicalFacts = $this->canonicalize($facts);
        $characterizationHash = $this->hashCanonical($canonicalCharacterization);
        $factsHash = $this->hashCanonical($canonicalFacts);

        if (! $this->hashMatches($characterizationHash, $snapshot->characterization_hash)) {
            throw new DomainException('report_snapshot_characterization_hash_mismatch');
        }
        if (! $this->hashMatches($factsHash, $snapshot->facts_hash)) {
            throw new DomainException('report_snapshot_facts_hash_mismatch');
        }

        $expectedManifest = [
            'profile' => [
                'profile_id' => $profile['profile_id'],
                'profile_hash' => $profile['profile_hash'],
            ],
            'characterization' => [
                'characterization_id' => $characterization['id'],
                'characterization_hash' => $characterizationHash,
            ],
            'facts' => [
                'fact_count' => count($canonicalFacts),
                'fact_ids' => array_map(fn (array $fact): string => $fact['fact_id'], $canonicalFacts),
                'facts_hash' => $factsHash,
            ],
        ];
        if ($this->canonicalJson($sourceManifest) !== $this->canonicalJson($expectedManifest)
            || $this->canonicalJson($snapshot->source_manifest) !== $this->canonicalJson($expectedManifest)) {
            throw new DomainException('report_snapshot_source_manifest_mismatch');
        }

        $expectedSnapshotHash = $this->hashCanonical([
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'profile' => $this->canonicalize($profile),
            'characterization' => $canonicalCharacterization,
            'facts' => $canonicalFacts,
            'source_manifest' => $this->canonicalize($sourceManifest),
        ]);
        if (! $this->hashMatches($expectedSnapshotHash, $payload['snapshot_hash'] ?? null)
            || ! $this->hashMatches($expectedSnapshotHash, $snapshot->snapshot_hash)) {
            throw new DomainException('report_snapshot_hash_mismatch');
        }
    }

    public function create(Characterization $characterization): ReportSnapshot
    {
        return $this->createOrFind($characterization)['snapshot'];
    }

    /**
     * @return array{snapshot: ReportSnapshot, created: bool}
     */
    public function createOrFind(Characterization $characterization): array
    {
        try {
            return DB::transaction(function () use ($characterization): array {
                $lockedCharacterization = Characterization::query()
                    ->whereKey($characterization->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $state = $this->buildCanonicalState($lockedCharacterization);
                $existing = ReportSnapshot::query()
                    ->where('snapshot_hash', $state['snapshot_hash'])
                    ->first();

                if ($existing) {
                    return ['snapshot' => $existing, 'created' => false];
                }

                $snapshot = ReportSnapshot::create([
                    'user_id' => $lockedCharacterization->user_id,
                    'characterization_id' => $lockedCharacterization->id,
                    'profile_id' => $state['profile']['profile_id'],
                    'profile_hash' => $state['profile_hash'],
                    'facts_hash' => $state['facts_hash'],
                    'characterization_hash' => $state['characterization_hash'],
                    'snapshot_hash' => $state['snapshot_hash'],
                    'source_manifest' => $state['source_manifest'],
                    'snapshot_json' => [
                        'schema_version' => $state['schema_version'],
                        'profile' => $state['profile'],
                        'characterization' => $state['characterization'],
                        'facts' => $state['facts'],
                        'source_manifest' => $state['source_manifest'],
                        'snapshot_hash' => $state['snapshot_hash'],
                    ],
                    'stale_state' => ReportSnapshot::STALE_FRESH,
                    'stale_reasons' => [],
                ]);

                $this->recordEvent('snapshot_created', $snapshot, [
                    'snapshot_id' => $snapshot->id,
                    'characterization_id' => $snapshot->characterization_id,
                    'snapshot_hash' => $snapshot->snapshot_hash,
                    'profile_hash' => $snapshot->profile_hash,
                    'facts_hash' => $snapshot->facts_hash,
                    'fact_count' => count($state['facts']),
                ]);

                return ['snapshot' => $snapshot, 'created' => true];
            });
        } catch (QueryException $exception) {
            $liveCharacterization = Characterization::query()->findOrFail($characterization->id);
            $liveState = $this->buildCanonicalState($liveCharacterization);
            $snapshot = ReportSnapshot::query()->where('snapshot_hash', $liveState['snapshot_hash'])->first();

            if ($snapshot) {
                return ['snapshot' => $snapshot, 'created' => false];
            }

            throw $exception;
        }

    }

    /**
     * @return Collection<int, ReportingFact>
     */
    private function persistedFacts(Characterization $characterization): Collection
    {
        return ReportingFact::query()
            ->where('characterization_id', $characterization->id)
            ->orderBy('fact_id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalCharacterization(Characterization $characterization): array
    {
        $topicIds = $characterization->esrs_topic_ids ?? [];
        sort($topicIds);

        return $this->canonicalize([
            'id' => $characterization->id,
            'user_id' => $characterization->user_id,
            'status' => $characterization->status,
            'nace_code' => $characterization->nace_code,
            'esrs_topic_ids' => $topicIds,
            'form_data' => $characterization->form_data ?? [],
            'result_data' => $characterization->result_data ?? [],
        ]);
    }

    /**
     * @param iterable<int, ReportingFact> $facts
     * @return list<array<string, mixed>>
     */
    private function canonicalFacts(iterable $facts): array
    {
        $canonical = [];

        foreach ($facts as $fact) {
            $canonical[] = $this->canonicalize([
                'fact_id' => $fact->fact_id,
                'schema_version' => $fact->schema_version,
                'profile_id' => $fact->profile_id,
                'datapoint_id' => $fact->datapoint_id,
                'applicability' => $fact->applicability,
                'value_type' => $fact->value_type,
                'value' => $fact->value,
                'unit' => $fact->unit,
                'decimals' => $fact->decimals,
                'dimensions' => $fact->dimensions ?? [],
                'language' => $fact->language,
                'nil' => (bool) $fact->nil,
                'nil_reason' => $fact->nil_reason,
                'evidence_refs' => $fact->evidence_refs ?? [],
                'provenance' => $fact->provenance,
                'approval_status' => $fact->approval_status,
                'blocking_reasons' => $fact->blocking_reasons ?? [],
                'reviewed_at' => $fact->reviewed_at?->toJSON(),
                'reviewed_by_user_id' => $fact->reviewed_by_user_id,
                'review_declaration_sha256' => $fact->review_declaration_sha256,
            ]);
        }

        usort($canonical, fn (array $left, array $right): int => $left['fact_id'] <=> $right['fact_id']);

        return $canonical;
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<array<string, mixed>>
     */
    private function factsForStaleness(array $facts): array
    {
        return $facts;
    }

    private function hashCanonical(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function hashMatches(string $expected, mixed $actual): bool
    {
        return is_string($actual) && hash_equals($expected, $actual);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordEvent(string $eventType, ReportSnapshot $snapshot, array $payload): void
    {
        ReportAuditEvent::create([
            'user_id' => $snapshot->user_id,
            'characterization_id' => $snapshot->characterization_id,
            'report_snapshot_id' => $snapshot->id,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }
}
