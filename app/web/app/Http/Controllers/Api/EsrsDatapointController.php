<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Support\EsrsDatapointFactState;
use App\Support\EsrsDatapointCsvExporter;
use App\Support\EsrsDatapointResponseCsvExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class EsrsDatapointController extends Controller
{
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
        EsrsDatapointFactState $factState,
    ) {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $corpus = $builder->build($characterization);

        return response($exporter->toCsv($corpus, $factState->state($characterization, $corpus)), 200, [
            'Content-Disposition' => 'attachment; filename=esrs-datapoint-responses.csv',
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function responses(Request $request, EsrsDatapointCorpusBuilder $builder, EsrsDatapointFactState $factState)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $corpus = $builder->build($characterization);

        return response()->json(['data' => $factState->state($characterization, $corpus)]);
    }

    public function updateResponses(Request $request, EsrsDatapointCorpusBuilder $builder, EsrsDatapointFactState $factState)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $validated = $request->validate([
            'reporting_entity' => ['sometimes', 'array'],
            'reporting_entity.identifier_scheme' => ['sometimes', 'nullable', 'string', 'max:512'],
            'reporting_entity.identifier' => ['sometimes', 'nullable', 'string', 'max:512'],
            'reporting_entity.name' => ['sometimes', 'nullable', 'string', 'max:512'],
            'responses' => ['present', 'array'],
            'responses.*.datapoint_id' => ['required', 'string'],
            'responses.*.status' => ['required', 'string', Rule::in(['draft', 'completed', 'not_applicable'])],
            'responses.*.value' => ['nullable', 'string', 'max:5000'],
            'responses.*.evidence_reference' => ['nullable', 'string', 'max:1000'],
            'responses.*.note' => ['nullable', 'string', 'max:2000'],
            'responses.*.triage' => ['sometimes', 'nullable', Rule::in([
                'have_it',
                'need_to_find',
                'not_applicable_candidate',
            ])],
            'responses.*.facts' => ['sometimes', 'array'],
        ]);

        $corpus = $builder->build($characterization);
        $currentState = $factState->state($characterization, $corpus);
        $formData = $characterization->form_data ?? [];
        $container = $factState->normalizePayload($validated, $corpus, $currentState['orphaned']['responses'] ?? []);

        Arr::set($formData, 'esrs_datapoint_responses', $container);

        $characterization->forceFill(['form_data' => $formData])->save();

        return response()->json([
            'data' => $factState->state($characterization->fresh(), $corpus),
        ]);
    }
}
