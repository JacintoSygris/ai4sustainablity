<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function show(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->confirmationState($characterization, $datapoints)]);
    }

    public function update(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();
        $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);

        if ($characterization->status !== Characterization::STATUS_COMPLETED || $p6TopicIds === []) {
            throw ValidationException::withMessages([
                'characterization' => 'A completed P6 materiality proposal is required before final confirmation.',
            ]);
        }

        $validated = $request->validate([
            'confirmed_topic_ids' => ['present', 'array'],
            'confirmed_topic_ids.*' => ['integer', 'distinct', 'exists:esrs_topics,id'],
            'change_reasons' => ['sometimes', 'array'],
            'change_reasons.*' => ['array'],
            'change_reasons.*.*' => ['string', Rule::in(self::REASON_KEYS)],
            'change_reason_notes' => ['sometimes', 'array'],
            'change_reason_notes.*' => ['nullable', 'string', 'max:300'],
            'e1_not_material_explanation' => ['nullable', 'string', 'max:280'],
        ]);

        $confirmedTopicIds = $this->topicIds($validated['confirmed_topic_ids']);
        $validReasonTopicIds = array_values(array_unique([
            ...$p6TopicIds,
            ...$confirmedTopicIds,
        ]));

        $this->validateReasonTopicKeys($validated['change_reasons'] ?? [], $validReasonTopicIds, 'change_reasons');
        $this->validateReasonTopicKeys($validated['change_reason_notes'] ?? [], $validReasonTopicIds, 'change_reason_notes');

        if ($this->removesE1($characterization, $confirmedTopicIds)
            && blank($validated['e1_not_material_explanation'] ?? null)) {
            throw ValidationException::withMessages([
                'e1_not_material_explanation' => 'An explanation is required when E1 is removed from final materiality.',
            ]);
        }

        $formData = $characterization->form_data ?? [];
        Arr::set($formData, 'materiality_confirmation', [
            'confirmed_topic_ids' => $confirmedTopicIds,
            'change_reasons' => $this->normalizeKeyedArrays($validated['change_reasons'] ?? []),
            'change_reason_notes' => $this->normalizeKeyedStrings($validated['change_reason_notes'] ?? []),
            'e1_not_material_explanation' => $validated['e1_not_material_explanation'] ?? null,
            'confirmed_at' => now()->toJSON(),
        ]);

        $characterization->forceFill(['form_data' => $formData])->save();

        return response()->json(['data' => $this->confirmationState($characterization->fresh(), $datapoints)]);
    }

    public function decisionSheet(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $state = $this->confirmationState($characterization, $datapoints);

        return response()->json(['data' => $this->decisionSheetState($characterization, $state)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmationState(Characterization $characterization, EsrsDatapointCorpusBuilder $datapoints): array
    {
        $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        $confirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        $confirmation = is_array($confirmation) ? $confirmation : [];
        $isConfirmed = array_key_exists('confirmed_topic_ids', $confirmation);
        $confirmedTopicIds = $this->topicIds(Arr::get($confirmation, 'confirmed_topic_ids', $p6TopicIds));
        $currentTopicIds = array_values(array_unique([
            ...$p6TopicIds,
            ...$confirmedTopicIds,
        ]));
        $delta = $this->delta($p6TopicIds, $confirmedTopicIds);
        $preview = $this->datapointPreview($characterization, $datapoints);

        return [
            'characterization_id' => $characterization->id,
            'is_confirmed' => $isConfirmed,
            'confirmation_status' => $isConfirmed
                ? self::CONFIRMATION_STATUS_CONFIRMED
                : self::CONFIRMATION_STATUS_DEFAULTED_FROM_P6,
            'p6_anchor_date' => $characterization->submitted_at?->toJSON() ?? $characterization->updated_at?->toJSON(),
            'p6_topic_ids' => $p6TopicIds,
            'confirmed_topic_ids' => $confirmedTopicIds,
            'delta' => $delta,
            'topics' => $this->topicSummaries(array_values(array_unique([
                ...$p6TopicIds,
                ...$confirmedTopicIds,
            ]))),
            'confirmation' => [
                'change_reasons' => $this->filterKeyedMap(Arr::get($confirmation, 'change_reasons', []), $currentTopicIds),
                'change_reason_notes' => $this->filterKeyedMap(Arr::get($confirmation, 'change_reason_notes', []), $currentTopicIds),
                'e1_not_material_explanation' => Arr::get($confirmation, 'e1_not_material_explanation'),
                'confirmed_at' => Arr::get($confirmation, 'confirmed_at'),
            ],
            'preview' => $preview,
        ];
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
            'p9_preview' => $preview,
            'e1_not_material_explanation' => $confirmation['e1_not_material_explanation'],
            'note' => $state['is_confirmed']
                ? 'These selections reflect the external double materiality assessment. Evidence remains outside the application.'
                : 'No final P8 confirmation has been stored yet. Values are defaulted from the P6 proposal for preview only.',
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
     * @param  array<string|int, mixed>  $values
     * @param  array<int, int>  $validTopicIds
     */
    private function validateReasonTopicKeys(array $values, array $validTopicIds, string $field): void
    {
        $validTopicKeys = array_map('strval', $validTopicIds);

        foreach (array_keys($values) as $topicId) {
            $topicKey = (string) $topicId;

            if (! preg_match('/^[1-9][0-9]*$/', $topicKey)
                || ! in_array($topicKey, $validTopicKeys, true)) {
                throw ValidationException::withMessages([
                    $field => 'Reason keys must be canonical topic IDs from the current P6/P8 topic set.',
                ]);
            }
        }
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
            ->filter(fn ($value, string|int $key) => isset($validTopicKeys[(string) $key]))
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
                'label' => 'Orientative standard-level estimate',
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
