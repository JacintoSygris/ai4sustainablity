<?php

use App\Services\Report\GuidanceLibrary;

// Unit tests are plain PHPUnit\Framework\TestCase by default (see
// tests/Pest.php, which only binds Tests\TestCase for the Feature suite),
// so base_path() has no app() to resolve against unless this file opts in
// (see the same note in XbrlConceptMapTest.php).
uses(Tests\TestCase::class);

it('returns certified guidance as authoritative with citations', function () {
    $g = (new GuidanceLibrary())->guidanceFor('E1-6_01', 'E1-6');

    expect($g['provenance_tier'])->toBe('certified_support_rule');
    expect($g['authoritative'])->toBeTrue();
    expect($g['citations'])->toContain('E1-6:AR39');
});

// S3-4 is certified in Atomizer's own registry, but this thin seed does
// not vendor it yet, so this only exercises this library's local
// generic-scaffold fallback path, not any claim about S3-4's real
// certification status upstream.
it('falls back to a non-authoritative generic scaffold for uncertified DRs', function () {
    $g = (new GuidanceLibrary())->guidanceFor('S3-4_02', 'S3-4');

    expect($g['provenance_tier'])->toBe('generic_scaffold');
    expect($g['authoritative'])->toBeFalse();
    expect($g['text'])->toContain('orientación general');
});

it('reports checksum integrity against the vendored sidecar', function () {
    expect((new GuidanceLibrary())->sha256())
        ->toBe(trim(file_get_contents(base_path('data/atomizer_support_rules_v1.json.sha256'))));
});
