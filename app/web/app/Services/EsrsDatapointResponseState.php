<?php

namespace App\Services;

use App\Models\Characterization;
use Illuminate\Support\Arr;

final class EsrsDatapointResponseState
{
    public const SCHEMA_VERSION = 'v1';

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    public function state(Characterization $characterization, array $corpus): array
    {
        $stored = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses', []);
        $stored = is_array($stored) ? $stored : [];
        $responses = Arr::get($stored, 'responses', []);
        $responses = is_array($responses) ? $responses : [];
        $responses = array_filter(
            $responses,
            static fn (mixed $response): bool => is_array($response)
                && in_array($response['status'] ?? null, ['draft', 'completed', 'not_applicable'], true),
        );
        $allowedDatapointIds = $this->corpusDatapointIds($corpus);
        $allowedDatapointLookup = array_flip($allowedDatapointIds);
        $orphanedResponses = array_diff_key($responses, $allowedDatapointLookup);
        $currentResponses = array_intersect_key($responses, $allowedDatapointLookup);

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => Arr::get($stored, 'schema_version', self::SCHEMA_VERSION),
            'revision' => $this->revision($characterization),
            'updated_at' => Arr::get($stored, 'updated_at'),
            'responses' => $currentResponses,
            'orphaned' => [
                'count' => count($orphanedResponses),
                'responses' => $orphanedResponses,
            ],
            'summary' => $this->summary($currentResponses, $this->requiredDatapointIds($corpus)),
        ];
    }

    /**
     * Flat summary consumed by P10. It is derived from the same required-id
     * policy as the P9 response endpoint.
     *
     * @param  array<string, mixed>  $corpus
     * @return array<string, int|float|string>
     */
    public function reportSummary(Characterization $characterization, array $corpus): array
    {
        $state = $this->state($characterization, $corpus);
        $summary = $state['summary'];

        return [
            'response_count' => $summary['response_count'],
            'completed_count' => $summary['completed_count'],
            'not_applicable_count' => $summary['not_applicable_count'],
            'decided_count' => $summary['decided_count'],
            'effective_required_datapoint_count' => $summary['applicable_datapoint_count'],
            'completion_ratio' => $summary['completion_ratio'],
            'completion_status' => $summary['completion_status'],
            'orphaned_response_count' => $state['orphaned']['count'],
        ];
    }

    public function revision(Characterization $characterization): int
    {
        $revision = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses.revision', 0);

        return is_numeric($revision) ? max(0, (int) $revision) : 0;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    public function requiredDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
            ->filter(fn (array $datapoint): bool => (bool) Arr::get($datapoint, 'selection.default_selected', true))
            ->pluck('id')
            ->filter()
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    public function corpusDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    public function normalizeResponses(array $responses): array
    {
        $updatedAt = now()->toJSON();

        return collect($responses)
            ->mapWithKeys(function (array $response) use ($updatedAt): array {
                $datapointId = trim((string) $response['datapoint_id']);
                $normalized = [
                    'datapoint_id' => $datapointId,
                    'status' => $response['status'],
                    'updated_at' => $updatedAt,
                ];

                foreach (['value', 'evidence_reference', 'note', 'triage'] as $field) {
                    if (array_key_exists($field, $response) && filled($response[$field])) {
                        $normalized[$field] = $response[$field];
                    }
                }

                return [$datapointId => $normalized];
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     * @param  list<string>  $requiredDatapointIds
     * @return array<string, int|float|string>
     */
    private function summary(array $responses, array $requiredDatapointIds): array
    {
        $statusCounts = collect($responses)->countBy('status');
        $completedCount = (int) $statusCounts->get('completed', 0);
        $notApplicableCount = (int) $statusCounts->get('not_applicable', 0);
        $responseCount = count($responses);
        $requiredResponses = array_intersect_key($responses, array_flip($requiredDatapointIds));
        $requiredStatusCounts = collect($requiredResponses)->countBy('status');
        $decidedRequiredCount = (int) $requiredStatusCounts->get('completed', 0)
            + (int) $requiredStatusCounts->get('not_applicable', 0);
        $requiredDatapointCount = count($requiredDatapointIds);

        return [
            'applicable_datapoint_count' => $requiredDatapointCount,
            'response_count' => $responseCount,
            'completed_count' => $completedCount,
            'draft_count' => (int) $statusCounts->get('draft', 0),
            'not_applicable_count' => $notApplicableCount,
            'decided_count' => $completedCount + $notApplicableCount,
            'decided_required_count' => $decidedRequiredCount,
            'optional_response_count' => $responseCount - count($requiredResponses),
            'completion_ratio' => $requiredDatapointCount > 0
                ? round($decidedRequiredCount / $requiredDatapointCount, 4)
                : 1.0,
            'completion_status' => match (true) {
                $responseCount === 0 => 'not_started',
                $requiredDatapointCount === 0 || $decidedRequiredCount >= $requiredDatapointCount => 'completed',
                default => 'in_progress',
            },
        ];
    }
}
