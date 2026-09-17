<?php
use App\Services\Report\XbrlConceptMap;

// Unit tests are plain PHPUnit\Framework\TestCase by default (see
// tests/Pest.php, which only binds Tests\TestCase for the Feature suite),
// so base_path() has no app() to resolve against unless this file opts in.
uses(Tests\TestCase::class);

// Note: the brief's illustrative example used 'E1-6_01' for
// esrs:GrossScope1GreenhouseGasEmissions, but per the real vendored
// Atomizer source (framework_datapoints.csv) that concept belongs to
// 'E1-6_07' ("Gross Scope 1 greenhouse gas emissions"); 'E1-6_01' is the
// narrative "GHG emissions per scope" datapoint, which has a blank
// XbrlConceptID in Atomizer and is therefore legitimately unmapped.
// Using the real mapped id here keeps the assertion factual instead of
// fabricating data to fit a mismatched example id.
it('resolves a mapped concept and reports checksum integrity', function () {
    $map = new XbrlConceptMap();

    expect($map->conceptFor('E1-6_07')['taggable_state'])->toBe('mapped');
    expect($map->conceptFor('E1-6_07')['concept_id'])->toStartWith('esrs:');
    expect($map->sha256())->toBe(trim(file_get_contents(base_path('data/atomizer_xbrl_concepts_v1.json.sha256'))));
    expect($map->version())->toBe('atomizer_xbrl_v1');
});

it('returns not_taggable for an unknown datapoint id', function () {
    expect((new XbrlConceptMap())->conceptFor('ZZ-9_99'))
        ->toBe(['concept_id' => null, 'taggable_state' => 'not_taggable', 'reason_code' => 'unknown_datapoint']);
});
