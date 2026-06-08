<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Services\EsrsDatapointCorpusBuilder;
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
        EsrsDatapointResponseCsvExporter $exporter,
    ) {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $corpus = $builder->build($characterization);

        return response($exporter->toCsv($corpus, $this->responseState($characterization, $corpus)), 200, [
            'Content-Disposition' => 'attachment; filename=esrs-datapoint-responses.csv',
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function responses(Request $request, EsrsDatapointCorpusBuilder $builder)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $corpus = $builder->build($characterization);

        return response()->json(['data' => $this->responseState($characterization, $corpus)]);
    }

    public function updateResponses(Request $request, EsrsDatapointCorpusBuilder $builder)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $validated = $request->validate([
            'responses' => ['present', 'array'],
            'responses.*.datapoint_id' => ['required', 'string', 'distinct'],
            'responses.*.status' => ['required', 'string', Rule::in(['draft', 'completed', 'not_applicable'])],
            'responses.*.value' => ['nullable', 'string', 'max:5000'],
            'responses.*.evidence_reference' => ['nullable', 'string', 'max:1000'],
            'responses.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        $corpus = $builder->build($characterization);
        $allowedDatapointIds = $this->corpusDatapointIds($corpus);
        $submittedDatapointIds = collect($validated['responses'])
            ->pluck('datapoint_id')
            ->map(fn (string $id) => trim($id))
            ->all();

        if (count($submittedDatapointIds) !== count(array_unique($submittedDatapointIds))) {
            throw ValidationException::withMessages([
                'responses' => 'Each datapoint may only be submitted once after canonical trimming.',
            ]);
        }

        if (array_diff($submittedDatapointIds, $allowedDatapointIds) !== []) {
            throw ValidationException::withMessages([
                'responses' => 'One or more datapoints are not part of the current corpus.',
            ]);
        }

        $formData = $characterization->form_data ?? [];
        Arr::set($formData, 'esrs_datapoint_responses', [
            'schema_version' => self::RESPONSE_SCHEMA_VERSION,
            'updated_at' => now()->toJSON(),
            'responses' => $this->normalizeResponses($validated['responses']),
        ]);

        $characterization->forceFill(['form_data' => $formData])->save();

        return response()->json([
            'data' => $this->responseState($characterization->fresh(), $corpus),
        ]);
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
        $responses = array_intersect_key($responses, array_flip($allowedDatapointIds));
        $applicableCount = (int) Arr::get(
            $corpus,
            'summary.total_datapoint_count',
            count($allowedDatapointIds),
        );

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => Arr::get($stored, 'schema_version', self::RESPONSE_SCHEMA_VERSION),
            'updated_at' => Arr::get($stored, 'updated_at'),
            'responses' => $responses,
            'summary' => $this->responseSummary($responses, $applicableCount),
        ];
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

                foreach (['value', 'evidence_reference', 'note'] as $field) {
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
     * @return array<string, int|float|string>
     */
    private function responseSummary(array $responses, int $applicableDatapointCount): array
    {
        $statusCounts = collect($responses)->countBy('status');
        $completedCount = (int) $statusCounts->get('completed', 0);
        $notApplicableCount = (int) $statusCounts->get('not_applicable', 0);
        $completedOrNotApplicable = $completedCount + $notApplicableCount;
        $responseCount = count($responses);

        return [
            'applicable_datapoint_count' => $applicableDatapointCount,
            'response_count' => $responseCount,
            'completed_count' => $completedCount,
            'draft_count' => (int) $statusCounts->get('draft', 0),
            'not_applicable_count' => $notApplicableCount,
            'completion_ratio' => $applicableDatapointCount > 0
                ? round($completedOrNotApplicable / $applicableDatapointCount, 4)
                : 1.0,
            'completion_status' => match (true) {
                $responseCount === 0 => 'not_started',
                $applicableDatapointCount > 0 && $completedOrNotApplicable >= $applicableDatapointCount => 'completed',
                default => 'in_progress',
            },
        ];
    }
}
