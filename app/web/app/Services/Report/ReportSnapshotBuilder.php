<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;

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

    public function create(Characterization $characterization): ReportSnapshot
    {
        return $this->createOrFind($characterization)['snapshot'];
    }

    /**
     * @return array{snapshot: ReportSnapshot, created: bool}
     */
    public function createOrFind(Characterization $characterization): array
    {
        $state = $this->buildCanonicalState($characterization);

        $existing = ReportSnapshot::query()
            ->where('snapshot_hash', $state['snapshot_hash'])
            ->first();

        if ($existing) {
            return ['snapshot' => $existing, 'created' => false];
        }

        try {
            $snapshot = ReportSnapshot::create([
                'user_id' => $characterization->user_id,
                'characterization_id' => $characterization->id,
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
        } catch (QueryException $exception) {
            $snapshot = ReportSnapshot::query()
                ->where('snapshot_hash', $state['snapshot_hash'])
                ->first();

            if ($snapshot) {
                return ['snapshot' => $snapshot, 'created' => false];
            }

            throw $exception;
        }

        $this->recordEvent('snapshot_created', $snapshot, [
            'snapshot_id' => $snapshot->id,
            'characterization_id' => $snapshot->characterization_id,
            'snapshot_hash' => $snapshot->snapshot_hash,
            'profile_hash' => $snapshot->profile_hash,
            'facts_hash' => $snapshot->facts_hash,
            'fact_count' => count($state['facts']),
        ]);

        return ['snapshot' => $snapshot, 'created' => true];
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
