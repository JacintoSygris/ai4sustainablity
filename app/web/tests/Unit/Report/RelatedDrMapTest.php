<?php

use App\Services\Report\RelatedDrMap;

// Unit tests are plain PHPUnit\Framework\TestCase by default (see tests/Pest.php,
// which only binds Tests\TestCase for the Feature suite), so base_path() has no
// app() to resolve against unless this file opts in.
uses(Tests\TestCase::class);

it('marks an edge in_scope when the target DR is present', function () {
    $edges = (new RelatedDrMap())->resolveEdges('E1-1', ['GOV-1', 'E1-1']);

    expect($edges[0]['target_dr'])->toBe('GOV-1');
    expect($edges[0]['resolution'])->toBe('in_scope');
});

it('marks an edge omitted when the target DR is out of scope', function () {
    $edges = (new RelatedDrMap())->resolveEdges('E1-1', ['E1-1']);

    expect($edges[0]['resolution'])->toBe('omitted');
});

it('returns an empty list for a DR with no edges', function () {
    expect((new RelatedDrMap())->resolveEdges('G1-2', ['G1-2']))->toBe([]);
});

it('reports the vendored version and checksum integrity', function () {
    $map = new RelatedDrMap();

    expect($map->version())->toBe('related_dr_map_v1');
    expect($map->sha256())->toBe(trim(file_get_contents(base_path('data/related_dr_map_esrs2023_v1.json.sha256'))));
});
