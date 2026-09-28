<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportAuditEvent;
use App\Services\CharacterizationStateTransaction;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\EsrsDatapointResponseState;
use App\Support\EsrsDatapointCsvExporter;
use App\Support\EsrsDatapointResponseCsvExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EsrsDatapointController extends Controller
{
    private const RESPONSE_SCHEMA_VERSION = 'v0';

    public function index(Request $request, EsrsDatapointCorpusBuilder $builder)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $builder->build($characterization)]);
    }

    public function exportCsv(
        Request $request,
        EsrsDatapointCorpusBuilder $builder,
        EsrsDatapointCsvExporter $exporter,
    ) {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        return response($exporter->toCsv($builder->build($characterization)), 200, [
            'Content-Disposition' => 'attachment; filename=esrs-datapoints.csv',
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportResponsesCsv(
        Request $request,
        EsrsDatapointCorpusBuilder $builder,
        EsrsDatapointResponseState $responseState,
        EsrsDatapointResponseCsvExporter $exporter,
    ) {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $corpus = $builder->build($characterization);

        return response($exporter->toCsv($corpus, $responseState->state($characterization, $corpus)), 200, [
            'Content-Disposition' => 'attachment; filename=esrs-datapoint-responses.csv',
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function responses(
        Request $request,
        EsrsDatapointCorpusBuilder $builder,
        EsrsDatapointResponseState $responseState,
    )
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $corpus = $builder->build($characterization);

        return response()->json(['data' => $responseState->state($characterization, $corpus)]);
    }

    public function updateResponses(
        Request $request,
        EsrsDatapointCorpusBuilder $builder,
        EsrsDatapointResponseState $responseState,
        CharacterizationStateTransaction $stateTransactions,
    )
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'responses' => ['present', 'array'],
            'responses.*.datapoint_id' => ['required', 'string', 'distinct'],
            'responses.*.status' => ['required', 'string', Rule::in(['draft', 'completed', 'not_applicable'])],
            'responses.*.value' => ['nullable', 'string', 'max:5000'],
            'responses.*.evidence_reference' => ['nullable', 'string', 'max:1000'],
            'responses.*.note' => ['nullable', 'string', 'max:2000'],
            'responses.*.triage' => ['sometimes', 'nullable', Rule::in([
                'have_it',
                'need_to_find',
                'not_applicable_candidate',
            ])],
        ]);

        $submittedDatapointIds = collect($validated['responses'])
            ->pluck('datapoint_id')
            ->map(fn (string $id) => trim($id))
            ->all();

        if (count($submittedDatapointIds) !== count(array_unique($submittedDatapointIds))) {
            throw ValidationException::withMessages([
                'responses' => 'Each datapoint may only be submitted once after canonical trimming.',
            ]);
        }

        $result = $stateTransactions->run($characterization->id, function (Characterization $lockedCharacterization) use (
            $request,
            $builder,
            $responseState,
            $validated,
            $submittedDatapointIds,
        ): array {
            $corpus = $builder->build($lockedCharacterization);
            $currentRevision = $responseState->revision($lockedCharacterization);

            if ((int) $validated['expected_revision'] !== $currentRevision) {
                return [
                    'conflict' => true,
                    'current_revision' => $currentRevision,
                    'state' => $responseState->state($lockedCharacterization, $corpus),
                ];
            }

            $allowedDatapointIds = $responseState->corpusDatapointIds($corpus);
            if (array_diff($submittedDatapointIds, $allowedDatapointIds) !== []) {
                throw ValidationException::withMessages([
                    'responses' => 'One or more datapoints are not part of the current corpus.',
                ]);
            }

            $formData = $lockedCharacterization->form_data ?? [];
            $storedResponses = Arr::get($formData, 'esrs_datapoint_responses.responses', []);
            $storedResponses = is_array($storedResponses) ? $storedResponses : [];
            $orphanedResponses = array_diff_key($storedResponses, array_flip($allowedDatapointIds));
            $normalizedResponses = $responseState->normalizeResponses($validated['responses']);
            $nextRevision = $currentRevision + 1;

            Arr::set($formData, 'esrs_datapoint_responses', [
                'schema_version' => EsrsDatapointResponseState::SCHEMA_VERSION,
                'revision' => $nextRevision,
                'updated_at' => now()->toJSON(),
                'responses' => array_replace($orphanedResponses, $normalizedResponses),
            ]);
            $lockedCharacterization->forceFill(['form_data' => $formData])->save();

            ReportAuditEvent::create([
                'user_id' => $request->user()->id,
                'characterization_id' => $lockedCharacterization->id,
                'event_type' => 'datapoint_responses_replaced',
                'payload' => [
                    'characterization_id' => $lockedCharacterization->id,
                    'previous_revision' => $currentRevision,
                    'revision' => $nextRevision,
                    'response_count' => count($normalizedResponses),
                    'datapoint_ids' => array_keys($normalizedResponses),
                ],
            ]);

            return [
                'conflict' => false,
                'state' => $responseState->state($lockedCharacterization->fresh(), $corpus),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => 'The datapoint responses changed after this edit started.',
                'code' => 'datapoint_responses_conflict',
                'current_revision' => $result['current_revision'],
                'data' => $result['state'],
            ], 409);
        }

        return response()->json(['data' => $result['state']]);
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    private function responseState(Characterization $characterization, array $corpus): array
    {
        $stored = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses', []);
        $responses = Arr::get($stored, 'responses', []);
        $responses = is_array($responses) ? $responses : [];
        $allowedDatapointIds = $this->corpusDatapointIds($corpus);
        $allowedDatapointLookup = array_flip($allowedDatapointIds);
        $orphanedResponses = array_diff_key($responses, $allowedDatapointLookup);
        $responses = array_intersect_key($responses, $allowedDatapointLookup);

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => Arr::get($stored, 'schema_version', self::RESPONSE_SCHEMA_VERSION),
            'updated_at' => Arr::get($stored, 'updated_at'),
            'responses' => $responses,
            'orphaned' => [
                'count' => count($orphanedResponses),
                'responses' => $orphanedResponses,
            ],
            'summary' => $this->responseSummary($responses, $this->requiredDatapointIds($corpus)),
        ];
    }

    /**
     * Datapoints that are default-selected (mandatory after voluntary and
     * phase-in defaults); completion is measured against these only.
     *
     * @param  array<string, mixed>  $corpus
     * @return array<int, string>
     */
    private function requiredDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
            ->filter(fn (array $datapoint) => (bool) Arr::get($datapoint, 'selection.default_selected', true))
            ->pluck('id')
            ->filter()
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<int, string>
     */
    private function corpusDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    private function normalizeResponses(array $responses): array
    {
        $updatedAt = now()->toJSON();

        return collect($responses)
            ->mapWithKeys(function (array $response) use ($updatedAt) {
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
     * @param  array<int, string>  $requiredDatapointIds
     * @return array<string, int|float|string>
     */
    private function responseSummary(array $responses, array $requiredDatapointIds): array
    {
        $statusCounts = collect($responses)->countBy('status');
        $completedCount = (int) $statusCounts->get('completed', 0);
        $notApplicableCount = (int) $statusCounts->get('not_applicable', 0);
        $responseCount = count($responses);

        $requiredResponses = array_intersect_key($responses, array_flip($requiredDatapointIds));
        $requiredStatusCounts = collect($requiredResponses)->countBy('status');
        $requiredCompletedOrNotApplicable = (int) $requiredStatusCounts->get('completed', 0)
            + (int) $requiredStatusCounts->get('not_applicable', 0);
        $requiredDatapointCount = count($requiredDatapointIds);

        return [
            'applicable_datapoint_count' => $requiredDatapointCount,
            'response_count' => $responseCount,
            'completed_count' => $completedCount,
            'draft_count' => (int) $statusCounts->get('draft', 0),
            'not_applicable_count' => $notApplicableCount,
            'optional_response_count' => $responseCount - count($requiredResponses),
            'completion_ratio' => $requiredDatapointCount > 0
                ? round($requiredCompletedOrNotApplicable / $requiredDatapointCount, 4)
                : 1.0,
            'completion_status' => match (true) {
                $responseCount === 0 => 'not_started',
                $requiredDatapointCount > 0 && $requiredCompletedOrNotApplicable >= $requiredDatapointCount => 'completed',
                default => 'in_progress',
            },
        ];
    }
}
