<?php

use App\Services\Report\ReportClaimBuilder;

it('builds a claim for a reviewed applicable fact preserving dimensions and evidence', function () {
    $claims = (new ReportClaimBuilder())->build([
        reportClaimBuilderFact([
            'fact_id' => 'rf_reviewed',
            'dimensions' => [
                ['axis' => 'region', 'member' => 'EU'],
                ['member' => 'SME', 'axis' => 'segment'],
            ],
            'evidence_refs' => [
                ['type' => 'document', 'ref' => 'board-minutes'],
            ],
        ]),
    ], 'snapshot_abc', 'profile_123');

    expect($claims)->toHaveCount(1);
    expect($claims[0])->toMatchArray([
        'schema_version' => 'report_claim_v1',
        'fact_id' => 'rf_reviewed',
        'datapoint_id' => 'BP-1_01',
        'value_type' => 'text',
        'value' => ['text' => 'Reviewed approved fact'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [
            ['axis' => 'region', 'member' => 'EU'],
            ['axis' => 'segment', 'member' => 'SME'],
        ],
        'language' => 'en',
        'applicability' => 'applicable',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [
            ['ref' => 'board-minutes', 'type' => 'document'],
        ],
        'approval_status' => 'reviewed',
        'provenance' => [
            'source' => 'reporting_fact_v1',
            'snapshot_hash' => 'snapshot_abc',
            'profile_id' => 'profile_123',
        ],
    ]);
    expect($claims[0]['claim_id'])->toStartWith('claim_');
});

it('excludes non-affirmable facts from narrative claims', function (array $overrides) {
    $claims = (new ReportClaimBuilder())->build([
        reportClaimBuilderFact($overrides),
    ], 'snapshot_abc', 'profile_123');

    expect($claims)->toBe([]);
})->with([
    'review_required' => [['approval_status' => 'review_required']],
    'blocked' => [['approval_status' => 'blocked']],
    'blocking_reasons' => [['blocking_reasons' => ['missing_evidence']]],
    'nil without value' => [['nil' => true, 'value' => null, 'nil_reason' => 'not_available']],
]);

it('is deterministic regardless of fact and key order', function () {
    $builder = new ReportClaimBuilder();

    $claims = $builder->build([
        reportClaimBuilderFact([
            'fact_id' => 'rf_b',
            'datapoint_id' => 'BP-1_02',
            'dimensions' => [['member' => 'B', 'axis' => 'segment']],
            'evidence_refs' => [['ref' => 'b', 'type' => 'note']],
        ]),
        reportClaimBuilderFact([
            'evidence_refs' => [['type' => 'note', 'ref' => 'a']],
            'dimensions' => [['axis' => 'segment', 'member' => 'A']],
            'datapoint_id' => 'BP-1_01',
            'fact_id' => 'rf_a',
        ]),
    ], 'snapshot_abc', 'profile_123');

    $claimsReordered = $builder->build([
        reportClaimBuilderFact([
            'fact_id' => 'rf_a',
            'datapoint_id' => 'BP-1_01',
            'dimensions' => [['member' => 'A', 'axis' => 'segment']],
            'evidence_refs' => [['ref' => 'a', 'type' => 'note']],
        ]),
        reportClaimBuilderFact([
            'fact_id' => 'rf_b',
            'datapoint_id' => 'BP-1_02',
            'evidence_refs' => [['type' => 'note', 'ref' => 'b']],
            'dimensions' => [['axis' => 'segment', 'member' => 'B']],
        ]),
    ], 'snapshot_abc', 'profile_123');

    expect(array_column($claims, 'fact_id'))->toBe(['rf_a', 'rf_b']);
    expect(array_column($claims, 'claim_id'))->toBe(array_column($claimsReordered, 'claim_id'));
});

function reportClaimBuilderFact(array $overrides = []): array
{
    return array_replace([
        'fact_id' => 'rf_default',
        'schema_version' => 'reporting_fact_v1',
        'profile_id' => 'esrs-2023-preparatory-v1',
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Reviewed approved fact'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [],
        'approval_status' => 'reviewed',
        'blocking_reasons' => [],
    ], $overrides);
}
