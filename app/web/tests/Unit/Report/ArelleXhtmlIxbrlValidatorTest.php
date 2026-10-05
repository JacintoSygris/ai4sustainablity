<?php

use App\Services\Report\ArelleXhtmlIxbrlValidator;
use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\XhtmlIxbrlCandidateException;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->arelleValidatorFixturePaths = [];
    $this->arelleValidatorPreviousEnvironment = [];
    foreach (['I4S_ARELLE_CAPTURE', 'I4S_ARELLE_EXIT', 'I4S_ARELLE_OUTPUT', 'I4S_ARELLE_SLEEP'] as $name) {
        $this->arelleValidatorPreviousEnvironment[$name] = getenv($name);
    }
    config(['services.report.arelle_command' => null]);
    $this->profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
});

afterEach(function () {
    foreach ($this->arelleValidatorPreviousEnvironment as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
    }
    foreach ($this->arelleValidatorFixturePaths as $path) {
        if (dirname($path) === sys_get_temp_dir().'/i4s-arelle-validator-tests' && is_file($path)) {
            unlink($path);
        }
    }
});

it('blocks when the configured Arelle binary is absent', function () {
    config(['services.report.arelle_command' => '/tmp/missing-arelle']);

    expect(fn () => (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_unavailable');
});

it('runs Arelle with argument list, offline validation, package, file and cleans the temporary XHTML', function () {
    $capturePath = arelleValidatorTempFile('capture.json', '');
    $binary = arelleValidatorFixtureBinary($capturePath, 0, '[info] validated in 1.23 secs - candidate.xhtml');
    config(['services.report.arelle_command' => [PHP_BINARY, '-n', $binary]]);

    (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture());

    $capture = json_decode(file_get_contents($capturePath), true, flags: JSON_THROW_ON_ERROR);

    expect($capture['argv'])->toContain('--internetConnectivity=offline')
        ->and($capture['argv'])->toContain('--validate')
        ->and($capture['argv'])->toContain('--validationExitCode')
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
        6,
    );
    config(['services.report.arelle_command' => [PHP_BINARY, '-n', $binary]]);

    $startedAt = hrtime(true);

    try {
        expect(fn () => (new ArelleXhtmlIxbrlValidator(1))->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
            ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_validation_failed');
    } finally {
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        expect($elapsedSeconds)->toBeLessThan(3);
    }
});

it('blocks concurrent Arelle validation before starting another process', function () {
    $capturePath = arelleValidatorTempFile('capture.json', 'not-started');
    $binary = arelleValidatorFixtureBinary($capturePath, 0, 'info: validation successful');
    config(['services.report.arelle_command' => [PHP_BINARY, '-n', $binary]]);
    $lock = \Illuminate\Support\Facades\Cache::lock('i4s:report:arelle-validation', 5);

    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
            ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_busy');
        expect(file_get_contents($capturePath))->toBe('not-started');
    } finally {
        $lock->release();
    }
});

it('blocks Arelle output that exceeds the configured byte budget', function () {
    $binary = arelleValidatorFixtureBinary(
        arelleValidatorTempFile('capture.json', ''),
        0,
        str_repeat('x', 2048).' validation successful',
    );
    config(['services.report.arelle_command' => [PHP_BINARY, '-n', $binary]]);

    expect(fn () => (new ArelleXhtmlIxbrlValidator(maxOutputBytes: 1024))->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_validation_failed');
});

it('blocks Arelle non-zero exit, explicit failure markers and error severities', function (int $exitCode, string $output) {
    $binary = arelleValidatorFixtureBinary(arelleValidatorTempFile('capture.json', ''), $exitCode, $output);
    config(['services.report.arelle_command' => [PHP_BINARY, '-n', $binary]]);

    expect(fn () => (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_validation_failed');
})->with([
    'non-zero' => [2, 'info: validation attempted'],
    'error severity' => [0, 'error: taxonomy failure'],
    'fatal severity' => [0, 'fatal: taxonomy failure'],
    'warning that says validation failed' => [0, 'warning: validation failed'],
    'bare failure marker' => [0, 'validation failure'],
    'invalid marker' => [0, 'instance invalid'],
    'ambiguous success and failure' => [0, 'validation passed after validation failed'],
    'unanalyzable' => [0, ''],
]);

// Characterization of the existing fail-closed admission guard, not a new RED behavior.
it('characterizes rejected Arelle commands without launching the fixture', function (string $case) {
    $capturePath = arelleValidatorTempFile('capture.json', 'not-started');
    $binary = arelleValidatorFixtureBinary($capturePath, 0, 'info: validation successful');
    $command = match ($case) {
        'empty list' => [],
        'non-list' => ['binary' => PHP_BINARY, 'flag' => '-n', 'fixture' => $binary],
        'mixed integer' => [PHP_BINARY, '-n', $binary, 1],
        'mixed null' => [PHP_BINARY, '-n', $binary, null],
        'mixed boolean' => [PHP_BINARY, '-n', $binary, false],
        'empty token' => [PHP_BINARY, '-n', $binary, ''],
        'NUL token' => [PHP_BINARY, '-n', $binary, "bad\0token"],
        'relative binary' => ['php', '-n', $binary],
        'PATH string' => 'php',
        'drive-relative binary' => ['C:php.exe', '-n', $binary],
        'UNC binary' => ['\\\\server\\share\\php.exe', '-n', $binary],
    };
    config(['services.report.arelle_command' => $command]);

    expect(fn () => (new ArelleXhtmlIxbrlValidator())->validate('<html />', $this->profile, arelleValidatorManifestFixture()))
        ->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_arelle_unavailable');
    expect(file_get_contents($capturePath))->toBe('not-started');
})->with([
    'empty list' => ['empty list'],
    'non-list' => ['non-list'],
    'mixed integer' => ['mixed integer'],
    'mixed null' => ['mixed null'],
    'mixed boolean' => ['mixed boolean'],
    'empty token' => ['empty token'],
    'NUL token' => ['NUL token'],
    'relative binary' => ['relative binary'],
    'PATH string' => ['PATH string'],
    'drive-relative binary' => ['drive-relative binary'],
    'UNC binary' => ['UNC binary'],
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
    test()->arelleValidatorFixturePaths = [...test()->arelleValidatorFixturePaths, $path];

    return $path;
}
