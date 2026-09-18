<?php

it('passes the report asset gates on the committed vendored data', function () {
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

it('reports profile checksum mismatch as a hard failure', function () {
    $path = base_path('data/reporting_profiles/esrs-2023-preparatory-v1.json.sha256');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, str_repeat('0', 64));

        $this->artisan('report:validate-assets')
            ->expectsOutputToContain('profile_checksum_mismatch')
            ->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
    }

    expect(file_get_contents($path))->toBe($original);
});

it('fails if the reporting profile uses non-external taxonomy provisioning', function () {
    $path = base_path('data/reporting_profiles/esrs-2023-preparatory-v1.json');
    $shaPath = $path.'.sha256';
    $original = file_get_contents($path);
    $originalSha = file_get_contents($shaPath);

    try {
        $mutated = str_replace(
            '"provisioning_mode": "external_package_required"',
            '"provisioning_mode": "bundled"',
            $original
        );
        file_put_contents($path, $mutated);
        file_put_contents($shaPath, hash('sha256', $mutated).PHP_EOL);

        $this->artisan('report:validate-assets')
            ->expectsOutputToContain('invalid_profile_provisioning_mode')
            ->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
        file_put_contents($shaPath, $originalSha);
    }

    expect(file_get_contents($path))->toBe($original);
    expect(file_get_contents($shaPath))->toBe($originalSha);
});

it('fails if the reporting profile references a versioned taxonomy zip', function () {
    $path = base_path('data/reporting_profiles/esrs-2023-preparatory-v1.json');
    $shaPath = $path.'.sha256';
    $original = file_get_contents($path);
    $originalSha = file_get_contents($shaPath);

    try {
        $data = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $data['taxonomy']['source_package_path'] = 'data/reporting_profiles/esrs-taxonomy-2023.zip';

        $mutated = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        file_put_contents($path, $mutated);
        file_put_contents($shaPath, hash('sha256', $mutated).PHP_EOL);

        $this->artisan('report:validate-assets')
            ->expectsOutputToContain('taxonomy_package_reference_forbidden')
            ->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
        file_put_contents($shaPath, $originalSha);
    }

    expect(file_get_contents($path))->toBe($original);
    expect(file_get_contents($shaPath))->toBe($originalSha);
});
