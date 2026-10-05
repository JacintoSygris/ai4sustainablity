<?php

use App\Services\Report\GenericXbrlPackageRoot;

uses(Tests\TestCase::class);

// Containment/resolution only: this text is not an official manifest or an integrity fixture.
const GENERIC_XBRL_ROOT_TEST_MARKER_PATH = 'data/xbrl/taxonomies/xbrl-country-current-2024-snapshot.manifest.json';
const GENERIC_XBRL_ROOT_TEST_MARKER_BYTES = "synthetic generic XBRL root marker\n";

function genericXbrlRootTestParent(): string
{
    return dirname(base_path(), 3).'/external-public-xbrl-tests';
}

function genericXbrlRootTestNormalize(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');

    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}

function genericXbrlRootTestFixture(): array
{
    $parent = genericXbrlRootTestParent();
    if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
        throw new RuntimeException('Cannot create external synthetic fixture parent.');
    }
    $resolvedParent = realpath($parent);
    if ($resolvedParent === false || is_link($parent)
        || genericXbrlRootTestNormalize($resolvedParent) !== genericXbrlRootTestNormalize($parent)) {
        throw new RuntimeException('External synthetic fixture parent is aliased.');
    }

    $root = $parent.'/'.bin2hex(random_bytes(16));
    // Exclusive allocation: never reuse another test's root, including on collision.
    if (file_exists($root) || is_link($root) || ! mkdir($root, 0700)) {
        throw new RuntimeException('Cannot exclusively allocate external synthetic fixture root.');
    }
    $marker = $root.'/'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH;
    if (! mkdir(dirname($marker), 0700, true)) {
        throw new RuntimeException('Cannot create external synthetic marker directories.');
    }
    $handle = fopen($marker, 'x');
    if ($handle === false) {
        throw new RuntimeException('Cannot exclusively create external synthetic marker.');
    }
    try {
        if (fwrite($handle, GENERIC_XBRL_ROOT_TEST_MARKER_BYTES) !== strlen(GENERIC_XBRL_ROOT_TEST_MARKER_BYTES)) {
            throw new RuntimeException('Cannot write external synthetic marker.');
        }
    } finally {
        fclose($handle);
    }

    // Retained for HOST custody: deliberately no unlink/rmdir/recursive cleanup.
    return [$root, $marker];
}

function genericXbrlRootTestSnapshot(string $root): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($root) + 1);
        $snapshot[str_replace('\\', '/', $relative)] = $entry->isLink()
            ? ['link', readlink($entry->getPathname())]
            : ($entry->isDir() ? ['directory'] : ['file', $entry->getSize(), hash_file('sha256', $entry->getPathname())]);
    }
    ksort($snapshot);

    return $snapshot;
}

function genericXbrlRootTestRejects(callable $resolve, string $code): void
{
    try {
        $resolve();
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe($code);

        return;
    }

    test()->fail('Expected RuntimeException with code '.$code.'.');
}

it('refuses an unset or null generic root before creating external files', function (bool $unset) {
    // Missing planned class must be the RED cause, before any external fixture effects.
    $resolver = new GenericXbrlPackageRoot();
    config(['services.report.generic_xbrl_root' => null]);
    if ($unset) {
        app('config')->offsetUnset('services.report.generic_xbrl_root');
    }
    $parent = genericXbrlRootTestParent();
    $before = is_dir($parent) ? scandir($parent) : false;

    try {
        genericXbrlRootTestRejects(
            fn () => $resolver->resolve(GENERIC_XBRL_ROOT_TEST_MARKER_PATH),
            'generic_xbrl_root_missing',
        );
    } finally {
        clearstatcache();
        expect(is_dir($parent) ? scandir($parent) : false)->toBe($before);
    }
})->with(['unset default' => [true], 'explicit null' => [false]]);

it('refuses a relative missing or non-directory generic root without file effects', function (string $kind) {
    $resolver = new GenericXbrlPackageRoot();
    [$root, $marker] = genericXbrlRootTestFixture();
    $configured = match ($kind) {
        'relative' => 'external-public-xbrl-tests/'.basename($root),
        'missing' => $root.'/absent-root',
        'regular file' => $marker,
    };
    config(['services.report.generic_xbrl_root' => $configured]);
    $before = genericXbrlRootTestSnapshot($root);

    try {
        genericXbrlRootTestRejects(
            fn () => $resolver->resolve(GENERIC_XBRL_ROOT_TEST_MARKER_PATH),
            'generic_xbrl_root_invalid',
        );
    } finally {
        expect(genericXbrlRootTestSnapshot($root))->toBe($before);
    }
})->with(['relative', 'missing', 'regular file']);

it('refuses checkout application and storage roots without touching the owned marker', function (string $kind) {
    $resolver = new GenericXbrlPackageRoot();
    [$root] = genericXbrlRootTestFixture();
    $configured = match ($kind) {
        'checkout' => dirname(base_path(), 2),
        'application' => base_path(),
        'storage' => storage_path(),
    };
    config(['services.report.generic_xbrl_root' => $configured]);
    $before = genericXbrlRootTestSnapshot($root);

    try {
        genericXbrlRootTestRejects(
            fn () => $resolver->resolve(GENERIC_XBRL_ROOT_TEST_MARKER_PATH),
            'generic_xbrl_root_invalid',
        );
    } finally {
        expect(genericXbrlRootTestSnapshot($root))->toBe($before);
    }
})->with(['checkout', 'application', 'storage']);

it('resolves the exact existing external marker path without mutating bytes', function () {
    $resolver = new GenericXbrlPackageRoot();
    [$root, $marker] = genericXbrlRootTestFixture();
    config(['services.report.generic_xbrl_root' => $root]);
    $before = genericXbrlRootTestSnapshot($root);

    try {
        expect($resolver->resolve(GENERIC_XBRL_ROOT_TEST_MARKER_PATH))->toBe($marker);
    } finally {
        expect(file_get_contents($marker))->toBe(GENERIC_XBRL_ROOT_TEST_MARKER_BYTES)
            ->and(genericXbrlRootTestSnapshot($root))->toBe($before);
    }
});

it('rejects traversal and absolute requested paths without file effects', function (string $kind) {
    $resolver = new GenericXbrlPackageRoot();
    [$root, $marker] = genericXbrlRootTestFixture();
    config(['services.report.generic_xbrl_root' => $root]);
    $requested = match ($kind) {
        'parent traversal' => '../'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH,
        'embedded traversal' => 'data/../'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH,
        'backslash traversal' => '..\\'.str_replace('/', '\\', GENERIC_XBRL_ROOT_TEST_MARKER_PATH),
        'leave and reenter' => '../'.basename($root).'/'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH,
        'absolute fixture' => $marker,
        'POSIX absolute' => '/'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH,
        'Windows absolute' => 'C:/'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH,
        'UNC absolute' => '//synthetic.invalid/share/'.GENERIC_XBRL_ROOT_TEST_MARKER_PATH,
        'empty' => '',
    };
    $before = genericXbrlRootTestSnapshot($root);

    try {
        genericXbrlRootTestRejects(fn () => $resolver->resolve($requested), 'generic_xbrl_path_invalid');
    } finally {
        expect(genericXbrlRootTestSnapshot($root))->toBe($before);
    }
})->with([
    'parent traversal', 'embedded traversal', 'backslash traversal', 'leave and reenter',
    'absolute fixture', 'POSIX absolute', 'Windows absolute', 'UNC absolute', 'empty',
]);

it('refuses a missing requested file or a directory without creating or changing files', function (string $kind) {
    $resolver = new GenericXbrlPackageRoot();
    [$root] = genericXbrlRootTestFixture();
    config(['services.report.generic_xbrl_root' => $root]);
    $requested = $kind === 'missing' ? 'data/xbrl/taxonomies/absent.manifest.json' : 'data/xbrl/taxonomies';
    $before = genericXbrlRootTestSnapshot($root);

    try {
        genericXbrlRootTestRejects(fn () => $resolver->resolve($requested), 'generic_xbrl_file_missing');
    } finally {
        expect(genericXbrlRootTestSnapshot($root))->toBe($before);
    }
})->with(['missing', 'directory']);
