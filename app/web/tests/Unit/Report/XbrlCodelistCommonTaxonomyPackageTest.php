<?php

use App\Services\Report\XbrlCodelistCommonTaxonomyPackage;

uses(Tests\TestCase::class);

function codelistCommon2024Path(string $path): string
{
    return dirname(__DIR__, 3).'/data/xbrl/taxonomies/'.$path;
}

it('verifies the active XBRL code list common 2024 snapshot package and manifest', function () {
    $package = app(XbrlCodelistCommonTaxonomyPackage::class);

    expect($package->verifiedPath())->toEndWith('data/xbrl/taxonomies/xbrl-codelist-common-2024-snapshot.zip')
        ->and($package->metadata())->toMatchArray([
            'version' => 'xbrl-codelist-common-2024-snapshot',
            'source_base_url' => 'https://www.xbrl.org/taxonomy/int/codelist-common/2024/',
            'zip_sha256' => 'a4ef52ff46f4489309ead522e180aff93cfc3ad13e47ffb61ae7aa8b633c5a1e',
            'zip_size' => 2387,
            'member_count' => 4,
            'path' => 'data/xbrl/taxonomies/xbrl-codelist-common-2024-snapshot.zip',
        ]);
});

it('fails closed when source/member/manifest integrity is inconsistent', function () {
    $manifest = codelistCommon2024Path('xbrl-codelist-common-2024-snapshot.manifest.json');
    $backup = $manifest.'.codelist-common-test-backup';

    copy($manifest, $backup);

    try {
        $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $data['source_members'][0]['local_member'] = 'xbrl-codelist-common-2024-snapshot/wrong-role-label-code.xsd';
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        app(XbrlCodelistCommonTaxonomyPackage::class)->verifiedPath();
    } finally {
        rename($backup, $manifest);
    }
})->throws(RuntimeException::class, 'codelist_common_taxonomy_manifest_invalid');
