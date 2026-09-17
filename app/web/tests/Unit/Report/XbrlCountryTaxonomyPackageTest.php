<?php

use App\Services\Report\XbrlCountryTaxonomyPackage;

uses(Tests\TestCase::class);

function countryT2024Path(string $path): string
{
    return dirname(__DIR__, 3).'/data/xbrl/taxonomies/'.$path;
}

it('verifies the active XBRL country current 2024 snapshot package and manifest', function () {
    $package = app(XbrlCountryTaxonomyPackage::class);

    expect($package->verifiedPath())->toEndWith('data/xbrl/taxonomies/xbrl-country-current-2024-snapshot.zip')
        ->and($package->metadata())->toMatchArray([
            'version' => 'xbrl-country-current-2024-snapshot',
            'source_base_url' => 'https://www.xbrl.org/taxonomy/int/country/current/',
            'zip_sha256' => 'bbcaa6097bdbfa67880b3f1e7435fc203eaa8ed24fdb6c093efa75687fcf4e55',
            'zip_size' => 35563,
            'member_count' => 9,
            'path' => 'data/xbrl/taxonomies/xbrl-country-current-2024-snapshot.zip',
        ]);
});

it('fails closed when source/member/manifest integrity is inconsistent', function () {
    $manifest = countryT2024Path('xbrl-country-current-2024-snapshot.manifest.json');
    $backup = $manifest.'.country-test-backup';

    copy($manifest, $backup);

    try {
        $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $data['source_members'][0]['local_member'] = 'xbrl-country-current-2024-snapshot/wrong-entry-en.xsd';
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        app(XbrlCountryTaxonomyPackage::class)->verifiedPath();
    } finally {
        rename($backup, $manifest);
    }
})->throws(RuntimeException::class, 'country_taxonomy_manifest_invalid');
