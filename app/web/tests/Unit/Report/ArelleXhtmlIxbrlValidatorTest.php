<?php

use App\Services\Report\ArelleXhtmlIxbrlValidator;
use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\XhtmlIxbrlCandidateException;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['services.report.arelle_command' => null]);
    $this->profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
});

it('blocks when the configured Arelle binary is absent', function () {
    config(['services.report.arelle_command' => '/tmp/missing-arelle']);

    expect(fn () => (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_unavailable');
});

it('runs Arelle with argument list, offline validation, package, file and cleans the temporary XHTML', function () {
    $capturePath = arelleValidatorTempFile('capture.json', '');
    $binary = arelleValidatorFixtureBinary($capturePath, 0, 'info: validation successful');
    config(['services.report.arelle_command' => $binary]);

    (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture());

    $capture = json_decode(file_get_contents($capturePath), true, flags: JSON_THROW_ON_ERROR);

    expect($capture['argv'])->toContain('--internetConnectivity=offline')
        ->and($capture['argv'])->toContain('--validate')
        ->and($capture['argv'])->toContain('--packages')
        ->and($capture['argv'])->toContain('--file')
        ->and($capture['package_exists'])->toBeTrue()
        ->and($capture['xhtml_exists_during_run'])->toBeTrue()
        ->and(is_file($capture['xhtml_path']))->toBeFalse();
});

it('blocks Arelle timeout', function () {
    $binary = arelleValidatorFixtureBinary(
        arelleValidatorTempFile('capture.json', ''),
        0,
        'info: validation successful',
        2,
    );
    config(['services.report.arelle_command' => $binary]);

    expect(fn () => (new ArelleXhtmlIxbrlValidator(1))->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_validation_failed');
});

it('blocks Arelle non-zero exit and error/fatal severities', function (int $exitCode, string $output) {
    $binary = arelleValidatorFixtureBinary(arelleValidatorTempFile('capture.json', ''), $exitCode, $output);
    config(['services.report.arelle_command' => $binary]);

    expect(fn () => (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_validation_failed');
})->with([
    'non-zero' => [2, 'info: validation attempted'],
    'error severity' => [0, 'error: taxonomy failure'],
    'fatal severity' => [0, 'fatal: taxonomy failure'],
    'unanalyzable' => [0, ''],
]);

function arelleValidatorManifestFixture(): array
{
    $package = arelleValidatorTempFile('package.zip', 'taxonomy package');

    return [
        'taxonomy_entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
        'taxonomy_package_path' => $package,
    ];
}

function arelleValidatorFixtureBinary(string $capturePath, int $exitCode, string $output, int $sleepSeconds = 0): string
{
    $binary = arelleValidatorTempFile('arelle-fixture.php', <<<'PHP'
#!/usr/bin/env php
<?php
$capturePath = getenv('I4S_ARELLE_CAPTURE');
$argvList = $argv;
$fileIndex = array_search('--file', $argvList, true);
$packageIndex = array_search('--packages', $argvList, true);
$xhtmlPath = is_int($fileIndex) ? ($argvList[$fileIndex + 1] ?? '') : '';
$packagePath = is_int($packageIndex) ? ($argvList[$packageIndex + 1] ?? '') : '';
file_put_contents($capturePath, json_encode([
    'argv' => $argvList,
    'xhtml_path' => $xhtmlPath,
    'xhtml_exists_during_run' => is_file($xhtmlPath),
    'package_exists' => is_file($packagePath),
], JSON_THROW_ON_ERROR));
if ((int) getenv('I4S_ARELLE_SLEEP') > 0) {
    sleep((int) getenv('I4S_ARELLE_SLEEP'));
}
fwrite(STDOUT, getenv('I4S_ARELLE_OUTPUT'));
exit((int) getenv('I4S_ARELLE_EXIT'));
PHP);
    chmod($binary, 0700);
    putenv('I4S_ARELLE_CAPTURE='.$capturePath);
    putenv('I4S_ARELLE_EXIT='.$exitCode);
    putenv('I4S_ARELLE_OUTPUT='.$output);
    putenv('I4S_ARELLE_SLEEP='.$sleepSeconds);

    return $binary;
}

function arelleValidatorTempFile(string $name, string $contents): string
{
    $dir = sys_get_temp_dir().'/i4s-arelle-validator-tests';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir.'/'.uniqid('', true).'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}
