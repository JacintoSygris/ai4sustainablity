<?php

use App\Services\Report\EsrsTaxonomyPackageRepository;

function t158TaxonomyPath(string $path): string
{
    return dirname(__DIR__, 3).'/data/xbrl/taxonomies/esrs-set1-2024/'.$path;
}

it('verifies the vendored ESRS Set 1 taxonomy package and exposes safe metadata', function () {
    $package = app(EsrsTaxonomyPackageRepository::class)->verified('esrs-set1-2024');

    expect($package->version())->toBe('esrs-set1-2024')
        ->and($package->metadata())->toMatchArray([
            'version' => 'esrs-set1-2024',
            'published_date' => '2024-08-30',
            'zip_sha256' => 'f9dab98514dbb27b53f6bf94cb1980920e6029d5d21963677954275c217850b3',
            'entrypoint' => 'xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
            'core_entrypoint' => 'xbrl.efrag.org/taxonomy/esrs/2023-12-22/common/esrs_cor.xsd',
            'catalog' => 'META-INF/catalog.xml',
        ])
        ->and($package->entrypointPath())->toEndWith('data/xbrl/taxonomies/esrs-set1-2024/xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd')
        ->and($package->catalogPath())->toEndWith('data/xbrl/taxonomies/esrs-set1-2024/META-INF/catalog.xml');
});

it('fails closed when a manifest member is missing', function () {
    $path = t158TaxonomyPath('META-INF/catalog.xml');
    $backup = $path.'.t158-test-backup';

    rename($path, $backup);

    try {
        app(EsrsTaxonomyPackageRepository::class)->verified('esrs-set1-2024');
    } finally {
        rename($backup, $path);
    }
})->throws(RuntimeException::class, 'taxonomy_member_missing');
