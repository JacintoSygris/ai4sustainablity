<?php

use App\Services\Report\ReportClaimBuilder;
use App\Services\Report\ReportContentReadiness;

function readinessFact(array $overrides = []): array
{
    return array_replace([
        'fact_id' => 'rf_default',
        'datapoint_id' => 'BP-1_01',
        'approval_status' => 'reviewed',
        'applicability' => 'applicable',
        'blocking_reasons' => [],
        'nil' => false,
        'value' => ['text' => 'Información empresarial revisada.'],
        'value_type' => 'text',
    ], $overrides);
}

it('reports missing persisted and claimable content deterministically', function () {
    $assessment = (new ReportContentReadiness(new ReportClaimBuilder))->assess([], ['BP-1_02', 'BP-1_01', 'BP-1_01']);

    expect($assessment)->toMatchArray([
        'ready' => false,
        'claimable_count' => 0,
        'required_count' => 2,
        'resolved_required_count' => 0,
        'missing_datapoint_ids' => ['BP-1_01', 'BP-1_02'],
        'reasons' => ['no_persisted_facts', 'completed_datapoint_facts_missing'],
    ]);
});

it('reports partial coverage without claiming that no content exists', function () {
    $assessment = (new ReportContentReadiness(new ReportClaimBuilder))->assess(
        [readinessFact()],
        ['BP-1_02', 'BP-1_01'],
    );

    expect($assessment)->toMatchArray([
        'ready' => false,
        'claimable_count' => 1,
        'required_count' => 2,
        'resolved_required_count' => 1,
        'missing_datapoint_ids' => ['BP-1_02'],
        'reasons' => ['completed_datapoint_facts_missing'],
    ]);
});

it('rejects not applicable, unreviewed and blocked facts as report content', function (array $fact, array $expectedReasons) {
    $assessment = (new ReportContentReadiness(new ReportClaimBuilder))->assess([$fact]);

    expect($assessment['ready'])->toBeFalse()
        ->and($assessment['claimable_count'])->toBe(0)
        ->and($assessment['reasons'])->toBe($expectedReasons);
})->with([
    'not applicable' => [
        readinessFact(['applicability' => 'not_applicable', 'value' => null]),
        ['no_claimable_report_content'],
    ],
    'unreviewed' => [
        readinessFact(['approval_status' => 'review_required']),
        ['fact_status_not_reviewed', 'no_claimable_report_content'],
    ],
    'blocked' => [
        readinessFact(['blocking_reasons' => ['missing_evidence']]),
        ['fact_blocking_reasons_present', 'no_claimable_report_content'],
    ],
]);

it('deduplicates claims by datapoint when calculating required coverage', function () {
    $assessment = (new ReportContentReadiness(new ReportClaimBuilder))->assess([
        readinessFact(['fact_id' => 'rf_a']),
        readinessFact(['fact_id' => 'rf_b']),
    ], ['BP-1_01', 'BP-1_01']);

    expect($assessment)->toMatchArray([
        'ready' => true,
        'claimable_count' => 2,
        'required_count' => 1,
        'resolved_required_count' => 1,
        'missing_datapoint_ids' => [],
        'reasons' => [],
    ]);
});

it('ignores blocked historical facts outside the current completed scope', function () {
    $assessment = (new ReportContentReadiness(new ReportClaimBuilder))->assess([
        readinessFact(['fact_id' => 'rf_current', 'datapoint_id' => 'BP-1_01']),
        readinessFact([
            'fact_id' => 'rf_historical',
            'datapoint_id' => 'BP-1_02',
            'approval_status' => 'review_required',
            'applicability' => 'blocked',
            'blocking_reasons' => ['scope_removed'],
        ]),
    ], ['BP-1_01']);

    expect($assessment)->toMatchArray([
        'ready' => true,
        'claimable_count' => 1,
        'required_count' => 1,
        'resolved_required_count' => 1,
        'missing_datapoint_ids' => [],
        'reasons' => [],
    ]);
});
