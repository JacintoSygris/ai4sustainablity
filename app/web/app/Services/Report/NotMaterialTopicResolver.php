<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use Illuminate\Support\Arr;

/**
 * Resolves which ESRS topics the user assessed and did NOT consider material.
 *
 * The report asserts a HUMAN ACT, so every omitted topic must trace to a stored
 * field, and the strength of that field decides what the report may claim:
 *
 *  - direct   -> guided_answers[t].final_result === 'no_material': a recorded verdict.
 *  - inferred -> t was in the FROZEN p6_snapshot and was not confirmed: proves only
 *                non-confirmation. The user may never have opened it.
 *
 * The live `esrs_topic_ids` is never used to infer an omission: P6 re-runs after the
 * confirmation, so a topic it proposes today may never have been seen by the user.
 */
class NotMaterialTopicResolver
{
    public const GRADE_DIRECT = 'direct_guided_answer';
    public const GRADE_INFERRED = 'inferred_from_snapshot_delta';

    public const STATUS_DETERMINABLE = 'determinable';
    public const STATUS_NOT_DETERMINABLE = 'not_determinable_absent_p6_snapshot';
    public const STATUS_NOT_CONFIRMED = 'not_confirmed';

    /** @return array<string, mixed> */
    public function resolve(Characterization $characterization): array
    {
        $confirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        $confirmation = is_array($confirmation) ? $confirmation : [];

        // Degraded states are declared, never silent: a present-but-null or scalar
        // stored value must fall back to empty, not crash report generation.
        $liveTopicIds = $characterization->esrs_topic_ids ?? [];
        $liveIds = $this->ids(is_array($liveTopicIds) ? $liveTopicIds : []);
        $isConfirmed = array_key_exists('confirmed_topic_ids', $confirmation);
        $confirmedTopicIds = Arr::get($confirmation, 'confirmed_topic_ids', []);
        $confirmedIds = $this->ids(is_array($confirmedTopicIds) ? $confirmedTopicIds : []);

        $snapshotIds = Arr::get($confirmation, 'p6_snapshot.topic_ids');
        $hasSnapshot = is_array($snapshotIds);
        $snapshotIds = $hasSnapshot ? $this->ids($snapshotIds) : [];

        $guidedNo = [];
        $guidedAnswers = Arr::get($confirmation, 'guided_answers', []);
        // Degraded states are declared, never silent: a present-but-scalar stored
        // value must fall back to empty, not crash report generation.
        foreach (is_array($guidedAnswers) ? $guidedAnswers : [] as $topicId => $answer) {
            if (is_array($answer) && ($answer['final_result'] ?? null) === 'no_material') {
                $guidedNo[] = (int) $topicId;
            }
        }
        $guidedNo = array_values(array_unique($guidedNo));

        // A confirmed-material topic that also carries a no_material verdict is
        // contradictory stored state. The confirmation is the stronger decision:
        // exclude it from the omissions and surface the contradiction instead.
        $contradictory = array_values(array_intersect($guidedNo, $confirmedIds));

        $direct = array_values(array_diff($guidedNo, $confirmedIds));
        $inferred = array_values(array_diff($snapshotIds, $confirmedIds, $direct));

        $grades = [];
        foreach ($direct as $id) {
            $grades[$id] = self::GRADE_DIRECT;
        }
        foreach ($inferred as $id) {
            $grades[$id] = self::GRADE_INFERRED;
        }

        $ordered = [...$direct, ...$inferred];
        $topics = EsrsTopic::whereIn('id', $ordered)->get()->keyBy('id');

        $omitted = [];
        $unresolved = [];
        foreach ($ordered as $id) {
            $topic = $topics->get($id);

            if (! $topic instanceof EsrsTopic) {
                $unresolved[] = $id;

                continue;
            }

            $omitted[] = [
                'topic_id' => $id,
                'esrs_code' => $topic->esrs_code,
                'theme_es' => $topic->theme_es,
                'label' => $this->label($topic),
                'evidence_grade' => $grades[$id],
                'change_reasons' => array_values((array) Arr::get($confirmation, 'change_reasons.'.$id, [])),
                'change_reason_note' => Arr::get($confirmation, 'change_reason_notes.'.$id),
            ];
        }

        return [
            'omitted_topics' => $omitted,
            'inferred_omissions_status' => match (true) {
                ! $isConfirmed => self::STATUS_NOT_CONFIRMED,
                ! $hasSnapshot => self::STATUS_NOT_DETERMINABLE,
                default => self::STATUS_DETERMINABLE,
            },
            'unresolved_omitted_topic_ids' => $unresolved,
            'contradictory_topic_ids' => $contradictory,
            'is_stale' => $hasSnapshot && $this->sorted($snapshotIds) !== $this->sorted($liveIds),
            'confirmed_at' => Arr::get($confirmation, 'confirmed_at'),
            'captured_at' => Arr::get($confirmation, 'p6_snapshot.captured_at'),
            'snapshot_topic_ids' => $snapshotIds,
            'current_p6_topic_ids' => $liveIds,
            'confirmation_history_available' => false,
        ];
    }

    /**
     * `theme_es` is the standard-level name and is NOT unique — all five E3 topics are
     * "Agua y recursos marinos" and four also share the subtheme "Agua". Only `subtopic_es`
     * separates them. Keying an omission sentence on the theme would print it five times.
     */
    private function label(EsrsTopic $topic): string
    {
        $qualifier = filled($topic->subtopic_es) ? $topic->subtopic_es : $topic->subtheme_es;

        return filled($qualifier) ? "{$topic->theme_es}: {$qualifier}" : (string) $topic->theme_es;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }
}
