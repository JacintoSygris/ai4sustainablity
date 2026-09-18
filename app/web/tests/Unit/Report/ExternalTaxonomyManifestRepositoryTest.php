<?php

use App\Services\Report\ExternalTaxonomyManifestRepository;
use App\Services\Report\ReportingProfileRepository;


uses(Tests\TestCase::class);

beforeEach(function () {
    config(['services.report.external_taxonomy_manifest_path' => null]);
    $this->profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
});

it('fails closed when no external manifest path is configured', function () {
    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_manifest_missing');
});

it('fails closed when the external manifest is malformed json or not an object', function () {
    $manifestPath = externalTaxonomyManifestTestFile('manifest.json', '{');
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_manifest_malformed');

    file_put_contents($manifestPath, json_encode(['not an object'], JSON_THROW_ON_ERROR));

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_manifest_malformed');
});

it('fails closed when the manifest schema does not match', function () {
    [$manifestPath] = externalTaxonomyManifestValidFixture(['schema_version' => 'external_taxonomy_manifest_v2']);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_manifest_schema_mismatch');
});

it('fails closed when the manifest profile does not match', function () {
    [$manifestPath] = externalTaxonomyManifestValidFixture(['profile_id' => 'other-profile']);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_profile_mismatch');
});

it('fails closed when the taxonomy entrypoint does not match', function () {
    [$manifestPath] = externalTaxonomyManifestValidFixture(['taxonomy_entrypoint' => 'https://example.test/esrs.xsd']);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_entrypoint_mismatch');
});

it('fails closed when the external package has not been explicitly confirmed', function () {
    [$manifestPath] = externalTaxonomyManifestValidFixture(['external_taxonomy_package_confirmed' => 'true']);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_not_confirmed');
});

it('fails closed when the package checksum is not a sha256 hex string', function () {
    [$manifestPath] = externalTaxonomyManifestValidFixture(['taxonomy_package_checksum' => 'not-a-sha256']);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_package_checksum_invalid');
});

it('fails closed when the external taxonomy package is missing', function () {
    [$manifestPath, $packagePath] = externalTaxonomyManifestValidFixture();
    unlink($packagePath);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_package_missing');
});

it('fails closed when the external taxonomy package checksum differs', function () {
    [$manifestPath, $packagePath] = externalTaxonomyManifestValidFixture();
    file_put_contents($packagePath, 'mutated external package');
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_package_checksum_mismatch');
});

it('fails closed when the package resolves inside the repository or storage path', function (string $packagePath) {
    [$manifestPath] = externalTaxonomyManifestValidFixture([
        'taxonomy_package_path' => $packagePath,
        'taxonomy_package_checksum' => hash_file('sha256', $packagePath),
    ]);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    expect(fn () => (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile))
        ->toThrow(RuntimeException::class, 'external_taxonomy_package_path_forbidden');
})->with([
    'repo' => fn () => base_path('artisan'),
    'storage' => fn () => storage_path('app/.gitignore'),
]);

it('returns only safe manifest metadata for a valid external temporary fixture', function () {
    [$manifestPath, $packagePath, $checksum] = externalTaxonomyManifestValidFixture([
        'operator_secret_note' => 'must not be returned',
    ]);
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    $manifest = (new ExternalTaxonomyManifestRepository())->loadForProfile($this->profile);

    expect($manifest)->toBe([
        'schema_version' => 'external_taxonomy_manifest_v1',
        'profile_id' => 'esrs-2023-preparatory-v1',
        'taxonomy_entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
        'taxonomy_package_checksum' => $checksum,
        'external_taxonomy_package_confirmed' => true,
    ]);
    expect(json_encode($manifest, JSON_THROW_ON_ERROR))
        ->not->toContain($packagePath)
        ->not->toContain('operator_secret_note')
        ->not->toContain('must not be returned');
});

it('returns the canonical package path only through the internal API', function () {
    [$manifestPath, $packagePath, $checksum] = externalTaxonomyManifestValidFixture();
    config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);

    $repository = new ExternalTaxonomyManifestRepository();

    $safe = $repository->loadForProfile($this->profile);
    $internal = $repository->loadInternalForProfile($this->profile);

    expect($safe)->not->toHaveKey('taxonomy_package_path');
    expect($internal)->toMatchArray([
        'taxonomy_package_path' => realpath($packagePath),
        'taxonomy_package_checksum' => $checksum,
    ]);
});

function externalTaxonomyManifestValidFixture(array $overrides = []): array
{
    $packagePath = externalTaxonomyManifestTestFile('esrs-taxonomy-package.zip', 'external taxonomy package bytes');
    $checksum = hash_file('sha256', $packagePath);

    $manifest = array_replace([
        'schema_version' => 'external_taxonomy_manifest_v1',
        'profile_id' => 'esrs-2023-preparatory-v1',
        'taxonomy_entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
        'taxonomy_package_path' => $packagePath,
        'taxonomy_package_checksum' => $checksum,
        'external_taxonomy_package_confirmed' => true,
    ], $overrides);

    $manifestPath = externalTaxonomyManifestTestFile(
        'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    return [$manifestPath, $packagePath, $manifest['taxonomy_package_checksum']];
}

function externalTaxonomyManifestTestFile(string $name, string $contents): string
{
    $dir = sys_get_temp_dir().'/i4s-external-taxonomy-manifest-tests';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir.'/'.uniqid('', true).'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}
