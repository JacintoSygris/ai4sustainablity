<?php

it('passes the vendored taxonomy asset integrity gate', function () {
    $this->artisan('taxonomy:validate-assets esrs-set1-2024')
        ->expectsOutputToContain('taxonomy:validate-assets OK')
        ->assertExitCode(0);
});

it('reports integrity failures without leaking local paths', function () {
    $path = base_path('data/xbrl/taxonomies/esrs-set1-2024/META-INF/catalog.xml');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, $original."\n<!-- t158 integrity probe -->\n");

        $this->artisan('taxonomy:validate-assets esrs-set1-2024')
            ->expectsOutputToContain('taxonomy_integrity_failed')
            ->doesntExpectOutputToContain(base_path())
            ->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
    }

    expect(file_get_contents($path))->toBe($original);
});
