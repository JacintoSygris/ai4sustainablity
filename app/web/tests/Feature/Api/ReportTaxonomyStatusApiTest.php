<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes only safe ESRS taxonomy status metadata to authenticated P10 users', function () {
    config(['services.report.external_taxonomy_manifest_path' => null]);

    $payload = $this->actingAs(User::factory()->create())
        ->getJson('/api/report/taxonomy')
        ->assertOk()
        ->assertJsonPath('data.taxonomy.name', 'EFRAG ESRS XBRL Taxonomy Set 1')
        ->assertJsonPath('data.taxonomy.version', '2023-12-22')
        ->assertJsonPath('data.reporting_profile', 'esrs-2023-preparatory-v1')
        ->assertJsonPath('data.availability.state', 'blocked')
        ->assertJsonPath('data.availability.reason_code', 'external_taxonomy_manifest_missing')
        ->json('data');

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($encoded)
        ->not->toContain('taxonomy_package_path')
        ->not->toContain('manifest_path')
        ->not->toContain('external_taxonomy_manifest_path')
        ->not->toMatch('/[a-f0-9]{64}/i');
});

// Restore the process-local config even for the preserved original case.
$taxonomyStatus201OriginalManifestPath = null;
beforeEach(function () use (&$taxonomyStatus201OriginalManifestPath) {
    $taxonomyStatus201OriginalManifestPath = config('services.report.external_taxonomy_manifest_path');
});
afterEach(function () use (&$taxonomyStatus201OriginalManifestPath) {
    config(['services.report.external_taxonomy_manifest_path' => $taxonomyStatus201OriginalManifestPath]);
});

/** Fictional bytes exercise custody guards only, never Arelle or filing readiness. */
function taxonomyStatus201WithSyntheticManifest(callable $assertions): void
{
    $originalConfig = config('services.report.external_taxonomy_manifest_path');
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'taxonomy-status-201-'.bin2hex(random_bytes(12));
    $ownedFiles = [];
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create synthetic taxonomy fixture directory');
    }

    try {
        $normalizedDirectory = str_replace('\\', '/', realpath($directory));
        foreach ([base_path(), storage_path()] as $root) {
            $normalizedRoot = rtrim(str_replace('\\', '/', realpath($root)), '/');
            if ($normalizedDirectory === $normalizedRoot || str_starts_with($normalizedDirectory, $normalizedRoot.'/')) {
                throw new RuntimeException('Synthetic taxonomy fixture must be outside application and storage roots');
            }
        }

        $profile = app(\App\Services\Report\ReportingProfileRepository::class)->load();
        $packagePath = $directory.DIRECTORY_SEPARATOR.'synthetic-package-sentinel.bin';
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'synthetic-manifest-sentinel.json';
        $ownedFiles = [$packagePath, $manifestPath];
        if (file_put_contents($packagePath, 'synthetic taxonomy fixture') === false) {
            throw new RuntimeException('Cannot write fictional taxonomy package bytes');
        }
        $manifest = [
            'schema_version' => 'external_taxonomy_manifest_v1',
            'profile_id' => $profile->profileId(),
            'taxonomy_entrypoint' => $profile->taxonomy()['entrypoint'],
            'external_taxonomy_package_confirmed' => true,
            'taxonomy_package_checksum' => hash_file('sha256', $packagePath),
            'taxonomy_package_path' => $packagePath,
            'provider_output' => 'synthetic-provider-output-do-not-expose',
        ];
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write synthetic taxonomy manifest');
        }
        config(['services.report.external_taxonomy_manifest_path' => $manifestPath]);
        $assertions($manifest, $manifestPath, $profile);
    } finally {
        config(['services.report.external_taxonomy_manifest_path' => $originalConfig]);
        foreach ($ownedFiles as $ownedFile) {
            if (is_file($ownedFile)) {
                unlink($ownedFile);
            }
        }
        rmdir($directory);
    }
}

/** Assert the entire HTTP body stays free of internal metadata and provider output. */
function taxonomyStatus201AssertSafePayload(
    \Illuminate\Testing\TestResponse $response,
    string $state,
    ?string $reason = null,
    array $sentinels = [],
): void {
    $payload = $response->assertOk()->json('data');
    expect($payload)->toBeArray();
    \PHPUnit\Framework\Assert::assertEqualsCanonicalizing(
        ['type', 'version', 'taxonomy', 'reporting_profile', 'availability'], array_keys($payload),
    );
    expect($payload['type'])->toBe('report_taxonomy_status');
    expect($payload['version'])->toBeString();
    expect(trim($payload['version']))->not->toBe('');
    $profile = app(\App\Services\Report\ReportingProfileRepository::class)->load();
    \PHPUnit\Framework\Assert::assertEqualsCanonicalizing(['name', 'version'], array_keys($payload['taxonomy']));
    expect($payload['taxonomy']['name'])->toBe($profile->taxonomy()['name']);
    expect($payload['taxonomy']['version'])->toBe($profile->taxonomy()['version']);
    expect($payload['reporting_profile'])->toBe($profile->profileId());
    $availability = $payload['availability'];
    expect($availability['state'])->toBe($state);
    $availabilityKeys = array_key_exists('reason_code', $availability) ? ['state', 'reason_code'] : ['state'];
    \PHPUnit\Framework\Assert::assertEqualsCanonicalizing($availabilityKeys, array_keys($availability));
    if ($reason !== null) {
        expect($availability['reason_code'] ?? null)->toBe($reason);
    } elseif (array_key_exists('reason_code', $availability)) {
        expect($availability['reason_code'])->toBeString();
        expect(trim($availability['reason_code']))->not->toBe('');
    }

    $body = $response->getContent();
    expect($body)->not->toMatch('/[a-f0-9]{64}/i');
    foreach (['taxonomy_package_path', 'taxonomy_package_checksum', 'manifest_path', 'external_taxonomy_manifest_path', 'provider_output'] as $internalKey) {
        expect($body)->not->toContain('"'.$internalKey.'"');
    }
    foreach (array_merge([
        'synthetic-provider-output-do-not-expose', 'synthetic-package-sentinel',
        'synthetic-manifest-sentinel', 'taxonomy-status-201-', 'filing_ready',
    ], $sentinels) as $sentinel) {
        expect($body)->not->toContain($sentinel);
        expect($body)->not->toContain(trim(json_encode($sentinel, JSON_THROW_ON_ERROR), '"'));
    }
}

it('requires authentication for advertised taxonomy GET and HEAD', function (string $method) {
    $this->json($method, '/api/report/taxonomy')->assertUnauthorized();
})->with(['GET', 'HEAD']);

it('returns an empty authenticated HEAD body for advertised taxonomy status', function () {
    config(['services.report.external_taxonomy_manifest_path' => null]);
    $this->actingAs(User::factory()->create())
        ->json('HEAD', '/api/report/taxonomy')
        ->assertOk()
        ->assertContent('');
});

it('verifies availability with an actual synthetic manifest and safe metadata only', function () {
    taxonomyStatus201WithSyntheticManifest(function (array $manifest, string $manifestPath, $profile) {
        $loaded = app(\App\Services\Report\ExternalTaxonomyManifestRepository::class)->loadForProfile($profile);
        expect($loaded['profile_id'])->toBe($profile->profileId());
        expect($loaded['taxonomy_entrypoint'])->toBe($profile->taxonomy()['entrypoint']);
        expect($loaded['external_taxonomy_package_confirmed'])->toBeTrue();
        expect($loaded['taxonomy_package_checksum'])->toBe(hash_file('sha256', $manifest['taxonomy_package_path']));
        expect(array_key_exists('taxonomy_package_path', $loaded))->toBeFalse();

        $response = $this->actingAs(User::factory()->create())->getJson('/api/report/taxonomy');
        taxonomyStatus201AssertSafePayload($response, 'verified', sentinels: [
            $manifestPath, $manifest['taxonomy_package_path'], $manifest['taxonomy_package_checksum'],
        ]);
    });
});

it('blocks unsafe manifest classes using actual repository reason codes and safe HTTP metadata', function (string $vector, string $reason) {
    taxonomyStatus201WithSyntheticManifest(function (array $manifest, string $manifestPath, $profile) use ($vector, $reason) {
        switch ($vector) {
            case 'malformed-json':
                $contents = '{"provider_output":"synthetic-provider-output-do-not-expose",';
                break;
            case 'schema-mismatch':
                $manifest['schema_version'] = 'synthetic-invalid-schema';
                break;
            case 'wrong-profile':
                $manifest['profile_id'] = 'synthetic-untrusted-profile';
                break;
            case 'wrong-entrypoint':
                $manifest['taxonomy_entrypoint'] = 'urn:synthetic:untrusted-entrypoint';
                break;
            case 'not-confirmed':
                $manifest['external_taxonomy_package_confirmed'] = false;
                break;
            case 'checksum-invalid':
                $manifest['taxonomy_package_checksum'] = 'synthetic-invalid-checksum';
                break;
            case 'checksum-mismatch':
                $manifest['taxonomy_package_checksum'] = hash('sha256', 'different synthetic taxonomy fixture');
                break;
            case 'missing-package':
                // Delete only our fictional package; deterministic on Windows too.
                unlink($manifest['taxonomy_package_path']);
                break;
            default:
                throw new RuntimeException('Unknown synthetic manifest vector');
        }
        $contents ??= json_encode($manifest, JSON_THROW_ON_ERROR);
        if (file_put_contents($manifestPath, $contents) === false) {
            throw new RuntimeException('Cannot update synthetic taxonomy manifest');
        }
        expect(fn () => app(\App\Services\Report\ExternalTaxonomyManifestRepository::class)->loadForProfile($profile))
            ->toThrow(RuntimeException::class, $reason);
        $response = $this->actingAs(User::factory()->create())->getJson('/api/report/taxonomy');
        taxonomyStatus201AssertSafePayload($response, 'blocked', $reason, [
            $manifestPath, $manifest['taxonomy_package_path'], $manifest['taxonomy_package_checksum'],
        ]);
    });
})->with([
    'malformed JSON' => ['malformed-json', 'external_taxonomy_manifest_malformed'],
    'schema mismatch' => ['schema-mismatch', 'external_taxonomy_manifest_schema_mismatch'],
    'wrong profile' => ['wrong-profile', 'external_taxonomy_profile_mismatch'],
    'wrong entrypoint' => ['wrong-entrypoint', 'external_taxonomy_entrypoint_mismatch'],
    'unconfirmed package' => ['not-confirmed', 'external_taxonomy_not_confirmed'],
    'invalid checksum' => ['checksum-invalid', 'external_taxonomy_package_checksum_invalid'],
    'checksum mismatch' => ['checksum-mismatch', 'external_taxonomy_package_checksum_mismatch'],
    'missing package' => ['missing-package', 'external_taxonomy_package_missing'],
]);

it('ignores request manifest and profile overrides in favor of server authority', function () {
    taxonomyStatus201WithSyntheticManifest(function (array $manifest, string $manifestPath) {
        // A valid attacker-selected fixture must not override missing server config.
        config(['services.report.external_taxonomy_manifest_path' => null]);
        $url = '/api/report/taxonomy?'.http_build_query([
            'manifest_path' => $manifestPath,
            'external_taxonomy_manifest_path' => $manifestPath,
            'profile' => 'synthetic-untrusted-profile',
            'profile_id' => 'synthetic-untrusted-profile',
            'reporting_profile' => 'synthetic-untrusted-profile',
        ]);
        $response = $this->actingAs(User::factory()->create())->getJson($url);
        taxonomyStatus201AssertSafePayload($response, 'blocked', 'external_taxonomy_manifest_missing', [
            $manifestPath, $manifest['taxonomy_package_path'], 'synthetic-untrusted-profile',
        ]);
        expect(config('services.report.external_taxonomy_manifest_path'))->toBeNull();
    });
});