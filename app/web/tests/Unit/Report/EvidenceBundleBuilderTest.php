<?php

use App\Services\Report\EvidenceBundleBuilder;

it('summarizes unmapped concepts and guidance provenance from the IR', function () {
    $ir = [
        'version_hash' => str_repeat('a', 64),
        'asset_versions' => ['ig3' => '2025-06', 'xbrl' => 'atomizer_xbrl_v1'],
        'chapters' => [[
            'sections' => [[
                'dr_key' => 'S3-4',
                'blocks' => [[
                    'datapoint_id' => 'S3-4_02',
                    'slots' => [['node_id' => 'slot_S3-4_02', 'taggable_state' => 'unmapped']],
                    'guidance' => ['provenance_tier' => 'generic_scaffold'],
                ]],
            ]],
        ]],
    ];

    $bundle = (new EvidenceBundleBuilder())->build($ir);

    expect($bundle['type'])->toBe('report_evidence_bundle');
    expect($bundle['ir_version_hash'])->toBe(str_repeat('a', 64));
    expect($bundle['unmapped_concepts'])->toContain('S3-4_02');
    expect($bundle['guidance_provenance']['S3-4'])->toBe('generic_scaffold');
});

it('collapses a section provenance to the least authoritative tier among its blocks', function () {
    $ir = [
        'version_hash' => str_repeat('a', 64),
        'asset_versions' => [],
        'chapters' => [[
            'sections' => [[
                'dr_key' => 'S3-4',
                'blocks' => [
                    [
                        'datapoint_id' => 'S3-4_01',
                        'slots' => [['node_id' => 'slot_S3-4_01', 'taggable_state' => 'mapped']],
                        'guidance' => ['provenance_tier' => 'certified_support_rule'],
                    ],
                    [
                        'datapoint_id' => 'S3-4_02',
                        'slots' => [['node_id' => 'slot_S3-4_02', 'taggable_state' => 'unmapped']],
                        'guidance' => ['provenance_tier' => 'generic_scaffold'],
                    ],
                ],
            ]],
        ]],
    ];

    $bundle = (new EvidenceBundleBuilder())->build($ir);

    // The section has one certified_support_rule block and one
    // generic_scaffold block; it must be reported at the least authoritative
    // tier (generic_scaffold), never the more authoritative one.
    expect($bundle['guidance_provenance']['S3-4'])->toBe('generic_scaffold');
});

it('carries the materiality trace, including the verbatim free-text note', function () {
    $ir = ['version_hash' => str_repeat('a', 64), 'asset_versions' => [], 'chapters' => []];
    $trace = [
        'omitted_topics' => [[
            'topic_id' => 7, 'esrs_code' => 'E3', 'theme_es' => 'Agua',
            'evidence_grade' => 'inferred_from_snapshot_delta',
            'change_reasons' => ['threshold'], 'change_reason_note' => 'Nota interna',
        ]],
        'inferred_omissions_status' => 'determinable',
        'unresolved_omitted_topic_ids' => [], 'contradictory_topic_ids' => [],
        'is_stale' => true, 'confirmed_at' => '2026-01-01T00:00:00Z', 'captured_at' => '2026-01-01T00:00:00Z',
        'snapshot_topic_ids' => [7], 'current_p6_topic_ids' => [], 'confirmation_history_available' => false,
    ];

    $bundle = (new EvidenceBundleBuilder())->build($ir, $trace);

    expect($bundle['materiality_trace']['is_stale'])->toBeTrue();
    expect($bundle['materiality_trace']['omitted_topics'][0]['change_reason_note'])->toBe('Nota interna');
    expect($bundle['materiality_trace']['confirmation_history_available'])->toBeFalse();
});

it('keeps a bundle without a trace valid', function () {
    $bundle = (new EvidenceBundleBuilder())
        ->build(['version_hash' => str_repeat('a', 64), 'asset_versions' => [], 'chapters' => []]);

    expect($bundle['materiality_trace'])->toBe([]);
});
