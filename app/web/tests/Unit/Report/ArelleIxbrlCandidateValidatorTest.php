<?php

use App\Services\Report\ArelleIxbrlCandidateValidator;
use App\Services\Report\IxbrlCandidateBuilder;
use App\Services\Report\IxbrlCandidateDocument;

uses(Tests\TestCase::class);

it('fails closed when the canonical Arelle binary is unavailable', function () {
    config(['services.arelle.binary' => '/missing/arelleCmdLine']);

    $result = app(ArelleIxbrlCandidateValidator::class)
        ->validate(new IxbrlCandidateDocument('<html />', 'candidate.xhtml'));

    expect($result['status'])->toBe('failed')
        ->and($result['reason_codes'])->toContain('arelle_binary_missing');
});

it('runs Arelle with offline structural validation arguments through a controlled process', function () {
    $argvPath = tempnam(sys_get_temp_dir(), 'arelle-argv-');
    $packagePath = tempnam(sys_get_temp_dir(), 'arelle-package-');
    $binary = tempnam(sys_get_temp_dir(), 'arelle-bin-');
    file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents('__ARGV_PATH__', json_encode($argv, JSON_THROW_ON_ERROR));
$packageIndexes = array_keys($argv, '--package', true);
$packages = [
    $argv[$packageIndexes[0] + 1] ?? '',
    $argv[$packageIndexes[1] + 1] ?? '',
];
$zip = new ZipArchive();
$countryCatalog = '';
if ($packages[0] !== '' && $zip->open($packages[0]) === true) {
    $catalog = (string) $zip->getFromName('xbrl-country-current-2024-snapshot/META-INF/catalog.xml');
    $countryCatalog = $catalog;
    $zip->close();
}
$codelistCatalog = '';
if ($packages[1] !== '' && $zip->open($packages[1]) === true) {
    $catalog = (string) $zip->getFromName('xbrl-codelist-common-2024-snapshot/META-INF/catalog.xml');
    $codelistCatalog = $catalog;
    $zip->close();
}
file_put_contents('__PACKAGE_PATH__', json_encode([
    'paths' => $packages,
    'exists' => [is_file($packages[0]), is_file($packages[1])],
    'catalogs' => [$countryCatalog, $codelistCatalog],
], JSON_THROW_ON_ERROR));
fwrite(STDOUT, "[info:valid] controlled validator\n");
exit(0);
PHP);
    file_put_contents($binary, str_replace('__ARGV_PATH__', addslashes($argvPath), file_get_contents($binary)));
    file_put_contents($binary, str_replace('__PACKAGE_PATH__', addslashes($packagePath), file_get_contents($binary)));
    chmod($binary, 0700);

    config(['services.arelle.binary' => $binary]);

    $result = app(ArelleIxbrlCandidateValidator::class)
        ->validate(new IxbrlCandidateDocument(validArelleValidatorCandidateBytes(), 'candidate.xhtml'));

    $argv = json_decode(file_get_contents($argvPath), true, flags: JSON_THROW_ON_ERROR);
    $package = json_decode(file_get_contents($packagePath), true, flags: JSON_THROW_ON_ERROR);
    $packageIndexes = array_keys($argv, '--package', true);

    expect($result['status'])->toBe('passed')
        ->and($argv)->toContain('--validate')
        ->and($argv)->toContain('--validationExitCode')
        ->and($argv)->toContain('--internetConnectivity')
        ->and($argv)->toContain('offline')
        ->and($argv)->toContain('--formula=none')
        ->and($argv)->toContain('--package')
        ->and(collect($argv)->contains(fn (string $arg): bool => str_ends_with($arg, 'xbrl-country-current-2024-snapshot.zip')))->toBeTrue()
        ->and(collect($argv)->contains(fn (string $arg): bool => str_ends_with($arg, 'xbrl-codelist-common-2024-snapshot.zip')))->toBeTrue()
        ->and(collect($argv)->filter(fn (string $arg): bool => $arg === '--package')->count())->toBe(2)
        ->and($argv[$packageIndexes[0] + 1])->toEndWith('xbrl-country-current-2024-snapshot.zip')
        ->and($argv[$packageIndexes[1] + 1])->toEndWith('xbrl-codelist-common-2024-snapshot.zip')
        ->and($package['exists'])->toBe([true, true])
        ->and($package['catalogs'][0])->toContain('<rewriteURI uriStartString="https://www.xbrl.org/taxonomy/int/country/current/" rewritePrefix="../" />')
        ->and($package['catalogs'][1])->toContain('<rewriteURI uriStartString="https://www.xbrl.org/taxonomy/int/codelist-common/2024/" rewritePrefix="../" />')
        ->and(substr_count($package['catalogs'][0], '<rewriteURI '))->toBe(1)
        ->and(substr_count($package['catalogs'][1], '<rewriteURI '))->toBe(1)
        ->and($package['catalogs'][0])->not->toContain('<uri ')
        ->and($package['catalogs'][1])->not->toContain('<uri ');

    @unlink($binary);
    @unlink($argvPath);
    @unlink($packagePath);
});

it('fails closed before Arelle execution when the current country package source integrity fails', function () {
    $marker = tempnam(sys_get_temp_dir(), 'arelle-marker-');
    @unlink($marker);
    $binary = tempnam(sys_get_temp_dir(), 'arelle-bin-');
    file_put_contents($binary, "#!/usr/bin/env sh\ntouch ".escapeshellarg($marker)."\nexit 0\n");
    chmod($binary, 0700);

    config(['services.arelle.binary' => $binary]);

    $source = dirname(__DIR__, 3).'/data/xbrl/taxonomies/xbrl-country-current-2024-source/entry-en.xsd';
    $backup = $source.'.arelle-test-backup';
    rename($source, $backup);

    try {
        $result = app(ArelleIxbrlCandidateValidator::class)
            ->validate(new IxbrlCandidateDocument(validArelleValidatorCandidateBytes(), 'candidate.xhtml'));
    } finally {
        rename($backup, $source);
    }

    expect($result['status'])->toBe('failed')
        ->and($result['reason_codes'])->toContain('arelle_taxonomy_package_integrity_failed')
        ->and(is_file($marker))->toBeFalse();

    @unlink($binary);
    @unlink($marker);
});

it('fails closed before Arelle execution when the code list common package source integrity fails', function () {
    $marker = tempnam(sys_get_temp_dir(), 'arelle-marker-');
    @unlink($marker);
    $binary = tempnam(sys_get_temp_dir(), 'arelle-bin-');
    file_put_contents($binary, "#!/usr/bin/env sh\ntouch ".escapeshellarg($marker)."\nexit 0\n");
    chmod($binary, 0700);

    config(['services.arelle.binary' => $binary]);

    $source = dirname(__DIR__, 3).'/data/xbrl/taxonomies/xbrl-codelist-common-2024-source/role-label-code.xsd';
    $backup = $source.'.arelle-test-backup';
    rename($source, $backup);

    try {
        $result = app(ArelleIxbrlCandidateValidator::class)
            ->validate(new IxbrlCandidateDocument(validArelleValidatorCandidateBytes(), 'candidate.xhtml'));
    } finally {
        rename($backup, $source);
    }

    expect($result['status'])->toBe('failed')
        ->and($result['reason_codes'])->toContain('arelle_taxonomy_package_integrity_failed')
        ->and(is_file($marker))->toBeFalse();

    @unlink($binary);
    @unlink($marker);
});

it('fails closed before Arelle execution when the code list common package manifest integrity fails', function () {
    $marker = tempnam(sys_get_temp_dir(), 'arelle-marker-');
    @unlink($marker);
    $binary = tempnam(sys_get_temp_dir(), 'arelle-bin-');
    file_put_contents($binary, "#!/usr/bin/env sh\ntouch ".escapeshellarg($marker)."\nexit 0\n");
    chmod($binary, 0700);

    config(['services.arelle.binary' => $binary]);

    $manifest = dirname(__DIR__, 3).'/data/xbrl/taxonomies/xbrl-codelist-common-2024-snapshot.manifest.json';
    $backup = $manifest.'.arelle-test-backup';
    copy($manifest, $backup);

    try {
        $data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $data['runtime_dependency'] = false;
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $result = app(ArelleIxbrlCandidateValidator::class)
            ->validate(new IxbrlCandidateDocument(validArelleValidatorCandidateBytes(), 'candidate.xhtml'));
    } finally {
        rename($backup, $manifest);
    }

    expect($result['status'])->toBe('failed')
        ->and($result['reason_codes'])->toContain('arelle_taxonomy_package_integrity_failed')
        ->and(is_file($marker))->toBeFalse();

    @unlink($binary);
    @unlink($marker);
});

it('can run real Arelle offline when the canonical binary is installed', function () {
    if (! is_executable('/opt/arelle/bin/arelleCmdLine')) {
        $this->markTestSkipped('Canonical Arelle CLI is not installed in this environment.');
    }

    config(['services.arelle.binary' => '/opt/arelle/bin/arelleCmdLine']);

    $result = app(ArelleIxbrlCandidateValidator::class)
        ->validate(new IxbrlCandidateDocument(validArelleValidatorCandidateBytes(), 'candidate.xhtml'));

    expect($result['status'])->toBe('passed');
});

function validArelleValidatorCandidateBytes(): string
{
    $builder = app(IxbrlCandidateBuilder::class);

    return $builder->build([
        'characterization_id' => 999,
        'schema_version' => 'v1',
        'stored_schema_version' => 'v1',
        'reporting_entity' => [
            'identifier_scheme' => 'https://example.test/entity',
            'identifier' => 'demo-entity',
            'name' => 'Entidad Demo',
        ],
        'responses' => [
            'BP-1_01' => [
                'datapoint_id' => 'BP-1_01',
                'status' => 'completed',
                'facts' => [[
                    'fact_id' => '11111111-1111-4111-8111-111111111111',
                    'value_kind' => 'narrative',
                    'value' => 'Prepared response.',
                    'decimals' => null,
                    'unit' => null,
                    'concept' => [
                        'concept_id' => 'esrs:DescriptionOfBusinessModelAndValueChainExplanatory',
                        'taggable_state' => 'mapped',
                    ],
                    'context' => [
                        'period_type' => 'duration',
                        'start_date' => '2025-01-01',
                        'end_date' => '2025-12-31',
                        'instant_date' => null,
                        'dimensions' => [],
                    ],
                ]],
                'fact_readiness' => ['state' => 'valid_completed', 'fact_count' => 1],
            ],
        ],
    ])->bytes;
}
