<?php

namespace App\Services\Dma;

/** NLP adapter: leave the IRO implementation and its asset seal byte-identical. */
final class DmaNlpIroContextProjector
{
    public static function hasAssociations(array $evidence): bool
    {
        foreach ($evidence as $record) {
            foreach ($record['iro_reviews'] ?? [] as $review) {
                if (isset($review['associated_topic_ids'])) { return true; }
            }
        }
        return false;
    }

    public function project(array $evidence, array $corpus): array
    {
        $base = app(IroContextProjector::class);
        $candidates = array_map(fn ($items) => array_column($items, null, 'id'), $base->candidates($corpus));
        $reviews = [];
        foreach ($evidence as $record) {
            foreach ($record['iro_reviews'] ?? [] as $review) { $reviews[] = $review; }
        }
        usort($reviews, fn ($a, $b) => strcmp($a['iro_id'], $b['iro_id']));
        $inputs = [];
        foreach ($reviews as $review) {
            if ($review['decision'] !== 'material') { continue; }
            $ids = $review['focus_datapoint_ids']; sort($ids);
            foreach ($ids as $id) {
                $topicId = null;
                foreach ($review['associated_topic_ids'] ?? [$review['topic_id']] as $topic) {
                    if (isset($candidates[$topic][$id])) { $topicId = $topic; break; }
                }
                if ($topicId === null) { P8Authority::reject('iro_review_required'); }
                $inputs[] = array_replace($review, ['topic_id' => $topicId, 'focus_datapoint_ids' => [$id]]);
            }
        }
        // One IRO projection captures guidance once, regardless of shortlist size.
        $result = $base->project([['iro_reviews' => $inputs]], $corpus);
        $byIro = [];
        foreach ($result['cards'] as $card) {
            foreach ($card['links'] as $link) { $byIro[$card['review']['iro_id']][] = $link; }
        }
        $result['reviews'] = $reviews;
        $result['cards'] = [];
        foreach ($reviews as $review) {
            if ($review['decision'] !== 'material') { continue; }
            $result['cards'][] = ['review' => $review, 'links' => $byIro[$review['iro_id']] ?? [],
                'provenance_tier' => 'reviewed_iro_context', 'authoritative' => false];
        }
        return $result;
    }
}
