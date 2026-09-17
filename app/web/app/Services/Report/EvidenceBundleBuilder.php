<?php

namespace App\Services\Report;

class EvidenceBundleBuilder
{
    /**
     * Provenance tiers ordered least → most authoritative. When a section has
     * multiple blocks with differing tiers, the section is reported at the
     * LEAST authoritative tier among them, so it is never overstated relative
     * to its weakest-sourced block.
     *
     * @var array<int, string>
     */
    private const PROVENANCE_RANK = [
        'pending_review',
        'generic_scaffold',
        'reviewed_plain_language',
        'certified_support_rule',
    ];

    /**
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $materialityTrace  NotMaterialTopicResolver::resolve() output.
     *                                                  Carries the verbatim change_reason_note, which
     *                                                  must never appear in the rendered DOCX.
     * @return array<string, mixed>
     */
    public function build(array $ir, array $materialityTrace = []): array
    {
        $unmapped = [];
        $provenance = [];

        foreach ($ir['chapters'] ?? [] as $chapter) {
            foreach ($chapter['sections'] ?? [] as $section) {
                foreach ($section['blocks'] ?? [] as $block) {
                    foreach ($block['slots'] ?? [] as $slot) {
                        if (($slot['taggable_state'] ?? null) !== 'mapped') {
                            $unmapped[] = $block['datapoint_id'];
                        }
                    }

                    $tier = $block['guidance']['provenance_tier'] ?? 'pending_review';
                    $provenance[$section['dr_key']] = isset($provenance[$section['dr_key']])
                        ? $this->leastAuthoritative($provenance[$section['dr_key']], $tier)
                        : $tier;
                }
            }
        }

        return [
            'type' => 'report_evidence_bundle',
            'version' => 'v1',
            'ir_version_hash' => $ir['version_hash'] ?? null,
            'asset_versions' => $ir['asset_versions'] ?? [],
            'unmapped_concepts' => array_values(array_unique($unmapped)),
            'guidance_provenance' => $provenance,
            'materiality_trace' => $materialityTrace,
        ];
    }

    private function leastAuthoritative(string $a, string $b): string
    {
        $rankA = array_search($a, self::PROVENANCE_RANK, true);
        $rankB = array_search($b, self::PROVENANCE_RANK, true);

        // Unknown tiers are treated as the least authoritative (rank -1),
        // so an unrecognized provenance tier never masks a weaker known one.
        $rankA = $rankA === false ? -1 : $rankA;
        $rankB = $rankB === false ? -1 : $rankB;

        return $rankA <= $rankB ? $a : $b;
    }
}
