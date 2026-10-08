<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\CharacterizationPredictionMapper;
use App\Services\CharacterizationStateTransaction;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Support\DoubleMaterialityProcessState;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MaterialityConfirmationController extends Controller
{
    private const CONFIRMATION_STATUS_CONFIRMED = 'confirmed';

    private const CONFIRMATION_STATUS_DEFAULTED_FROM_P6 = 'defaulted_from_p6';

    private const REASON_KEYS = [
        'new_data',
        'stakeholders',
        'scope_change',
        'threshold',
        'sector_requirement',
        'other',
    ];

    private const DIMENSION_VALUES = [
        'impact',
        'financial',
        'both',
    ];

    private const IMPACT_LEVELS = [
        'bajo',
        'medio',
        'alto',
        'no_lo_se',
    ];

    private const CONFIDENCE_LEVELS = [
        'baja',
        'media',
        'alta',
    ];

    private const EXPOSURE_LEVELS = [
        'normal',
        'fuerte',
        'descartada',
    ];

    private const SUGGESTED_RESULTS = [
        'material',
        'no_material',
        'en_observacion',
    ];

    private const FINAL_RESULTS = [
        'material',
        'no_material',
    ];

    private const UNIVERSE_ATTESTATION_VERSION = 1;

    private const REVIEW_MODES = [
        'direct',
        'guided',
    ];

    private const MAX_JSON_NESTING = 64;

    private const MAX_P8_REQUEST_BYTES = 1_048_576;

    private const MAX_TOPIC_COUNT = 89;

    private const MAX_REASON_COUNT = 6;

    private const MAX_JAVASCRIPT_SAFE_INTEGER = 9_007_199_254_740_991;

    public function show(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->confirmationState($characterization, $datapoints)]);
    }

    public function update(
        Request $request,
        EsrsDatapointCorpusBuilder $datapoints,
        CharacterizationStateTransaction $stateTransactions,
    ) {
        $rawContent = $request->getContent();
        if (strlen($rawContent) > self::MAX_P8_REQUEST_BYTES) {
            return response()->json([
                'message' => __('The P8 materiality confirmation request exceeds the 1048576-byte limit.'),
                'code' => 'materiality_confirmation_request_too_large',
            ], 413);
        }

        $this->rejectDuplicateJsonMembers($rawContent);

        $validated = $request->validate([
            'expected_revision' => ['required', 'integer:strict', 'min:0', 'max:'.self::MAX_JAVASCRIPT_SAFE_INTEGER],
            'confirmed_topic_ids' => ['present', 'array', 'list', 'max:'.self::MAX_TOPIC_COUNT],
            'confirmed_topic_ids.*' => ['integer:strict', 'min:1', 'distinct:strict', 'exists:esrs_topics,id'],
            'reviewed_topic_ids' => ['sometimes', 'array', 'list', 'max:'.self::MAX_TOPIC_COUNT],
            'reviewed_topic_ids.*' => ['integer:strict', 'min:1', 'distinct:strict', 'exists:esrs_topics,id'],
            'universe_attestation' => ['sometimes', 'array:version,reviewed_universe,mode'],
            'universe_attestation.version' => ['required_with:universe_attestation', 'integer:strict', Rule::in([self::UNIVERSE_ATTESTATION_VERSION])],
            'universe_attestation.reviewed_universe' => ['required_with:universe_attestation', 'boolean:strict'],
            'universe_attestation.mode' => ['required_with:universe_attestation', 'string', Rule::in(self::REVIEW_MODES)],
            'change_reasons' => ['sometimes', 'array', 'max:'.self::MAX_TOPIC_COUNT],
            'change_reasons.*' => ['array', 'list', 'max:'.self::MAX_REASON_COUNT],
            'change_reasons.*.*' => ['string', Rule::in(self::REASON_KEYS)],
            'change_reason_notes' => ['sometimes', 'array', 'max:'.self::MAX_TOPIC_COUNT],
            'change_reason_notes.*' => ['nullable', 'string', 'max:300'],
            'dimensions' => ['sometimes', 'array', 'max:'.self::MAX_TOPIC_COUNT],
            'dimensions.*' => ['string', Rule::in(self::DIMENSION_VALUES)],
            'guided_answers' => ['sometimes', 'array', 'max:'.self::MAX_TOPIC_COUNT],
            'guided_answers.*' => ['array:impacto,financiero,confianza,exposicion,suggested_result,final_result,revisar,note'],
            'guided_answers.*.impacto' => ['required', 'string', Rule::in(self::IMPACT_LEVELS)],
            'guided_answers.*.financiero' => ['required', 'string', Rule::in(self::IMPACT_LEVELS)],
            'guided_answers.*.confianza' => ['required', 'string', Rule::in(self::CONFIDENCE_LEVELS)],
            'guided_answers.*.exposicion' => ['required', 'string', Rule::in(self::EXPOSURE_LEVELS)],
            'guided_answers.*.suggested_result' => ['required', 'string', Rule::in(self::SUGGESTED_RESULTS)],
            'guided_answers.*.final_result' => ['required', 'string', Rule::in(self::FINAL_RESULTS)],
            'guided_answers.*.revisar' => ['required', 'boolean:strict'],
            'guided_answers.*.note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'e1_not_material_explanation' => ['nullable', 'string', 'max:2000'],
        ], [
            'confirmed_topic_ids.max' => __('The confirmed_topic_ids field may not contain more than 89 topics.'),
            'reviewed_topic_ids.max' => __('The reviewed_topic_ids field may not contain more than 89 topics.'),
            'change_reasons.max' => __('The change_reasons field may not contain more than 89 topics.'),
            'change_reasons.*.max' => __('A topic may not contain more than 6 change reasons.'),
            'change_reason_notes.max' => __('The change_reason_notes field may not contain more than 89 topics.'),
            'dimensions.max' => __('The dimensions field may not contain more than 89 topics.'),
            'guided_answers.max' => __('The guided_answers field may not contain more than 89 topics.'),
        ]);

        // Laravel's generic nested distinct wildcard compares reasons across topics.
        $reasonErrors = [];
        foreach ($validated['change_reasons'] ?? [] as $topicId => $reasons) {
            foreach ($reasons as $index => $reason) {
                if (count(array_keys($reasons, $reason, true)) > 1) {
                    $reasonErrors['change_reasons.'.$topicId.'.'.$index] = __('A topic may not contain duplicate change reasons.');
                }
            }
        }
        if ($reasonErrors !== []) {
            throw ValidationException::withMessages($reasonErrors);
        }

        $this->validateStrictTopicIdList($request->input('confirmed_topic_ids'), 'confirmed_topic_ids');
        if ($request->has('reviewed_topic_ids')) {
            $this->validateStrictTopicIdList($request->input('reviewed_topic_ids'), 'reviewed_topic_ids');
        }
        if ($request->has('universe_attestation') !== $request->has('reviewed_topic_ids')) {
            throw ValidationException::withMessages([
                'reviewed_topic_ids' => __('The reviewed topic universe and its attestation must be submitted together.'),
            ]);
        }
        if ($request->has('universe_attestation')) {
            $attestation = $request->input('universe_attestation');
            if (! is_int($attestation['version'] ?? null)) {
                throw ValidationException::withMessages([
                    'universe_attestation.version' => __('The attestation version must be a JSON integer without coercion.'),
                ]);
            }
            if (! is_bool($attestation['reviewed_universe'] ?? null)) {
                throw ValidationException::withMessages([
                    'universe_attestation.reviewed_universe' => __('The reviewed universe flag must be a JSON boolean without coercion.'),
                ]);
            }
        }

        $characterizationId = Characterization::forUser($request->user()->id)->firstOrFail()->id;
        $outcome = $stateTransactions->run($characterizationId, function (Characterization $characterization) use ($validated): array {
            $currentRevision = $this->confirmationRevision($characterization);

            if ($validated['expected_revision'] !== $currentRevision) {
                return ['conflict' => true, 'current_revision' => $currentRevision];
            }

            $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
            if ($characterization->status !== Characterization::STATUS_COMPLETED || $p6TopicIds === []) {
                throw ValidationException::withMessages([
                    'characterization' => __('A completed P6 materiality proposal is required before final confirmation.'),
                ]);
            }

            $confirmedTopicIds = $this->topicIds($validated['confirmed_topic_ids']);
            $storedConfirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
            $storedConfirmation = is_array($storedConfirmation) ? $storedConfirmation : [];
            $storedEvidence = $this->validStoredEvidence($storedConfirmation);
            $previousStoredConfirmedTopicIds = $this->strictStoredExistingTopicIds(
                Arr::get($storedConfirmation, 'confirmed_topic_ids')
            ) ?? [];
            $previousReviewedTopicIds = $this->reviewedTopicState(
                $storedConfirmation,
                $p6TopicIds,
                $previousStoredConfirmedTopicIds,
            )['topic_ids'];
            // Map keys are evidence only; membership must be established independently.
            foreach ($storedEvidence as $field => $map) {
                $storedEvidence[$field] = $this->filterKeyedMap($map, $previousReviewedTopicIds);
            }
            $hasLearningPair = array_key_exists('reviewed_topic_ids', $validated);
            $reviewedTopicIds = $hasLearningPair
                ? $this->topicIds($validated['reviewed_topic_ids'])
                : $this->mergeTopicIds(
                    $previousReviewedTopicIds,
                    $p6TopicIds,
                    $confirmedTopicIds,
                );

            $this->validateReviewedUniverse(
                $reviewedTopicIds,
                $previousReviewedTopicIds,
                $p6TopicIds,
                $confirmedTopicIds,
            );
            $this->validateTopicMapKeys($validated['change_reasons'] ?? [], $reviewedTopicIds, 'change_reasons');
            $this->validateTopicMapKeys($validated['change_reason_notes'] ?? [], $reviewedTopicIds, 'change_reason_notes');
            $this->validateTopicMapKeys($validated['dimensions'] ?? [], $reviewedTopicIds, 'dimensions');
            $this->validateTopicMapKeys($validated['guided_answers'] ?? [], $reviewedTopicIds, 'guided_answers');
            $this->validateGuidedAnswerConsistency($validated['guided_answers'] ?? [], $confirmedTopicIds);

            $universeAttestation = $this->normalizeUniverseAttestation($validated['universe_attestation'] ?? null);
            if (($universeAttestation['reviewed_universe'] ?? false) === true
                && ($universeAttestation['mode'] ?? null) === 'guided') {
                $this->validateCompleteGuidedUniverse(
                    $validated['guided_answers'] ?? [],
                    $reviewedTopicIds,
                    $confirmedTopicIds,
                );
            }

            if ($this->removesE1($characterization, $confirmedTopicIds)
                && blank($validated['e1_not_material_explanation'] ?? null)) {
                throw ValidationException::withMessages([
                    'e1_not_material_explanation' => __('An explanation is required when E1 is removed from final materiality.'),
                ]);
            }

            $formData = $characterization->form_data ?? [];
            $changeReasons = $this->normalizeKeyedArrays($validated['change_reasons'] ?? []);
            $changeReasonNotes = $this->normalizeKeyedStrings($validated['change_reason_notes'] ?? []);
            $dimensions = $this->normalizeKeyedStrings($validated['dimensions'] ?? []);
            $guidedAnswers = $this->normalizeGuidedAnswers($validated['guided_answers'] ?? []);
            if (! $hasLearningPair) {
                $legacyRepresentableTopicIds = $this->mergeTopicIds($p6TopicIds, $confirmedTopicIds);
                $changeReasons = $this->mergeLegacyHistoricalEvidence(
                    $storedEvidence['change_reasons'],
                    $changeReasons,
                    $legacyRepresentableTopicIds,
                );
                $changeReasonNotes = $this->mergeLegacyHistoricalEvidence(
                    $storedEvidence['change_reason_notes'],
                    $changeReasonNotes,
                    $legacyRepresentableTopicIds,
                );
                $dimensions = $this->mergeLegacyHistoricalEvidence(
                    $storedEvidence['dimensions'],
                    $dimensions,
                    $legacyRepresentableTopicIds,
                );
                $guidedAnswers = $this->mergeLegacyHistoricalEvidence(
                    $storedEvidence['guided_answers'],
                    $guidedAnswers,
                    $legacyRepresentableTopicIds,
                );
            }
            $decisionBasis = $this->deriveDecisionBasis(
                $guidedAnswers,
                DoubleMaterialityProcessState::fromFormData($formData)
            );

            Arr::set($formData, 'materiality_confirmation', [
                'revision' => $currentRevision + 1,
                'confirmed_topic_ids' => $confirmedTopicIds,
                'reviewed_topic_ids' => $reviewedTopicIds,
                'universe_attestation' => $universeAttestation,
                'change_reasons' => $changeReasons,
                'change_reason_notes' => $changeReasonNotes,
                'dimensions' => $dimensions,
                'guided_answers' => $guidedAnswers,
                'decision_basis' => $decisionBasis,
                'p6_snapshot' => [
                    'topic_ids' => $p6TopicIds,
                    'captured_at' => now()->toJSON(),
                ],
                'e1_not_material_explanation' => $validated['e1_not_material_explanation'] ?? null,
                'confirmed_at' => now()->toJSON(),
            ]);

            $characterization->forceFill(['form_data' => $formData])->save();

            return ['conflict' => false, 'characterization_id' => $characterization->id];
        });

        if ($outcome['conflict']) {
            return response()->json([
                'message' => __('La confirmación ha cambiado desde que se abrió. Recargue el estado actual antes de volver a guardar.'),
                'code' => 'stale_materiality_state',
                'data' => ['current_revision' => $outcome['current_revision']],
            ], 409);
        }

        $characterization = Characterization::findOrFail($outcome['characterization_id']);

        return response()->json(['data' => $this->confirmationState($characterization, $datapoints)]);
    }

    public function preview(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'candidate_topic_ids' => ['present', 'array'],
            'candidate_topic_ids.*' => ['integer', 'distinct', 'exists:esrs_topics,id'],
        ]);

        $clone = clone $characterization;
        $formData = $clone->form_data ?? [];
        Arr::set(
            $formData,
            'materiality_confirmation.confirmed_topic_ids',
            $this->topicIds($validated['candidate_topic_ids'])
        );
        $clone->forceFill(['form_data' => $formData]);

        return response()->json([
            'data' => [
                'preview' => $this->datapointPreview($clone, $datapoints),
            ],
        ]);
    }

    public function decisionSheet(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $state = $this->confirmationState($characterization, $datapoints);

        return response()->json(['data' => $this->decisionSheetState($characterization, $state)])
            ->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'materiality-decision-sheet.json' : 'hoja-decision-materialidad.json').'"');
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmationState(Characterization $characterization, EsrsDatapointCorpusBuilder $datapoints): array
    {
        $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        $confirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        $confirmation = is_array($confirmation) ? $confirmation : [];
        $admState = DoubleMaterialityProcessState::fromFormData($characterization->form_data ?? []);
        $storedConfirmedTopicIds = array_key_exists('confirmed_topic_ids', $confirmation)
            ? $this->strictStoredExistingTopicIds($confirmation['confirmed_topic_ids'])
            : null;
        $isConfirmed = $storedConfirmedTopicIds !== null;
        $confirmedTopicIds = $storedConfirmedTopicIds ?? $p6TopicIds;
        $storedEvidence = $this->validStoredEvidence($confirmation);
        $reviewedTopicState = $this->reviewedTopicState(
            $confirmation,
            $p6TopicIds,
            $storedConfirmedTopicIds ?? [],
        );
        $reviewedTopicIds = $reviewedTopicState['topic_ids'];
        // A valid frozen P6 snapshot permits historical display, never learning
        // authority or additional membership at the current PUT boundary.
        $historicalSnapshotTopicIds = $this->strictStoredExistingTopicIds(
            Arr::get($confirmation, 'p6_snapshot.topic_ids')
        ) ?? [];
        $projectedTopicIds = $this->mergeTopicIds($reviewedTopicIds, $historicalSnapshotTopicIds);
        $delta = $this->delta($p6TopicIds, $confirmedTopicIds);
        $preview = $this->datapointPreview($characterization, $datapoints);
        $guidedAnswers = $this->filterKeyedMap($storedEvidence['guided_answers'], $projectedTopicIds);
        $decisionBasis = $this->storedDecisionBasis($confirmation, $guidedAnswers)
            ?? $this->deriveDecisionBasis($guidedAnswers, $admState);
        $p6Snapshot = $this->p6Snapshot(Arr::get($confirmation, 'p6_snapshot'));
        $storedUniverseAttestation = $this->storedUniverseAttestation(Arr::get($confirmation, 'universe_attestation'));
        $universeAttestation = $reviewedTopicState['authoritative'] ? $storedUniverseAttestation : null;
        $learningTopicLabels = $this->learningTopicLabels(
            $confirmation,
            $reviewedTopicState['authoritative'],
            $reviewedTopicIds,
            $storedConfirmedTopicIds,
            $universeAttestation,
        );

        return [
            'characterization_id' => $characterization->id,
            'is_confirmed' => $isConfirmed,
            'is_stale' => $this->isStaleConfirmation($isConfirmed, $p6Snapshot, $p6TopicIds),
            'confirmation_status' => $isConfirmed
                ? self::CONFIRMATION_STATUS_CONFIRMED
                : self::CONFIRMATION_STATUS_DEFAULTED_FROM_P6,
            'decision_basis' => $decisionBasis,
            'p6_snapshot' => $p6Snapshot,
            'adm' => [
                'acta_registered' => $admState['acta_registered'],
                'acta' => $admState['acta'],
            ],
            'exposicion_defaults' => $this->exposicionDefaults($characterization, $reviewedTopicIds),
            'p6_anchor_date' => $characterization->submitted_at?->toJSON() ?? $characterization->updated_at?->toJSON(),
            'p6_topic_ids' => $p6TopicIds,
            'confirmed_topic_ids' => $confirmedTopicIds,
            'learning_topic_labels' => $learningTopicLabels,
            'delta' => $delta,
            'topics' => $this->topicSummaries($reviewedTopicIds),
            'confirmation' => [
                'revision' => $this->confirmationRevision($characterization),
                'reviewed_topic_ids' => $reviewedTopicIds,
                'universe_attestation' => $universeAttestation,
                'change_reasons' => $this->filterKeyedMap($storedEvidence['change_reasons'], $projectedTopicIds),
                'change_reason_notes' => $this->filterKeyedMap($storedEvidence['change_reason_notes'], $projectedTopicIds),
                'dimensions' => $this->filterKeyedMap($storedEvidence['dimensions'], $projectedTopicIds),
                'guided_answers' => $guidedAnswers,
                'e1_not_material_explanation' => Arr::get($confirmation, 'e1_not_material_explanation'),
                'confirmed_at' => Arr::get($confirmation, 'confirmed_at'),
            ],
            'preview' => $preview,
        ];
    }

    private function confirmationRevision(Characterization $characterization): int
    {
        $revision = Arr::get($characterization->form_data ?? [], 'materiality_confirmation.revision', 0);

        return is_numeric($revision) ? max(0, (int) $revision) : 0;
    }

    /**
     * @param  array<int, int>  $p6TopicIds
     * @param  array<int, int>  $confirmedTopicIds
     * @return array<string, array<int, int>>
     */
    private function delta(array $p6TopicIds, array $confirmedTopicIds): array
    {
        return [
            'added' => array_values(array_diff($confirmedTopicIds, $p6TopicIds)),
            'removed' => array_values(array_diff($p6TopicIds, $confirmedTopicIds)),
            'unchanged' => array_values(array_intersect($confirmedTopicIds, $p6TopicIds)),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function decisionSheetState(Characterization $characterization, array $state): array
    {
        $companyProfile = Arr::get($characterization->form_data ?? [], 'company_profile', []);
        $confirmation = $state['confirmation'];
        $delta = $state['delta'];
        $preview = $state['preview'];
        $topicsById = collect($state['topics'])->keyBy('id');

        return [
            'type' => 'p8_decision_sheet',
            'characterization_id' => $characterization->id,
            'is_confirmed' => $state['is_confirmed'],
            'confirmation_status' => $state['confirmation_status'],
            'decision_basis' => $state['decision_basis'],
            'adm' => $state['adm'],
            'company' => [
                'name' => Arr::get($companyProfile, 'company_name'),
                'reporting_year' => Arr::get($companyProfile, 'reporting_year'),
                'nace_code' => $characterization->nace_code,
            ],
            'p6_anchor_date' => $state['p6_anchor_date'],
            'confirmed_at' => $confirmation['confirmed_at'],
            'summary' => [
                'p6_topic_count' => count($state['p6_topic_ids']),
                'confirmed_topic_count' => count($state['confirmed_topic_ids']),
                'added_count' => count($delta['added']),
                'removed_count' => count($delta['removed']),
                'unchanged_count' => count($delta['unchanged']),
                'activated_esrs_standards' => $preview['activated_esrs_standards'],
                'p9_total_datapoint_estimate' => Arr::get($preview, 'datapoint_estimate.total_datapoint_count'),
                'effort_level' => $preview['effort_level'],
                'coverage_status' => $preview['coverage_status'],
            ],
            'changes' => [
                'added' => $this->decisionSheetTopicRows($delta['added'], $topicsById, $confirmation),
                'removed' => $this->decisionSheetTopicRows($delta['removed'], $topicsById, $confirmation),
                'unchanged' => $this->decisionSheetTopicRows($delta['unchanged'], $topicsById, $confirmation),
            ],
            'observation_resolutions' => $this->observationResolutions($confirmation['guided_answers']),
            'p9_preview' => $preview,
            'e1_not_material_explanation' => $confirmation['e1_not_material_explanation'],
            'note' => $state['is_confirmed']
                ? __('These selections reflect the external double materiality assessment. Evidence remains outside the application.')
                : __('No final P8 confirmation has been stored yet. Values are defaulted from the P6 proposal for preview only.'),
        ];
    }

    /**
     * @param  array<int, int>  $topicIds
     * @return array<int, array<string, mixed>>
     */
    private function decisionSheetTopicRows(array $topicIds, mixed $topicsById, array $confirmation): array
    {
        return collect($topicIds)
            ->map(function (int $topicId) use ($topicsById, $confirmation) {
                $topic = $topicsById->get($topicId);

                if (! is_array($topic)) {
                    return null;
                }

                return [
                    ...$topic,
                    'change_reasons' => Arr::get($confirmation, 'change_reasons.'.$topicId, []),
                    'change_reason_note' => Arr::get($confirmation, 'change_reason_notes.'.$topicId),
                    'dimension' => Arr::get($confirmation, 'dimensions.'.$topicId),
                    'guided' => Arr::get($confirmation, 'guided_answers.'.$topicId),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $values
     * @return array<int, int>
     */
    private function topicIds(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $guidedAnswers
     * @param  array<int, int>  $confirmedTopicIds
     */
    private function validateGuidedAnswerConsistency(array $guidedAnswers, array $confirmedTopicIds): void
    {
        foreach ($guidedAnswers as $topicId => $answer) {
            $isConfirmed = in_array((int) $topicId, $confirmedTopicIds, true);
            $isMaterial = Arr::get($answer, 'final_result') === 'material';

            if ($isConfirmed === $isMaterial) {
                continue;
            }

            throw ValidationException::withMessages([
                'guided_answers.'.$topicId.'.final_result' =>
                    __('The guided final result must match the final confirmed topic set.'),
            ]);
        }
    }

    private function validateStrictTopicIdList(mixed $values, string $field): void
    {
        if (! is_array($values)) {
            return;
        }

        $seen = [];
        foreach ($values as $value) {
            if (! is_int($value) || $value <= 0 || isset($seen[$value])) {
                throw ValidationException::withMessages([
                    $field => __('Topic IDs must be unique positive JSON integers without coercion.'),
                ]);
            }

            $seen[$value] = true;
        }
    }

    private function rejectDuplicateJsonMembers(string $json): void
    {
        if (trim($json) === '') {
            return;
        }

        try {
            $offset = 0;
            $this->scanJsonValue($json, $offset, 0);
            $this->skipJsonWhitespace($json, $offset);
            if ($offset !== strlen($json)) {
                throw new \InvalidArgumentException('Trailing JSON content.');
            }
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'json' => __('The JSON request must not contain duplicate object member names.'),
            ]);
        }
    }

    private function scanJsonValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > self::MAX_JSON_NESTING) {
            throw new \InvalidArgumentException('JSON nesting is too deep.');
        }

        $this->skipJsonWhitespace($json, $offset);
        $token = $json[$offset] ?? '';
        if ($token === '{') {
            $this->scanJsonObject($json, $offset, $depth);

            return;
        }
        if ($token === '[') {
            $this->scanJsonArray($json, $offset, $depth);

            return;
        }
        if ($token === '"') {
            $this->scanJsonString($json, $offset);

            return;
        }

        $start = $offset;
        $length = strlen($json);
        while ($offset < $length && ! str_contains(",]} \t\r\n", $json[$offset])) {
            $offset++;
        }
        if ($offset === $start) {
            throw new \InvalidArgumentException('Invalid JSON value.');
        }
    }

    private function scanJsonObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }

        $seen = [];
        while (true) {
            $key = $this->scanJsonString($json, $offset);
            $identity = "\0".$key;
            if (array_key_exists($identity, $seen)) {
                throw new \InvalidArgumentException('Duplicate JSON member.');
            }
            $seen[$identity] = true;

            $this->skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                throw new \InvalidArgumentException('Invalid JSON object.');
            }
            $offset++;
            $this->scanJsonValue($json, $offset, $depth + 1);
            $this->skipJsonWhitespace($json, $offset);
            $separator = $json[$offset] ?? '';
            if ($separator === '}') {
                $offset++;

                return;
            }
            if ($separator !== ',') {
                throw new \InvalidArgumentException('Invalid JSON object separator.');
            }
            $offset++;
            $this->skipJsonWhitespace($json, $offset);
        }
    }

    private function scanJsonArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }

        while (true) {
            $this->scanJsonValue($json, $offset, $depth + 1);
            $this->skipJsonWhitespace($json, $offset);
            $separator = $json[$offset] ?? '';
            if ($separator === ']') {
                $offset++;

                return;
            }
            if ($separator !== ',') {
                throw new \InvalidArgumentException('Invalid JSON array separator.');
            }
            $offset++;
        }
    }

    private function scanJsonString(string $json, int &$offset): string
    {
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? '') !== '"') {
            throw new \InvalidArgumentException('Invalid JSON string.');
        }

        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;

                continue;
            }
            if ($json[$offset] === '"') {
                $offset++;
                try {
                    $decoded = json_decode(
                        substr($json, $start, $offset - $start),
                        true,
                        self::MAX_JSON_NESTING,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (\JsonException $exception) {
                    throw new \InvalidArgumentException('Invalid JSON string.', previous: $exception);
                }

                if (! is_string($decoded)) {
                    throw new \InvalidArgumentException('Invalid JSON object key.');
                }

                return $decoded;
            }
            $offset++;
        }

        throw new \InvalidArgumentException('Unterminated JSON string.');
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    /**
     * @param  array<int, int>  $reviewedTopicIds
     * @param  array<int, int>  $previousReviewedTopicIds
     * @param  array<int, int>  $p6TopicIds
     * @param  array<int, int>  $confirmedTopicIds
     */
    private function validateReviewedUniverse(
        array $reviewedTopicIds,
        array $previousReviewedTopicIds,
        array $p6TopicIds,
        array $confirmedTopicIds,
    ): void {
        $requiredTopicIds = $this->mergeTopicIds($previousReviewedTopicIds, $p6TopicIds, $confirmedTopicIds);

        if (array_diff($requiredTopicIds, $reviewedTopicIds) !== []) {
            throw ValidationException::withMessages([
                'reviewed_topic_ids' => __('The reviewed topic universe is monotonic and must contain every previously reviewed, presented, and confirmed topic.'),
            ]);
        }
    }

    /**
     * @param  array<string|int, mixed>  $guidedAnswers
     * @param  array<int, int>  $reviewedTopicIds
     * @param  array<int, int>  $confirmedTopicIds
     */
    private function validateCompleteGuidedUniverse(
        array $guidedAnswers,
        array $reviewedTopicIds,
        array $confirmedTopicIds,
    ): void {
        foreach ($reviewedTopicIds as $topicId) {
            $answer = $guidedAnswers[(string) $topicId] ?? $guidedAnswers[$topicId] ?? null;
            if (! is_array($answer)
                || in_array($answer['impacto'] ?? null, ['no_lo_se'], true)
                || in_array($answer['financiero'] ?? null, ['no_lo_se'], true)
                || ($answer['suggested_result'] ?? null) === 'en_observacion') {
                throw ValidationException::withMessages([
                    'universe_attestation.reviewed_universe' => __('A complete guided universe requires one terminal non-observational binary answer for every reviewed topic.'),
                ]);
            }

            $expectedResult = in_array($topicId, $confirmedTopicIds, true) ? 'material' : 'no_material';
            if (($answer['final_result'] ?? null) !== $expectedResult) {
                throw ValidationException::withMessages([
                    'guided_answers.'.$topicId.'.final_result' => __('The guided final result must match the final confirmed topic set.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<int, string>>  $values
     * @return array<string, array<int, string>>
     */
    private function normalizeKeyedArrays(array $values): array
    {
        return collect($values)
            ->mapWithKeys(fn (array $value, string|int $key) => [(string) $key => array_values($value)])
            ->all();
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function normalizeKeyedStrings(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => filled($value))
            ->mapWithKeys(fn ($value, string|int $key) => [(string) $key => (string) $value])
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $values
     * @return array<string, array<string, mixed>>
     */
    private function normalizeGuidedAnswers(array $values): array
    {
        return collect($values)
            ->mapWithKeys(function (array $value, string|int $key): array {
                $answer = [
                    'impacto' => (string) $value['impacto'],
                    'financiero' => (string) $value['financiero'],
                    'confianza' => (string) $value['confianza'],
                    'exposicion' => (string) $value['exposicion'],
                    'suggested_result' => (string) $value['suggested_result'],
                    'final_result' => (string) $value['final_result'],
                    'revisar' => (bool) $value['revisar'],
                ];

                if (array_key_exists('note', $value) && filled($value['note'])) {
                    $answer['note'] = (string) $value['note'];
                }

                return [(string) $key => $answer];
            })
            ->all();
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @param  array<int, int>  $validTopicIds
     */
    private function validateTopicMapKeys(array $values, array $validTopicIds, string $field): void
    {
        $validTopicKeys = array_map('strval', $validTopicIds);

        foreach (array_keys($values) as $topicId) {
            $topicKey = (string) $topicId;

            if (! preg_match('/^[1-9][0-9]*$/', $topicKey)
                || ! in_array($topicKey, $validTopicKeys, true)) {
                throw ValidationException::withMessages([
                    $field => __('Keys must be canonical topic IDs inside the reviewed topic universe.'),
                ]);
            }
        }

        if ($this->existingTopicIds(array_map('intval', array_keys($values)))
            !== array_values(array_map('intval', array_keys($values)))) {
            throw ValidationException::withMessages([
                $field => __('Keys must be canonical catalog topic IDs inside the reviewed topic universe.'),
            ]);
        }
    }

    /**
     * @param  array<int, array<string|int, mixed>>  $maps
     * @return array<int, int>
     */
    private function topicMapIds(array $maps): array
    {
        $topicIds = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $topicId) {
                $topicKey = (string) $topicId;
                if (preg_match('/^[1-9][0-9]*$/', $topicKey)) {
                    $topicIds[] = (int) $topicKey;
                }
            }
        }

        return $this->mergeTopicIds($topicIds);
    }

    /**
     * @param  array<int, int>  ...$topicIdGroups
     * @return array<int, int>
     */
    private function mergeTopicIds(array ...$topicIdGroups): array
    {
        $merged = [];
        foreach ($topicIdGroups as $topicIds) {
            foreach ($topicIds as $topicId) {
                if (! in_array($topicId, $merged, true)) {
                    $merged[] = $topicId;
                }
            }
        }

        return $merged;
    }

    /**
     * @return array{topic_ids: array<int, int>, authoritative: bool}
     */
    private function reviewedTopicState(
        array $confirmation,
        array $p6TopicIds,
        array $confirmedTopicIds,
    ): array {
        $stored = Arr::get($confirmation, 'reviewed_topic_ids');
        if ($this->isStrictStoredTopicIdList($stored)) {
            $existing = $this->existingTopicIds($stored);
            if ($existing === $stored) {
                $merged = $this->mergeTopicIds($stored, $p6TopicIds, $confirmedTopicIds);

                return [
                    'topic_ids' => $merged,
                    'authoritative' => $merged === $stored
                        && $this->storedP6SnapshotMatches($confirmation, $p6TopicIds),
                ];
            }
        }

        $salvagedStoredTopicIds = [];
        if (is_array($stored)) {
            foreach ($stored as $topicId) {
                if (is_int($topicId) && $topicId > 0) {
                    $salvagedStoredTopicIds[] = $topicId;
                }
            }
        }
        $salvagedStoredTopicIds = $this->existingTopicIds(
            $this->mergeTopicIds($salvagedStoredTopicIds)
        );
        $legacy = $this->mergeTopicIds($salvagedStoredTopicIds, $p6TopicIds, $confirmedTopicIds);

        return ['topic_ids' => $this->existingTopicIds($legacy), 'authoritative' => false];
    }

    private function isStrictStoredTopicIdList(mixed $values): bool
    {
        if (! is_array($values)) {
            return false;
        }

        $seen = [];
        foreach ($values as $value) {
            if (! is_int($value) || $value <= 0 || isset($seen[$value])) {
                return false;
            }
            $seen[$value] = true;
        }

        return true;
    }

    /** @return array<int, int>|null */
    private function strictStoredExistingTopicIds(mixed $values): ?array
    {
        if (! $this->isStrictStoredTopicIdList($values)) {
            return null;
        }

        return $this->existingTopicIds($values) === $values ? $values : null;
    }

    /** @param array<int, int> $p6TopicIds */
    private function storedP6SnapshotMatches(array $confirmation, array $p6TopicIds): bool
    {
        $snapshotTopicIds = Arr::get($confirmation, 'p6_snapshot.topic_ids');
        if ($snapshotTopicIds === null) {
            return false;
        }

        $storedTopicIds = $this->strictStoredExistingTopicIds($snapshotTopicIds);

        return $storedTopicIds !== null
            && $this->sortedTopicIds($storedTopicIds) === $this->sortedTopicIds($p6TopicIds);
    }

    /**
     * @param  array<int, int>  $topicIds
     * @return array<int, int>
     */
    private function existingTopicIds(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        $existing = array_flip(EsrsTopic::whereIn('id', $topicIds)->pluck('id')->all());

        return array_values(array_filter($topicIds, fn (int $topicId): bool => isset($existing[$topicId])));
    }

    /**
     * @return array{version: int, reviewed_universe: bool, mode: string}|null
     */
    private function normalizeUniverseAttestation(mixed $attestation): ?array
    {
        if (! is_array($attestation)) {
            return null;
        }

        return [
            'version' => self::UNIVERSE_ATTESTATION_VERSION,
            'reviewed_universe' => $attestation['reviewed_universe'] === true,
            'mode' => (string) $attestation['mode'],
        ];
    }

    /**
     * @return array{version: int, reviewed_universe: bool, mode: string}|null
     */
    private function storedUniverseAttestation(mixed $attestation): ?array
    {
        $expectedKeys = ['mode', 'reviewed_universe', 'version'];
        $actualKeys = is_array($attestation) ? array_keys($attestation) : [];
        sort($actualKeys);

        if (! is_array($attestation)
            || $actualKeys !== $expectedKeys
            || ($attestation['version'] ?? null) !== self::UNIVERSE_ATTESTATION_VERSION
            || ! is_bool($attestation['reviewed_universe'] ?? null)
            || ! in_array($attestation['mode'] ?? null, self::REVIEW_MODES, true)) {
            return null;
        }

        return [
            'version' => self::UNIVERSE_ATTESTATION_VERSION,
            'reviewed_universe' => $attestation['reviewed_universe'],
            'mode' => $attestation['mode'],
        ];
    }

    /**
     * @param  array<int, int>  $reviewedTopicIds
     * @param  array<int, int>|null  $confirmedTopicIds
     * @param  array{version: int, reviewed_universe: bool, mode: string}|null  $attestation
     * @return array<string, int>|null
     */
    private function learningTopicLabels(
        array $confirmation,
        bool $authoritativeUniverse,
        array $reviewedTopicIds,
        ?array $confirmedTopicIds,
        ?array $attestation,
    ): ?array {
        if ($confirmedTopicIds === null
            || ! $authoritativeUniverse
            || ($attestation['reviewed_universe'] ?? false) !== true
            || array_diff($confirmedTopicIds, $reviewedTopicIds) !== []) {
            return null;
        }

        if (! $this->storedChangeReasonsAreValid(Arr::get($confirmation, 'change_reasons', []), $reviewedTopicIds)
            || ! $this->storedNotesAreValid(Arr::get($confirmation, 'change_reason_notes', []), $reviewedTopicIds)
            || ! $this->storedDimensionsAreValid(Arr::get($confirmation, 'dimensions', []), $reviewedTopicIds)
            || ! $this->storedGuidedAnswersAreValid(
                Arr::get($confirmation, 'guided_answers', []),
                $reviewedTopicIds,
                $confirmedTopicIds,
            )) {
            return null;
        }

        if ($attestation['mode'] === 'guided') {
            $guidedAnswers = Arr::get($confirmation, 'guided_answers', []);
            if (! is_array($guidedAnswers)
                || ! $this->guidedUniverseIsComplete($guidedAnswers, $reviewedTopicIds, $confirmedTopicIds)) {
                return null;
            }
        }

        return collect($reviewedTopicIds)
            ->mapWithKeys(fn (int $topicId): array => [
                (string) $topicId => in_array($topicId, $confirmedTopicIds, true) ? 1 : 0,
            ])
            ->all();
    }

    /**
     * @return array{
     *   change_reasons: array<string, array<int, string>>,
     *   change_reason_notes: array<string, string>,
     *   dimensions: array<string, string>,
     *   guided_answers: array<string, array<string, mixed>>
     * }
     */
    private function validStoredEvidence(array $confirmation): array
    {
        return [
            'change_reasons' => $this->filterValidStoredTopicMap(
                Arr::get($confirmation, 'change_reasons'),
                function (mixed $reasons): bool {
                    if (! is_array($reasons)
                        || ! array_is_list($reasons)
                        || count($reasons) > self::MAX_REASON_COUNT) {
                        return false;
                    }

                    $seen = [];
                    foreach ($reasons as $reason) {
                        if (! is_string($reason)
                            || ! in_array($reason, self::REASON_KEYS, true)
                            || isset($seen[$reason])) {
                            return false;
                        }
                        $seen[$reason] = true;
                    }

                    return true;
                },
            ),
            'change_reason_notes' => $this->filterValidStoredTopicMap(
                Arr::get($confirmation, 'change_reason_notes'),
                fn (mixed $note): bool => is_string($note) && filled($note) && mb_strlen($note) <= 300,
            ),
            'dimensions' => $this->filterValidStoredTopicMap(
                Arr::get($confirmation, 'dimensions'),
                fn (mixed $dimension): bool => is_string($dimension)
                    && in_array($dimension, self::DIMENSION_VALUES, true),
            ),
            'guided_answers' => $this->filterValidStoredTopicMap(
                Arr::get($confirmation, 'guided_answers'),
                fn (mixed $answer): bool => $this->storedGuidedAnswerValueIsValid($answer),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function filterValidStoredTopicMap(mixed $values, callable $valueIsValid): array
    {
        if (! is_array($values) || count($values) > self::MAX_TOPIC_COUNT) {
            return [];
        }

        $valid = [];
        foreach ($values as $topicId => $value) {
            $topicKey = (string) $topicId;
            if (! preg_match('/^[1-9][0-9]*$/', $topicKey) || ! $valueIsValid($value)) {
                continue;
            }
            $valid[$topicKey] = $value;
        }

        $existingTopicKeys = array_flip(array_map(
            'strval',
            $this->existingTopicIds(array_map('intval', array_keys($valid)))
        ));

        return array_filter(
            $valid,
            fn (string|int $topicId): bool => isset($existingTopicKeys[(string) $topicId]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function storedGuidedAnswerValueIsValid(mixed $answer): bool
    {
        if (! is_array($answer)) {
            return false;
        }

        $requiredKeys = [
            'confianza',
            'exposicion',
            'final_result',
            'financiero',
            'impacto',
            'revisar',
            'suggested_result',
        ];
        $actualKeys = array_keys($answer);
        sort($actualKeys);
        $expectedKeys = $requiredKeys;
        if (array_key_exists('note', $answer)) {
            $expectedKeys[] = 'note';
            sort($expectedKeys);
        }

        return $actualKeys === $expectedKeys
            && in_array($answer['impacto'] ?? null, self::IMPACT_LEVELS, true)
            && in_array($answer['financiero'] ?? null, self::IMPACT_LEVELS, true)
            && in_array($answer['confianza'] ?? null, self::CONFIDENCE_LEVELS, true)
            && in_array($answer['exposicion'] ?? null, self::EXPOSURE_LEVELS, true)
            && in_array($answer['suggested_result'] ?? null, self::SUGGESTED_RESULTS, true)
            && in_array($answer['final_result'] ?? null, self::FINAL_RESULTS, true)
            && is_bool($answer['revisar'] ?? null)
            && (! array_key_exists('note', $answer)
                || (is_string($answer['note']) && filled($answer['note']) && mb_strlen($answer['note']) <= 300));
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $incoming
     * @param  array<int, int>  $legacyRepresentableTopicIds
     * @return array<string, mixed>
     */
    private function mergeLegacyHistoricalEvidence(
        array $stored,
        array $incoming,
        array $legacyRepresentableTopicIds,
    ): array {
        $representableKeys = array_flip(array_map('strval', $legacyRepresentableTopicIds));
        $historical = array_filter(
            $stored,
            fn (string|int $topicId): bool => ! isset($representableKeys[(string) $topicId]),
            ARRAY_FILTER_USE_KEY,
        );

        return array_replace($historical, $incoming);
    }

    /**
     * @param  array<int, int>  $reviewedTopicIds
     */
    private function storedTopicMapIsValid(mixed $values, array $reviewedTopicIds): bool
    {
        if (! is_array($values)) {
            return false;
        }

        foreach (array_keys($values) as $topicId) {
            $topicKey = (string) $topicId;
            if (! preg_match('/^[1-9][0-9]*$/', $topicKey)
                || ! in_array((int) $topicKey, $reviewedTopicIds, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, int> $reviewedTopicIds */
    private function storedChangeReasonsAreValid(mixed $values, array $reviewedTopicIds): bool
    {
        if (! $this->storedTopicMapIsValid($values, $reviewedTopicIds)) {
            return false;
        }

        foreach ($values as $reasons) {
            if (! is_array($reasons) || ! array_is_list($reasons)) {
                return false;
            }

            $seen = [];
            foreach ($reasons as $reason) {
                if (! is_string($reason)
                    || ! in_array($reason, self::REASON_KEYS, true)
                    || isset($seen[$reason])) {
                    return false;
                }
                $seen[$reason] = true;
            }
        }

        return true;
    }

    /** @param array<int, int> $reviewedTopicIds */
    private function storedNotesAreValid(mixed $values, array $reviewedTopicIds): bool
    {
        if (! $this->storedTopicMapIsValid($values, $reviewedTopicIds)) {
            return false;
        }

        foreach ($values as $note) {
            if (! is_string($note) || blank($note) || mb_strlen($note) > 300) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, int> $reviewedTopicIds */
    private function storedDimensionsAreValid(mixed $values, array $reviewedTopicIds): bool
    {
        if (! $this->storedTopicMapIsValid($values, $reviewedTopicIds)) {
            return false;
        }

        foreach ($values as $dimension) {
            if (! is_string($dimension) || ! in_array($dimension, self::DIMENSION_VALUES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $reviewedTopicIds
     * @param  array<int, int>  $confirmedTopicIds
     */
    private function storedGuidedAnswersAreValid(
        mixed $values,
        array $reviewedTopicIds,
        array $confirmedTopicIds,
    ): bool {
        if (! $this->storedTopicMapIsValid($values, $reviewedTopicIds)) {
            return false;
        }

        $requiredKeys = [
            'confianza',
            'exposicion',
            'final_result',
            'financiero',
            'impacto',
            'revisar',
            'suggested_result',
        ];

        foreach ($values as $topicId => $answer) {
            if (! is_array($answer)) {
                return false;
            }

            $actualKeys = array_keys($answer);
            sort($actualKeys);
            $expectedKeys = $requiredKeys;
            if (array_key_exists('note', $answer)) {
                $expectedKeys[] = 'note';
                sort($expectedKeys);
            }

            $expectedResult = in_array((int) $topicId, $confirmedTopicIds, true) ? 'material' : 'no_material';
            if ($actualKeys !== $expectedKeys
                || ! in_array($answer['impacto'] ?? null, self::IMPACT_LEVELS, true)
                || ! in_array($answer['financiero'] ?? null, self::IMPACT_LEVELS, true)
                || ! in_array($answer['confianza'] ?? null, self::CONFIDENCE_LEVELS, true)
                || ! in_array($answer['exposicion'] ?? null, self::EXPOSURE_LEVELS, true)
                || ! in_array($answer['suggested_result'] ?? null, self::SUGGESTED_RESULTS, true)
                || ! in_array($answer['final_result'] ?? null, self::FINAL_RESULTS, true)
                || ($answer['final_result'] ?? null) !== $expectedResult
                || ! is_bool($answer['revisar'] ?? null)
                || (array_key_exists('note', $answer)
                    && (! is_string($answer['note']) || blank($answer['note']) || mb_strlen($answer['note']) > 300))) {
                return false;
            }
        }

        return true;
    }

    private function guidedUniverseIsComplete(array $guidedAnswers, array $reviewedTopicIds, array $confirmedTopicIds): bool
    {
        $guidedTopicIds = $this->topicMapIds([$guidedAnswers]);
        if (array_diff($guidedTopicIds, $reviewedTopicIds) !== []
            || array_diff($reviewedTopicIds, $guidedTopicIds) !== []) {
            return false;
        }

        foreach ($reviewedTopicIds as $topicId) {
            $answer = $guidedAnswers[(string) $topicId] ?? $guidedAnswers[$topicId] ?? null;
            $expectedResult = in_array($topicId, $confirmedTopicIds, true) ? 'material' : 'no_material';
            if (! is_array($answer)
                || ($answer['final_result'] ?? null) !== $expectedResult
                || in_array($answer['impacto'] ?? null, ['no_lo_se'], true)
                || in_array($answer['financiero'] ?? null, ['no_lo_se'], true)
                || ($answer['suggested_result'] ?? null) === 'en_observacion') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $validTopicIds
     * @return array<string, mixed>
     */
    private function filterKeyedMap(mixed $values, array $validTopicIds): array
    {
        if (! is_array($values)) {
            return [];
        }

        $validTopicKeys = array_flip(array_map('strval', $validTopicIds));

        return collect($values)
            ->filter(fn ($value, string|int $key) => preg_match('/^[1-9][0-9]*$/', (string) $key)
                && isset($validTopicKeys[(string) $key]))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function p6Snapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        $topicIds = Arr::get($snapshot, 'topic_ids', []);

        return [
            'topic_ids' => $this->topicIds(is_array($topicIds) ? $topicIds : []),
            'captured_at' => Arr::get($snapshot, 'captured_at'),
        ];
    }

    private function isStaleConfirmation(bool $isConfirmed, ?array $snapshot, array $currentP6TopicIds): bool
    {
        if (! $isConfirmed || $snapshot === null) {
            return false;
        }

        return $this->sortedTopicIds($snapshot['topic_ids'] ?? []) !== $this->sortedTopicIds($currentP6TopicIds);
    }

    /**
     * @param  array<int, int|string>  $topicIds
     * @return list<int>
     */
    private function sortedTopicIds(array $topicIds): array
    {
        $topicIds = $this->topicIds($topicIds);
        sort($topicIds);

        return array_values($topicIds);
    }

    /**
     * @param  array<string, mixed>  $confirmation
     */
    private function storedDecisionBasis(array $confirmation, array $guidedAnswers): ?string
    {
        $decisionBasis = Arr::get($confirmation, 'decision_basis');

        if ($decisionBasis === 'guided_questionnaire' && $guidedAnswers === []) {
            return null;
        }

        return in_array($decisionBasis, ['guided_questionnaire', 'adm_registered', 'none'], true)
            ? $decisionBasis
            : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $guidedAnswers
     * @param  array<string, mixed>  $admState
     */
    private function deriveDecisionBasis(array $guidedAnswers, array $admState): string
    {
        if ($guidedAnswers !== []) {
            return 'guided_questionnaire';
        }

        if (($admState['acta_registered'] ?? false) === true) {
            return 'adm_registered';
        }

        return 'none';
    }

    /**
     * @param  array<int, int>  $topicIds
     * @return array<string, string>
     */
    private function exposicionDefaults(Characterization $characterization, array $topicIds): array
    {
        $defaults = collect($topicIds)
            ->mapWithKeys(fn (int $topicId): array => [(string) $topicId => 'normal'])
            ->all();

        if ($defaults === []) {
            return [];
        }

        $rawPrediction = Arr::get($characterization->result_data ?? [], 'raw_prediction', []);
        $reviewRequiredPredictionKeys = Arr::get($characterization->result_data ?? [], 'review_required_prediction_keys', []);

        if (! is_array($rawPrediction) || ! is_array($reviewRequiredPredictionKeys)) {
            return $defaults;
        }

        try {
            $candidateTopics = app(CharacterizationPredictionMapper::class)->candidateTopics($rawPrediction);
        } catch (Throwable) {
            return $defaults;
        }

        $p6TopicKeys = array_flip(array_map('strval', $this->topicIds($characterization->esrs_topic_ids ?? [])));
        $reviewRequiredKeys = array_flip(array_map('strval', $reviewRequiredPredictionKeys));

        foreach ($candidateTopics as $candidateTopic) {
            $topicKey = (string) (int) ($candidateTopic['ar16_topic_id'] ?? 0);
            $predictionKeys = $candidateTopic['python_esrs_keys'] ?? [];

            if (! isset($defaults[$topicKey]) || ! isset($p6TopicKeys[$topicKey]) || ! is_array($predictionKeys)) {
                continue;
            }

            $hasReviewRequiredKey = collect($predictionKeys)
                ->contains(fn ($key): bool => isset($reviewRequiredKeys[(string) $key]));

            if (! $hasReviewRequiredKey) {
                $defaults[$topicKey] = 'fuerte';
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string|int, mixed>  $guidedAnswers  Filtered confirmation-state answers.
     * @return array<int, array<string, mixed>>
     */
    private function observationResolutions(array $guidedAnswers): array
    {
        return collect($guidedAnswers)
            ->filter(fn ($answer): bool => is_array($answer)
                && ($answer['suggested_result'] ?? null) === 'en_observacion')
            ->map(fn (array $answer, string|int $topicId): array => [
                'topic_id' => (int) $topicId,
                'final_result' => $answer['final_result'] ?? null,
                'revisar' => (bool) ($answer['revisar'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $confirmedTopicIds
     */
    private function removesE1(Characterization $characterization, array $confirmedTopicIds): bool
    {
        $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);

        $p6HasE1 = EsrsTopic::whereIn('id', $p6TopicIds)->where('esrs_code', 'E1')->exists();
        $confirmedHasE1 = EsrsTopic::whereIn('id', $confirmedTopicIds)->where('esrs_code', 'E1')->exists();

        return $p6HasE1 && ! $confirmedHasE1;
    }

    /**
     * @param  array<int, int>  $topicIds
     * @return array<int, string>
     */
    private function activatedStandards(array $topicIds): array
    {
        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');

        return collect($topicIds)
            ->map(fn (int $id) => $topics->get($id)?->esrs_code)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function datapointPreview(Characterization $characterization, EsrsDatapointCorpusBuilder $datapoints): array
    {
        $corpus = $datapoints->build($characterization);
        $summary = $corpus['summary'];
        $totalDatapoints = $summary['total_datapoint_count'];

        return [
            'material_topic_count' => count($corpus['material_topic_ids']),
            'activated_esrs_standards' => $corpus['activated_esrs_standards'],
            'datapoint_estimate' => [
                'label' => __('Materiality-filtered P9 corpus estimate'),
                'always_required_datapoint_count' => $summary['always_required_datapoint_count'],
                'topical_datapoint_count' => $summary['topical_datapoint_count'],
                'minimum_disclosure_requirement_datapoint_count' => $summary['minimum_disclosure_requirement_datapoint_count'],
                'total_datapoint_count' => $totalDatapoints,
                'voluntary_datapoint_count' => $summary['voluntary_datapoint_count'],
                'conditional_datapoint_count' => $summary['conditional_datapoint_count'],
                'phase_in_datapoint_count' => $summary['phase_in_datapoint_count'],
            ],
            'effort_level' => $this->effortLevel($totalDatapoints),
            'mapping_granularity' => $corpus['generation']['mapping_granularity'],
            'coverage_status' => $corpus['generation']['coverage_status'],
        ];
    }

    private function effortLevel(int $totalDatapoints): string
    {
        return match (true) {
            $totalDatapoints > 500 => 'high',
            $totalDatapoints > 250 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  array<int, int>  $topicIds
     * @return array<int, array<string, mixed>>
     */
    private function topicSummaries(array $topicIds): array
    {
        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');

        return collect($topicIds)
            ->map(fn (int $id) => $topics->get($id))
            ->filter()
            ->map(fn (EsrsTopic $topic) => [
                'id' => $topic->id,
                'esrs_code' => $topic->esrs_code,
                'theme' => [
                    'en' => $topic->theme_en,
                    'es' => $topic->theme_es,
                ],
                'subtheme' => [
                    'en' => $topic->subtheme_en,
                    'es' => $topic->subtheme_es,
                ],
                'subtopic' => [
                    'en' => $topic->subtopic_en,
                    'es' => $topic->subtopic_es,
                ],
            ])
            ->values()
            ->all();
    }
}
