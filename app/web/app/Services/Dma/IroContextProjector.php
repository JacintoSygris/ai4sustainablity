<?php

namespace App\Services\Dma;

use App\Services\Report\GuidanceLibrary;

/** Additive presentation only. Never filters or changes normative datapoints. */
final class IroContextProjector
{
    public const VERSION = 'iro_context_v1';

    public function candidates(array $corpus): array
    {
        $points = [];
        foreach ($corpus['blocks'] as $block) {
            foreach ($block['datapoints'] ?? [] as $point) { $points[$point['id']] = $point; }
        }
        ksort($points);
        $result = [];
        foreach ($corpus['matter_mapping']['material_topics'] as $topic) {
            $result[$topic['topic_id']] = [];
            foreach ($points as $point) {
                $cross = in_array($point['dr'], ['SBM-3', 'IRO-1'], true);
                if ($cross || ($point['standard'] === $topic['esrs_code']
                    && in_array($point['dr'], $topic['mapped_disclosure_requirement_keys'] ?? [], true))) {
                    $result[$topic['topic_id']][] = [
                        'id' => $point['id'], 'dr' => $point['dr'], 'esrs' => $point['standard'],
                        'name' => $point['display']['name'] ?? $point['name'], 'transversal' => $cross,
                    ];
                }
            }
        }
        return $result;
    }

    public function project(array $evidence, array $corpus): array
    {
        $candidates = array_map(fn ($items) => array_column($items, null, 'id'), $this->candidates($corpus));
        $guidance = app(GuidanceLibrary::class)->capture();
        $reviews = []; $cards = []; $annotations = [];
        foreach ($evidence as $record) {
            foreach ($record['iro_reviews'] ?? [] as $review) { $reviews[] = $review; }
        }
        usort($reviews, fn ($a, $b) => strcmp($a['iro_id'], $b['iro_id']));
        foreach ($reviews as $review) {
            if ($review['decision'] !== 'material') { continue; }
            $links = [];
            $ids = $review['focus_datapoint_ids']; sort($ids);
            foreach ($ids as $id) {
                $point = $candidates[$review['topic_id']][$id] ?? null;
                if ($point === null) { P8Authority::reject('iro_review_required'); }
                $questions = [];
                foreach ($this->questions($review, $point['dr']) as $key => $text) {
                    $questions[] = ['id' => $review['iro_id'].'|'.$id.'|'.$key, 'rule_key' => $key, 'text' => $text];
                }
                $link = ['iro_id' => $review['iro_id'], 'topic_id' => $review['topic_id'],
                    'datapoint_id' => $id, 'dr' => $point['dr'], 'esrs' => $point['esrs'],
                    'transversal' => $point['transversal'], 'questions' => $questions,
                    'guidance' => $guidance->guidanceFor($id, $point['dr'])];
                $links[] = $link;
                $annotations[$id][] = $link;
            }
            $cards[] = ['review' => $review, 'links' => $links, 'provenance_tier' => 'reviewed_iro_context', 'authoritative' => false];
        }
        ksort($annotations);
        return ['schema_version' => self::VERSION, 'rules_version' => self::VERSION, 'guidance_sha256' => $guidance->sha256(),
            'reviews' => $reviews, 'cards' => $cards, 'by_datapoint' => $annotations];
    }

    private function questions(array $review, string $dr): array
    {
        $questions = [];
        $group = $review['group']; $channel = $review['channel'];
        if ($review['type'] === 'impact' || in_array($review['dimension'], ['impact', 'both'], true)) {
            $questions['impact'] = ['es' => "¿Qué efecto produce este impacto sobre {$group} y qué datos y evidencias lo describen?",
                'en' => "What effect does this impact have on {$group}, and what data and evidence describe it?"];
        }
        if ($review['type'] !== 'opportunity' && ($review['type'] === 'risk' || in_array($review['dimension'], ['financial', 'both'], true))) {
            $questions['financial_exposure'] = ['es' => "¿Qué exposición y efectos financieros negativos se vinculan al canal {$channel}?",
                'en' => "What exposure and negative financial effects relate to the {$channel} channel?"];
        }
        if ($review['type'] === 'opportunity') {
            $questions['financial_benefits'] = ['es' => "¿Qué beneficios financieros potenciales se vinculan al canal {$channel}?",
                'en' => "What potential financial benefits relate to the {$channel} channel?"];
        }
        $locationRules = [
            'own_operations' => ['es' => 'Delimita los centros y procesos de las operaciones propias afectados.', 'en' => 'Define the affected sites and processes in own operations.'],
            'upstream' => ['es' => 'Delimita los proveedores y actividades de la cadena de valor anterior afectados.', 'en' => 'Define the affected suppliers and upstream value chain activities.'],
            'downstream' => ['es' => 'Delimita los clientes y el uso del producto afectados en la cadena de valor posterior.', 'en' => 'Define the affected customers and product use in the downstream value chain.'],
        ];
        foreach ($review['locations'] as $location) { $questions[$location] = $locationRules[$location]; }
        foreach ($review['groups'] as $kind) {
            $questions[$kind] = $kind === 'internal'
                ? ['es' => '¿Qué personas o colectivos internos se ven afectados y qué consultas lo sustentan?', 'en' => 'Which internal people or groups are affected, and which consultations support this?']
                : ['es' => '¿Qué personas o colectivos externos se ven afectados y qué consultas lo sustentan?', 'en' => 'Which external people or groups are affected, and which consultations support this?'];
        }
        if ($dr === 'SBM-3') {
            $questions['strategy'] = ['es' => '¿Cómo se relaciona este IRO con la estrategia y el modelo de negocio?', 'en' => 'How does this IRO relate to the strategy and business model?'];
        }
        if ($dr === 'IRO-1') {
            $questions['method'] = ['es' => '¿Qué método, evidencias y consultas sustentan la identificación de este IRO?', 'en' => 'What method, evidence and consultations support identifying this IRO?'];
        }
        ksort($questions);
        return $questions;
    }
}
