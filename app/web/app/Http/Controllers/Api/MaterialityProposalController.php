<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaterialityProposalController extends Controller
{
    private const ACTION_KEYS = [
        'accepted',
        'rejected',
        'unsure',
    ];

    private const REASON_KEYS = [
        'sector_fit',
        'not_relevant',
        'threshold',
        'stakeholder_input',
        'needs_adm',
        'other',
    ];

    public function show(Request $request)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->proposalState($characterization)]);
    }

    public function update(Request $request)
    {
        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'topic_actions' => ['required', 'array'],
            'topic_actions.*' => ['string', Rule::in(self::ACTION_KEYS)],
            'action_reasons' => ['sometimes', 'array'],
            'action_reasons.*' => ['array'],
            'action_reasons.*.*' => ['string', Rule::in(self::REASON_KEYS)],
            'action_notes' => ['sometimes', 'array'],
            'action_notes.*' => ['nullable', 'string', 'max:300'],
        ]);

        $proposalTopicIds = $this->proposalTopicIds($characterization);
        $this->validateReviewPrecondition($characterization, $proposalTopicIds);
        $this->validateReviewTopicKeys($validated, $proposalTopicIds);

        $formData = $characterization->form_data ?? [];
        Arr::set($formData, 'materiality_proposal_review', [
            'topic_actions' => $this->normalizeKeyedStrings($validated['topic_actions']),
            'action_reasons' => $this->normalizeKeyedArrays($validated['action_reasons'] ?? []),
            'action_notes' => $this->normalizeKeyedStrings($validated['action_notes'] ?? []),
            'reviewed_at' => now()->toJSON(),
        ]);

        $characterization->forceFill(['form_data' => $formData])->save();

        return response()->json(['data' => $this->proposalState($characterization->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalState(Characterization $characterization): array
    {
        $resultData = $characterization->result_data ?? [];
        $hasAiCandidates = array_key_exists('candidate_topics', $resultData);
        $topicIds = $this->proposalTopicIds($characterization);
        $candidateTopics = Arr::get($resultData, 'candidate_topics', []);
        $rawPrediction = Arr::get($resultData, 'raw_prediction', []);
        $reviewRequiredPredictionKeys = Arr::get($resultData, 'review_required_prediction_keys', []);

        return [
            'characterization_id' => $characterization->id,
            'status' => $characterization->status,
            'source' => $hasAiCandidates ? 'ai_prediction' : 'stored_topic_ids',
            'proposal_topic_ids' => $topicIds,
            'proposal_topics' => $this->topicSummaries($topicIds),
            'ready_for_confirmation' => $characterization->status === Characterization::STATUS_COMPLETED
                && $topicIds !== [],
            'submitted_at' => $characterization->submitted_at?->toJSON(),
            'completed_at' => $characterization->completed_at?->toJSON(),
            'updated_at' => $characterization->updated_at?->toJSON(),
            'review' => $this->reviewState($characterization, $topicIds),
            'ai' => [
                'status' => Arr::get($resultData, 'status'),
                'summary' => Arr::get($resultData, 'summary'),
                'candidate_topics' => is_array($candidateTopics) ? $candidateTopics : [],
                'review_required_prediction_keys' => $this->stringList($reviewRequiredPredictionKeys),
                'raw_prediction_key_count' => is_array($rawPrediction) ? count($rawPrediction) : 0,
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function proposalTopicIds(Characterization $characterization): array
    {
        $topicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);

        if ($topicIds === [] && array_key_exists('candidate_topics', $characterization->result_data ?? [])) {
            return $this->candidateTopicIds($characterization->result_data ?? []);
        }

        return $topicIds;
    }

    /**
     * @param  array<int, int>  $proposalTopicIds
     * @return array<string, mixed>
     */
    private function reviewState(Characterization $characterization, array $proposalTopicIds): array
    {
        $review = Arr::get($characterization->form_data ?? [], 'materiality_proposal_review', []);
        if (! is_array($review) || $this->reviewPredatesCurrentProposal($characterization, $review)) {
            return $this->emptyReviewState();
        }

        $validTopicKeys = array_fill_keys(
            collect($proposalTopicIds)->map(fn (int $id) => (string) $id)->all(),
            true
        );
        $topicActions = $this->filterToCurrentTopicKeys(Arr::get($review, 'topic_actions', []), $validTopicKeys);
        $reviewedTopicIds = array_map('intval', array_keys($topicActions));
        $missingTopicIds = array_diff($proposalTopicIds, $reviewedTopicIds);

        return [
            'status' => match (true) {
                $topicActions === [] => 'not_started',
                $missingTopicIds === [] => 'reviewed',
                default => 'in_progress',
            },
            'topic_actions' => $topicActions,
            'action_reasons' => $this->filterToCurrentTopicKeys(Arr::get($review, 'action_reasons', []), $validTopicKeys),
            'action_notes' => $this->filterToCurrentTopicKeys(Arr::get($review, 'action_notes', []), $validTopicKeys),
            'reviewed_at' => Arr::get($review, 'reviewed_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReviewState(): array
    {
        return [
            'status' => 'not_started',
            'topic_actions' => [],
            'action_reasons' => [],
            'action_notes' => [],
            'reviewed_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private function reviewPredatesCurrentProposal(Characterization $characterization, array $review): bool
    {
        $reviewedAt = Arr::get($review, 'reviewed_at');

        if (! $characterization->completed_at) {
            return false;
        }

        if (! is_string($reviewedAt) || blank($reviewedAt)) {
            return true;
        }

        try {
            return Carbon::parse($reviewedAt)->lt($characterization->completed_at);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<int, mixed>  $values
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
     * @param  array<string, mixed>  $resultData
     * @return array<int, int>
     */
    private function candidateTopicIds(array $resultData): array
    {
        return collect(Arr::get($resultData, 'candidate_topics', []))
            ->filter(fn ($topic) => is_array($topic))
            ->map(fn (array $topic) => (int) Arr::get($topic, 'ar16_topic_id'))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, int>  $proposalTopicIds
     */
    private function validateReviewTopicKeys(array $validated, array $proposalTopicIds): void
    {
        $validTopicIds = collect($proposalTopicIds)->map(fn (int $id) => (string) $id)->all();
        $messages = [];

        foreach (['topic_actions', 'action_reasons', 'action_notes'] as $field) {
            foreach (array_keys($validated[$field] ?? []) as $topicId) {
                $canonicalTopicId = $this->canonicalTopicKey($topicId);

                if ($canonicalTopicId === null || ! in_array($canonicalTopicId, $validTopicIds, true)) {
                    $messages[$field.'.'.$topicId] = 'The selected topic must be part of the current P6 proposal.';
                }
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<int, int>  $proposalTopicIds
     */
    private function validateReviewPrecondition(Characterization $characterization, array $proposalTopicIds): void
    {
        if ($characterization->status === Characterization::STATUS_COMPLETED && $proposalTopicIds !== []) {
            return;
        }

        throw ValidationException::withMessages([
            'characterization' => 'The P6 materiality proposal must be completed before review actions can be stored.',
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $values
     * @return array<string, array<int, string>>
     */
    private function normalizeKeyedArrays(array $values): array
    {
        return collect($values)
            ->mapWithKeys(function (array $value, string|int $key) {
                $canonicalKey = $this->canonicalTopicKey($key);

                return $canonicalKey === null ? [] : [$canonicalKey => array_values($value)];
            })
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
            ->mapWithKeys(function ($value, string|int $key) {
                $canonicalKey = $this->canonicalTopicKey($key);

                return $canonicalKey === null ? [] : [$canonicalKey => (string) $value];
            })
            ->all();
    }

    /**
     * @param  array<string, bool>  $validTopicKeys
     * @return array<string, mixed>
     */
    private function filterToCurrentTopicKeys(mixed $values, array $validTopicKeys): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->mapWithKeys(function (mixed $value, string|int $key) use ($validTopicKeys) {
                $canonicalKey = $this->canonicalTopicKey($key);

                if ($canonicalKey === null || ! isset($validTopicKeys[$canonicalKey])) {
                    return [];
                }

                return [$canonicalKey => $value];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn ($value) => is_string($value) && filled($value))
            ->values()
            ->all();
    }

    private function canonicalTopicKey(string|int $key): ?string
    {
        if (is_int($key)) {
            return $key > 0 ? (string) $key : null;
        }

        return preg_match('/^[1-9][0-9]*$/', $key) === 1 ? $key : null;
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
