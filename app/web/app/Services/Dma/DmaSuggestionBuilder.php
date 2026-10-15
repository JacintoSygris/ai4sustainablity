<?php

namespace App\Services\Dma;

use Illuminate\Validation\ValidationException;

final class DmaSuggestionBuilder
{
    public function __construct(private readonly DmaCsvParser $parser) {}

    public function build(array $iros, array $consultations): array
    {
        $groups = []; $byRow = [];
        foreach ($iros as $row) {
            $groups[$row['topic_id']][] = $row;
            $byRow[$row['row']] = $row['topic_id'];
        }
        $linked = []; $errors = [];
        foreach ($consultations as $row) {
            $topicId = $row['iro_row'] !== null ? ($byRow[$row['iro_row']] ?? null) : $row['topic_id'];
            if ($topicId === null || ! isset($groups[$topicId])) {
                $errors['stakeholder_file.row.'.$row['row'].'.matter_or_iro'] = __('The consultation must link to a topic or IRO row in this upload.');
            } else {
                $row['topic_id'] = $topicId;
                $linked[$topicId][] = $row;
            }
        }
        if ($errors !== []) { throw ValidationException::withMessages($errors); }
        ksort($groups);
        $suggestions = [];
        foreach ($groups as $topicId => $rows) {
            $verdicts = []; $conflict = false; $impact = false; $financial = false;
            $impactScores = []; $financialScores = [];
            $impactFlags = []; $financialFlags = [];
            foreach ($rows as $row) {
                $verdict = $this->parser->verdict($row['threshold_result']);
                $i = $this->parser->flag($row['impact_materiality_score']);
                $f = $this->parser->flag($row['financial_materiality_score']);
                $impactFlags[] = $i; $financialFlags[] = $f;
                $conflict = $conflict || ($verdict === 'no_material' && ($i === true || $f === true))
                    || ($verdict === 'material' && $i === false && $f === false);
                $impact = $impact || ($verdict === 'material' && $i === true);
                $financial = $financial || ($verdict === 'material' && $f === true);
                $impactScore = $this->parser->score($row['impact_materiality_score']);
                $financialScore = $this->parser->score($row['financial_materiality_score']);
                if ($impactScore !== null) { $impactScores[] = $impactScore; }
                if ($financialScore !== null) { $financialScores[] = $financialScore; }
                $verdicts[] = $verdict;
            }
            $verdict = $conflict ? 'review' : (in_array('material', $verdicts, true) ? 'material'
                : (in_array('review', $verdicts, true) ? 'review' : 'no_material'));
            $stakeholders = $linked[$topicId] ?? [];
            $suggestions[] = [
                'topic_id' => (int) $topicId,
                'suggestion' => $verdict,
                'reason_code' => $conflict ? 'conflicting_flags' : ($verdict === 'material' ? 'at_least_one_material_iro'
                    : ($verdict === 'no_material' ? 'all_iros_not_material' : 'incomplete_assessment')),
                'dimension' => $impact && $financial ? 'both' : ($impact ? 'impact' : ($financial ? 'financial' : null)),
                'iro_count' => count($rows),
                'iro_rows' => array_column($rows, 'row'),
                'supporting_iro_rows' => array_column(array_values(array_filter($rows, function ($row) use ($verdict, $conflict) {
                    $result = $this->parser->verdict($row['threshold_result']);
                    if (! $conflict) { return $result === $verdict; }
                    $i = $this->parser->flag($row['impact_materiality_score']);
                    $f = $this->parser->flag($row['financial_materiality_score']);
                    return ($result === 'no_material' && ($i === true || $f === true))
                        || ($result === 'material' && $i === false && $f === false);
                })), 'row'),
                'impact_scores' => $impactScores,
                'financial_scores' => $financialScores,
                'impact_flags' => $impactFlags,
                'financial_flags' => $financialFlags,
                'stakeholder_count' => count($stakeholders),
                'stakeholder_support_count' => count(array_filter($stakeholders, fn ($row) => $this->parser->verdict($row['decision_effect']) === 'material')),
                'stakeholder_opposition_count' => count(array_filter($stakeholders, fn ($row) => $this->parser->verdict($row['decision_effect']) === 'no_material')),
                'stakeholder_rows' => array_column($stakeholders, 'row'),
            ];
        }
        return ['schema_version' => 'dma_import_v1', 'iro_rows' => $iros,
            'stakeholder_rows' => array_merge([], ...array_values($linked)), 'suggestions' => $suggestions];
    }
}
