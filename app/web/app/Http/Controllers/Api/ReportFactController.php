<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ReportingFactProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportFactController extends Controller
{
    /**
     * @return JsonResponse
     */
    public function index(Request $request, ReportingFactProjector $projector)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->state($characterization, $projector)]);
    }

    /**
     * @return JsonResponse
     */
    public function update(
        Request $request,
        ReportingFactProjector $projector,
        EsrsDatapointCorpusBuilder $corpusBuilder,
    ) {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'facts' => ['present', 'array'],
            'facts.*.datapoint_id' => ['required', 'string', 'max:255'],
            'facts.*.applicability' => ['required', 'string', Rule::in([
                'applicable',
                'not_applicable',
                'pending',
                'unavailable',
                'blocked',
            ])],
            'facts.*.value_type' => ['required', 'string', Rule::in([
                'text',
                'number',
                'monetary',
                'integer',
                'boolean',
                'enumeration',
                'date',
                'nil',
            ])],
            'facts.*.value' => ['nullable'],
            'facts.*.unit' => ['nullable', 'string', 'max:64'],
            'facts.*.decimals' => ['nullable', 'integer', 'min:0', 'max:12'],
            'facts.*.dimensions' => ['present', 'array'],
            'facts.*.dimensions.*' => ['required', 'array:axis,member'],
            'facts.*.dimensions.*.axis' => ['required', 'string', 'max:255'],
            'facts.*.dimensions.*.member' => ['required', 'string', 'max:255'],
            'facts.*.language' => ['nullable', 'string', 'max:16'],
            'facts.*.nil' => ['required', 'boolean'],
            'facts.*.nil_reason' => ['nullable', 'string', 'max:1000'],
            'facts.*.evidence_refs' => ['present', 'array'],
            'facts.*.evidence_refs.*' => ['array:type,value'],
            'facts.*.evidence_refs.*.type' => ['required', 'string', 'max:100'],
            'facts.*.evidence_refs.*.value' => ['required', 'string', 'max:2000'],
            'facts.*.provenance' => ['required', 'string', Rule::in(['api'])],
            'facts.*.approval_status' => ['required', 'string', Rule::in(['review_required'])],
            'facts.*.blocking_reasons' => ['present', 'array'],
            'facts.*.blocking_reasons.*' => ['string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request, $characterization, $corpusBuilder) {
            $facts = $request->input('facts', []);
            $allowedDatapointIds = array_flip($this->corpusDatapointIds($corpusBuilder->build($characterization)));
            $seenFactIds = [];

            foreach (is_array($facts) ? $facts : [] as $index => $fact) {
                if (! is_array($fact)) {
                    continue;
                }

                $datapointId = trim((string) ($fact['datapoint_id'] ?? ''));
                $valueType = $fact['value_type'] ?? null;
                $language = $fact['language'] ?? null;
                $nil = (bool) ($fact['nil'] ?? false);
                $applicability = $fact['applicability'] ?? null;
                $evidenceRefs = is_array($fact['evidence_refs'] ?? null) ? $fact['evidence_refs'] : [];
                $dimensions = is_array($fact['dimensions'] ?? null) ? $fact['dimensions'] : [];
                $canonicalDimensions = $this->canonicalDimensions($dimensions);

                if ($datapointId !== '' && ! isset($allowedDatapointIds[$datapointId])) {
                    $validator->errors()->add("facts.$index.datapoint_id", 'The datapoint is not part of the current reporting corpus.');
                }

                if (($fact['approval_status'] ?? null) === 'approved') {
                    $validator->errors()->add("facts.$index.approval_status", 'Approved facts are not accepted by this endpoint.');
                }

                if (in_array($applicability, ['pending', 'blocked'], true) && ($fact['approval_status'] ?? null) === 'approved') {
                    $validator->errors()->add("facts.$index.approval_status", 'Pending or blocked facts cannot be approved.');
                }

                if ($nil) {
                    if ($valueType !== 'nil') {
                        $validator->errors()->add("facts.$index.value_type", 'Nil facts must use value_type nil.');
                    }

                    if (array_key_exists('value', $fact) && $fact['value'] !== null) {
                        $validator->errors()->add("facts.$index.value", 'Nil facts must not carry a value.');
                    }

                    if (! filled($fact['nil_reason'] ?? null)) {
                        $validator->errors()->add("facts.$index.nil_reason", 'Nil facts require a nil reason.');
                    }
                } else {
                    if ($valueType === 'nil') {
                        $validator->errors()->add("facts.$index.value_type", 'value_type nil requires nil=true.');
                    }

                    if (in_array($valueType, ['number', 'monetary', 'integer'], true)) {
                        if (! filled($fact['unit'] ?? null)) {
                            $validator->errors()->add("facts.$index.unit", 'Numeric facts require a unit.');
                        }

                        if (! array_key_exists('decimals', $fact) || $fact['decimals'] === null) {
                            $validator->errors()->add("facts.$index.decimals", 'Numeric facts require decimals.');
                        }
                    }

                    if ($valueType === 'text' && ! filled($language)) {
                        $validator->errors()->add("facts.$index.language", 'Text facts require a language.');
                    }
                }

                if (in_array($applicability, ['not_applicable', 'unavailable'], true) && count($evidenceRefs) === 0) {
                    $validator->errors()->add("facts.$index.evidence_refs", 'Not applicable or unavailable facts require structured evidence.');
                }

                $seenAxes = [];
                foreach ($dimensions as $dimensionIndex => $dimension) {
                    if (! is_array($dimension)) {
                        continue;
                    }

                    $axis = is_string($dimension['axis'] ?? null) ? trim($dimension['axis']) : '';
                    $member = is_string($dimension['member'] ?? null) ? trim($dimension['member']) : '';

                    if ($axis === '') {
                        $validator->errors()->add("facts.$index.dimensions.$dimensionIndex.axis", 'Dimension axis is required.');
                    }

                    if ($member === '') {
                        $validator->errors()->add("facts.$index.dimensions.$dimensionIndex.member", 'Dimension member is required.');
                    }

                    if ($axis !== '') {
                        if (isset($seenAxes[$axis])) {
                            $validator->errors()->add("facts.$index.dimensions.$dimensionIndex.axis", 'Duplicate dimension axis.');
                        }

                        $seenAxes[$axis] = true;
                    }
                }

                if ($datapointId !== '' && is_string($valueType)) {
                    $factId = ReportingFact::factId(
                        $characterization->id,
                        ReportingFact::PROFILE_ID,
                        $datapointId,
                        $canonicalDimensions,
                        $valueType,
                        is_string($language) && trim($language) !== '' ? trim($language) : null,
                    );

                    if (isset($seenFactIds[$factId])) {
                        $validator->errors()->add("facts.$index.datapoint_id", 'Duplicate reporting fact identity.');
                    }

                    $seenFactIds[$factId] = true;
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The reporting fact payload is invalid.',
                'code' => 'reporting_fact_invalid',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($validator->validated()['facts'] as $fact) {
            $datapointId = trim((string) $fact['datapoint_id']);
            $language = filled($fact['language'] ?? null) ? trim((string) $fact['language']) : null;
            $dimensions = $this->canonicalDimensions($fact['dimensions']);
            $factId = ReportingFact::factId(
                $characterization->id,
                ReportingFact::PROFILE_ID,
                $datapointId,
                $dimensions,
                $fact['value_type'],
                $language,
            );

            ReportingFact::updateOrCreate(
                [
                    'characterization_id' => $characterization->id,
                    'fact_id' => $factId,
                ],
                [
                    'schema_version' => ReportingFact::SCHEMA_VERSION,
                    'profile_id' => ReportingFact::PROFILE_ID,
                    'datapoint_id' => $datapointId,
                    'applicability' => $fact['applicability'],
                    'value_type' => $fact['value_type'],
                    'value' => $fact['value'] ?? null,
                    'unit' => $fact['unit'] ?? null,
                    'decimals' => $fact['decimals'] ?? null,
                    'dimensions' => $dimensions,
                    'language' => $language,
                    'nil' => (bool) $fact['nil'],
                    'nil_reason' => $fact['nil_reason'] ?? null,
                    'evidence_refs' => $fact['evidence_refs'],
                    'provenance' => 'api',
                    'approval_status' => 'review_required',
                    'blocking_reasons' => $fact['blocking_reasons'],
                ],
            );
        }

        return response()->json(['data' => $this->state($characterization->fresh(), $projector)]);
    }

    public function review(Request $request, int $fact): JsonResponse
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $reportingFact = ReportingFact::query()
            ->where('characterization_id', $characterization->id)
            ->whereKey($fact)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'review_declaration' => ['required', 'string'],
        ]);

        if ($validator->fails() || trim((string) $request->input('review_declaration', '')) === '') {
            return response()->json([
                'message' => 'A fact review declaration is required.',
                'code' => 'report_fact_review_declaration_required',
                'errors' => $validator->errors(),
            ], 422);
        }

        $reviewability = $this->factReviewability($reportingFact);

        if (! $reviewability['reviewable']) {
            return response()->json([
                'message' => 'The reporting fact is not reviewable.',
                'code' => 'report_fact_not_reviewable',
                'reasons' => $reviewability['reasons'],
            ], 409);
        }

        $previousStatus = $reportingFact->approval_status;
        $updates = [
            'approval_status' => 'reviewed',
        ];

        if (Schema::hasColumn($reportingFact->getTable(), 'reviewed_at')) {
            $updates['reviewed_at'] = now();
        }

        foreach (['reviewer_user_id', 'reviewed_by_user_id'] as $reviewerColumn) {
            if (Schema::hasColumn($reportingFact->getTable(), $reviewerColumn)) {
                $updates[$reviewerColumn] = $request->user()->id;
            }
        }

        $reportingFact->forceFill($updates)->save();

        ReportAuditEvent::create([
            'user_id' => $request->user()->id,
            'characterization_id' => $characterization->id,
            'event_type' => 'fact_reviewed',
            'payload' => [
                'user_id' => $request->user()->id,
                'characterization_id' => $characterization->id,
                'fact_id' => $reportingFact->fact_id,
                'datapoint_id' => $reportingFact->datapoint_id,
                'previous_approval_status' => $previousStatus,
                'approval_status' => 'reviewed',
            ],
        ]);

        return response()->json([
            'data' => ['id' => $reportingFact->id] + $reportingFact->fresh()->toApiArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Characterization $characterization, ReportingFactProjector $projector): array
    {
        $persistedFacts = ReportingFact::query()
            ->where('characterization_id', $characterization->id)
            ->orderBy('datapoint_id')
            ->orderBy('fact_id')
            ->get()
            ->map(fn (ReportingFact $fact) => $fact->toApiArray())
            ->values()
            ->all();

        $legacyProjection = $projector->projectLegacy($characterization);

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => ReportingFact::SCHEMA_VERSION,
            'profile_id' => ReportingFact::PROFILE_ID,
            'persisted_facts' => $persistedFacts,
            'persisted_fact_count' => count($persistedFacts),
            'legacy_projection' => $legacyProjection,
            'legacy_projection_count' => count($legacyProjection),
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
     * @param  array<int, mixed>  $dimensions
     * @return array<int, array{axis: string, member: string}>
     */
    private function canonicalDimensions(array $dimensions): array
    {
        $canonical = [];

        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $axis = is_string($dimension['axis'] ?? null) ? trim($dimension['axis']) : '';
            $member = is_string($dimension['member'] ?? null) ? trim($dimension['member']) : '';

            if ($axis === '' || $member === '') {
                continue;
            }

            $canonical[] = [
                'axis' => $axis,
                'member' => $member,
            ];
        }

        usort($canonical, fn (array $left, array $right): int => [$left['axis'], $left['member']] <=> [$right['axis'], $right['member']]);

        return $canonical;
    }

    /**
     * @return array{reviewable: bool, reasons: list<string>}
     */
    private function factReviewability(ReportingFact $fact): array
    {
        $reasons = [];

        if ($fact->approval_status !== 'review_required') {
            $reasons[] = 'fact_status_not_review_required';
        }

        if ($fact->applicability === 'pending') {
            $reasons[] = 'fact_pending';
        }

        if ($fact->applicability === 'blocked') {
            $reasons[] = 'fact_blocked';
        }

        if (count($fact->blocking_reasons ?? []) > 0) {
            $reasons[] = 'fact_blocking_reasons_present';
        }

        return [
            'reviewable' => $reasons === [],
            'reasons' => $reasons,
        ];
    }
}
