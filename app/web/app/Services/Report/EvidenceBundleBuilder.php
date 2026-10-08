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
    public function build(array $ir, array $materialityTrace = [], string $locale = 'es'): array
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

        $bundle = [
            'type' => 'report_evidence_bundle',
            'version' => 'v1',
            'ir_version_hash' => $ir['version_hash'] ?? null,
            'asset_versions' => $ir['asset_versions'] ?? [],
            'unmapped_concepts' => array_values(array_unique($unmapped)),
            'guidance_provenance' => $provenance,
            'materiality_trace' => $materialityTrace !== []
                ? $materialityTrace
                : ($ir['materiality_trace'] ?? []),
        ];

        if (($ir['schema_version'] ?? null) === 'report_ir_v1') {
            $bundle['approved_snapshot'] = $ir['source'] ?? [];
            $bundle['claims'] = $ir['claims'] ?? [];
            $bundle['fact_decisions'] = $ir['fact_decisions'] ?? [];
            $bundle['claim_evidence_index'] = $this->claimEvidenceIndex($bundle['claims']);
        }

        $display = new ReportDisplayProjection($locale);
        $bundle['display'] = [
            'locale' => $display->locale,
            'narrative' => $display->narrative($ir),
            'claim_labels' => array_map(fn (array $claim) => [
                'claim_id' => $claim['claim_id'] ?? null,
                'label' => $display->claimLabel($claim['datapoint_id'] ?? null, $claim),
            ], $ir['claims'] ?? []),
        ];

        return $bundle;
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

    /**
     * @param  mixed  $claims
     * @return array<string, mixed>
     */
    private function claimEvidenceIndex(mixed $claims): array
    {
        if (! is_array($claims)) {
            return [];
        }

        $index = [];
        foreach ($claims as $claim) {
            if (! is_array($claim) || ! isset($claim['claim_id'])) {
                continue;
            }

            $index[(string) $claim['claim_id']] = $claim['evidence_refs'] ?? [];
        }

        ksort($index);

        return $index;
    }
}
