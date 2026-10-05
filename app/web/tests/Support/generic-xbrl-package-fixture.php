<?php

use App\Services\Report\GenericXbrlPackageRoot;

/** @return array{root: string, source_root: string, source_files: array<string, array{bytes: int, sha256: string}>} */
function genericXbrlPackageFixture(): array
{
    $sourceRoot = getenv('XBRL_GENERIC_TAXONOMY_ROOT');
    if (! is_string($sourceRoot) || $sourceRoot === '') {
        throw new RuntimeException('XBRL_GENERIC_TAXONOMY_ROOT is required for real generic XBRL package tests.');
    }

    // Paths and digests only: official bytes are supplied explicitly outside the public tree.
    $inventory = [
        'xbrl-codelist-common-2024-snapshot.manifest.json' => [2149, '6ad4421a91618f014ab7e1bbfb50095ccc06fa52247ded8206c8b535ddfa7c6c'],
        'xbrl-codelist-common-2024-snapshot.zip' => [2387, 'a4ef52ff46f4489309ead522e180aff93cfc3ad13e47ffb61ae7aa8b633c5a1e'],
        'xbrl-codelist-common-2024-source/property-part.xsd' => [2093, '544c3bcafc7f8bcbfc15b758727466d9a8f5b27ca3336fc62a973c4a3c3dfcca'],
        'xbrl-codelist-common-2024-source/role-label-code.xsd' => [1470, '405d649e9b667db3061d4b003263bc43da1d6058fea005b9c04bc2aeb17cb06b'],
        'xbrl-country-current-2024-snapshot.manifest.json' => [4542, '87f7b3007ea1938bf1b83f58187fcf07a01e3b0bc98a8e8921605c09cf937614'],
        'xbrl-country-current-2024-snapshot.zip' => [35563, 'bbcaa6097bdbfa67880b3f1e7435fc203eaa8ed24fdb6c093efa75687fcf4e55'],
        'xbrl-country-current-2024-source/definition.xml' => [93071, 'd4e7fc1b17a635ceef2c36d5304597145e51b1e824200becb4fca29c72a8d973'],
        'xbrl-country-current-2024-source/elts.xsd' => [44035, '1fd8d58b0e07d24611c6baa0b4f88ea1d67d6a65ec9937547b253d2b2cc56bcc'],
        'xbrl-country-current-2024-source/entry-en.xsd' => [699, '6fba8e332c8281002556958efa3c43aa81aec7e1805b5672e51a6bfdb165d324'],
        'xbrl-country-current-2024-source/entry.xsd' => [1064, '3e54e44c492274b51d2f95e451fd3c49f680746043329122917e1d90e8cb1078'],
        'xbrl-country-current-2024-source/label-code.xml' => [265189, '3979fbf969ef766e930cbc0e46fe080e25be4b5331c8cbab6098cd72ae1fd2fa'],
        'xbrl-country-current-2024-source/label-en.xml' => [175580, '7dc28252ea18a2d9261689c4100bef8154461bf282e104cf16131c39601ba155'],
        'xbrl-country-current-2024-source/reference.xml' => [160952, '59fb5844edccd044c0109a84cb2f651b9a2ce3a67377218e34ab87ace15f1612'],
    ];
    $resolver = new GenericXbrlPackageRoot();
    $previousRoot = config('services.report.generic_xbrl_root');
    $sourceFiles = [];
    try {
        config(['services.report.generic_xbrl_root' => $sourceRoot]);
        foreach ($inventory as $relative => [$bytes, $sha256]) {
            $source = $resolver->resolve('data/xbrl/taxonomies/'.$relative);
            $sourceFiles[$source] = ['bytes' => $bytes, 'sha256' => $sha256];
        }
        genericXbrlPackageFixtureAssertSource(['source_files' => $sourceFiles]);
    } finally {
        config(['services.report.generic_xbrl_root' => $previousRoot]);
    }

    $parent = dirname(base_path(), 3).'/external-public-xbrl-tests';
    $normalize = static function (string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };
    $normalizedParent = $normalize($parent);
    $normalizedSource = $normalize($sourceRoot);
    if ($normalizedParent === $normalizedSource
        || str_starts_with($normalizedParent, $normalizedSource.'/')
        || str_starts_with($normalizedSource, $normalizedParent.'/')) {
        throw new RuntimeException('Generic XBRL fixture parent must be separate from the read-only source.');
    }
    // Check existing ancestors before creating anything; reject aliases and junctions.
    for ($ancestor = $parent; ; $ancestor = dirname($ancestor)) {
        if (is_link($ancestor) || (file_exists($ancestor)
            && (! is_dir($ancestor) || $normalize((string) realpath($ancestor)) !== $normalize($ancestor)))) {
            throw new RuntimeException('Generic XBRL fixture parent is aliased or invalid.');
        }
        if (dirname($ancestor) === $ancestor) {
            break;
        }
    }
    if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
        throw new RuntimeException('Cannot create external generic XBRL fixture parent.');
    }
    $root = $parent.'/'.bin2hex(random_bytes(16));
    if (file_exists($root) || is_link($root) || ! mkdir($root, 0700)) {
        throw new RuntimeException('Cannot exclusively allocate external generic XBRL fixture root.');
    }

    foreach ($inventory as $relative => [$bytes, $sha256]) {
        $source = rtrim($sourceRoot, '/\\').'/data/xbrl/taxonomies/'.$relative;
        $destination = $root.'/data/xbrl/taxonomies/'.$relative;
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
            throw new RuntimeException('Cannot create external generic XBRL fixture directories.');
        }
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot read external generic XBRL source.');
        }
        try {
            $output = fopen($destination, 'xb');
            if ($output === false) {
                throw new RuntimeException('Cannot exclusively create external generic XBRL fixture file.');
            }
            try {
                if (stream_copy_to_stream($input, $output) !== $bytes) {
                    throw new RuntimeException('External generic XBRL fixture copy size mismatch.');
                }
            } finally {
                fclose($output);
            }
        } finally {
            fclose($input);
        }
        clearstatcache(true, $destination);
        if (filesize($destination) !== $bytes || hash_file('sha256', $destination) !== $sha256) {
            throw new RuntimeException('External generic XBRL fixture copy integrity mismatch.');
        }
    }

    $fixture = ['root' => $root, 'source_root' => $sourceRoot, 'source_files' => $sourceFiles];
    genericXbrlPackageFixtureAssertSource($fixture);
    config(['services.report.generic_xbrl_root' => $root]);

    // Clones remain outside PUBLIC for HOST inspection; tests never clean up custody.
    return $fixture;
}

/** @param array{source_files: array<string, array{bytes: int, sha256: string}>} $fixture */
function genericXbrlPackageFixtureAssertSource(array $fixture): void
{
    foreach ($fixture['source_files'] as $path => $expected) {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) !== $expected['bytes']
            || hash_file('sha256', $path) !== $expected['sha256']) {
            throw new RuntimeException('Read-only generic XBRL source integrity changed: '.$path);
        }
    }
}
