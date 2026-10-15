<?php

use App\Services\EuTaxonomyEligibility;

uses(Tests\TestCase::class);

it('pins a partial bilingual catalogue with per annex coverage and source NACE codes', function () {
    $catalogue = app(EuTaxonomyEligibility::class)->catalogue();
    expect($catalogue['complete'])->toBeFalse();
    foreach ($catalogue['coverage'] as $coverage) {
        $entries = array_filter($catalogue['activities'], fn ($row) => $row['source']['celex'] === $coverage['celex'] && $row['source']['annex'] === $coverage['annex']);
        expect(count($entries))->toBe($coverage['included_count']);
    }
    foreach ($catalogue['activities'] as $row) {
        expect($row['name']['es'])->not->toBeEmpty();
        expect($row['name']['en'])->not->toBeEmpty();
        foreach ($row['nace_codes'] as $code) {
            expect($row['nace_source_text']['en'])->toContain($code);
            expect($row['nace_source_text']['es'])->toContain($code);
        }
    }
});

it('maps verified CNAE2025 classes and never confuses revision or hierarchy', function () {
    $service = app(EuTaxonomyEligibility::class);
    expect($service->match('E36.0.0')['potential_activity_ids'])->toContain('32021R2139-I-5.1', '32023R2486-I-2.1');
    expect($service->match('E36.00', 'nace_rev21')['potential_activity_ids'])->toBe($service->match('E36.0.0')['potential_activity_ids']);
    expect($service->match('E36.00', 'nace_rev2')['potential_activity_ids'])->toBe($service->match('E36.0.0')['potential_activity_ids']);
    foreach (['E', 'E36', 'E36.0', 'F42.9.9', 'K62.1.0', 'C20.1.1', '<script>'] as $code) {
        expect($service->match($code)['status'])->toBe('manual_review_required');
        expect($service->match($code)['potential_activity_ids'])->toBe([]);
    }
    expect($service->match('F42.99', 'nace_rev2')['potential_activity_ids'])->toContain('32023R2486-I-2.1');
    expect($service->match('F42', 'nace_rev2')['potential_activity_ids'])->toBe([]);
    expect($service->match('E36.00', 'unknown')['potential_activity_ids'])->toBe([]);
});
