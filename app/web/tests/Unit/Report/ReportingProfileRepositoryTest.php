<?php

use App\Services\Report\ReportingProfileException;
use App\Services\Report\ReportingProfileRepository;

// Unit tests are plain PHPUnit\Framework\TestCase by default (see
// tests/Pest.php, which only binds Tests\TestCase for the Feature suite),
// so base_path() has no app() to resolve against unless this file opts in.
uses(Tests\TestCase::class);

it('loads the preparatory ESRS 2023 profile with external taxonomy provisioning', function () {
    $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');

    expect($profile->profileId())->toBe('esrs-2023-preparatory-v1')
        ->and($profile->hash())->toMatch('/^[a-f0-9]{64}$/')
        ->and($profile->taxonomy()['entrypoint'])->toBe('https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd')
        ->and($profile->taxonomy()['provisioning_mode'])->toBe('external_package_required')
        ->and($profile->allowedArtefacts())->toBe(['html', 'docx', 'evidence_json', 'xhtml_ixbrl_candidate'])
        ->and($profile->supportsValidatedCandidate())->toBeTrue()
        ->and($profile->supportsFilingReady())->toBeFalse()
        ->and($profile->requiresExternalTaxonomyPackage())->toBeTrue();

    expect($profile->raw())->not->toHaveKey('package_sha256');
    expect(json_encode($profile->raw(), JSON_THROW_ON_ERROR))->not->toContain('taxonomy_package.zip');
});

it('rejects a manipulated profile asset checksum', function () {
    $path = base_path('data/reporting_profiles/esrs-2023-preparatory-v1.json');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, str_replace(
            '"status": "active"',
            '"status": "retired"',
            $original
        ));

        expect(fn () => (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1'))
            ->toThrow(ReportingProfileException::class, 'profile_checksum_mismatch');
    } finally {
        file_put_contents($path, $original);
    }

    expect(file_get_contents($path))->toBe($original);
});

it('rejects a manipulated declared mapping checksum', function () {
    $path = base_path('data/atomizer_xbrl_concepts_v1.json.sha256');
    $original = file_get_contents($path);

    try {
        file_put_contents($path, str_repeat('0', 64));

        expect(fn () => (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1'))
            ->toThrow(ReportingProfileException::class, 'mapping_checksum_mismatch');
    } finally {
        file_put_contents($path, $original);
    }

    expect(file_get_contents($path))->toBe($original);
});

it('rejects package checksums and versioned local package paths in the profile', function () {
    $path = base_path('data/reporting_profiles/esrs-2023-preparatory-v1.json');
    $shaPath = $path.'.sha256';
    $original = file_get_contents($path);
    $originalSha = file_get_contents($shaPath);

    try {
        $data = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        $data['taxonomy']['package_sha256'] = str_repeat('a', 64);
        $data['taxonomy']['local_package_path'] = 'data/reporting_profiles/taxonomy_package.zip';

        $mutated = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        file_put_contents($path, $mutated);
        file_put_contents($shaPath, hash('sha256', $mutated).PHP_EOL);

        expect(fn () => (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1'))
            ->toThrow(ReportingProfileException::class, 'taxonomy_package_reference_forbidden');
    } finally {
        file_put_contents($path, $original);
        file_put_contents($shaPath, $originalSha);
    }

    expect(file_get_contents($path))->toBe($original);
    expect(file_get_contents($shaPath))->toBe($originalSha);
});
