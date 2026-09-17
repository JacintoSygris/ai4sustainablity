<?php

it('passes report asset validation on the bundled data', function () {
    $this->artisan('report:validate-assets')->assertExitCode(0);
});

it('reports checksum mismatch as a hard failure', function () {
    $path = base_path('data/atomizer_xbrl_concepts_v1.json.sha256');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, str_repeat('0', 64));

        $this->artisan('report:validate-assets')
            ->expectsOutputToContain('checksum_mismatch')
            ->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
    }

    expect(file_get_contents($path))->toBe($original);
});

it('fails closed when the concept map is malformed instead of silently skipping reconciliation', function () {
    $path = base_path('data/atomizer_xbrl_concepts_v1.json');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, json_encode(['version' => 'x']));

        $this->artisan('report:validate-assets')
            ->expectsOutputToContain('malformed_concept_map')
            ->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
    }

    expect(file_get_contents($path))->toBe($original);
});
