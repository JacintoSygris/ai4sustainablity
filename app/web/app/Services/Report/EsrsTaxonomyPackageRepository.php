<?php

namespace App\Services\Report;

use RuntimeException;
use Throwable;

class EsrsTaxonomyPackageRepository
{
    private const VERSION = 'esrs-set1-2024';

    private const REQUIRED_KEYS = [
        'schema_version',
        'version',
        'source_url',
        'published_date',
        'zip_sha256',
        'entrypoint',
        'core_entrypoint',
        'catalog',
        'taxonomy_package',
        'members',
    ];

    public function verified(string $version): EsrsTaxonomyPackage
    {
        if ($version !== self::VERSION) {
            throw new RuntimeException('taxonomy_package_unknown');
        }

        $root = $this->basePath("data/xbrl/taxonomies/{$version}");
        $manifest = $this->loadManifest($root);

        $this->assertManifestShape($manifest, $version);
        $this->assertRelativePath($manifest['entrypoint']);
        $this->assertRelativePath($manifest['core_entrypoint']);
        $this->assertRelativePath($manifest['catalog']);
        $this->assertRelativePath($manifest['taxonomy_package']);

        $memberPaths = [];

        foreach ($manifest['members'] as $member) {
            if (! is_array($member)
                || ! is_string($member['path'] ?? null)
                || ! is_string($member['type'] ?? null)
                || ! is_string($member['sha256'] ?? null)
                || ! is_int($member['size'] ?? null)
            ) {
                throw new RuntimeException('taxonomy_manifest_invalid');
            }

            $this->assertRelativePath($member['path']);
            $memberPaths[$member['path']] = true;
            $path = $root.'/'.$member['path'];

            if ($member['type'] === 'directory') {
                if (! is_dir($path) || $member['size'] !== 0 || $member['sha256'] !== hash('sha256', '')) {
                    throw new RuntimeException('taxonomy_directory_invalid');
                }

                continue;
            }

            if ($member['type'] !== 'file') {
                throw new RuntimeException('taxonomy_manifest_invalid');
            }

            if (! is_file($path)) {
                throw new RuntimeException('taxonomy_member_missing');
            }

            if (filesize($path) !== $member['size']) {
                throw new RuntimeException('taxonomy_member_size_mismatch');
            }

            if (hash_file('sha256', $path) !== $member['sha256']) {
                throw new RuntimeException('taxonomy_member_hash_mismatch');
            }
        }

        foreach ([$manifest['entrypoint'], $manifest['core_entrypoint'], $manifest['catalog'], $manifest['taxonomy_package']] as $required) {
            if (! isset($memberPaths[$required])) {
                throw new RuntimeException('taxonomy_required_member_missing');
            }
        }

        return new EsrsTaxonomyPackage($root, $manifest);
    }

    private function basePath(string $path): string
    {
        try {
            return base_path($path);
        } catch (Throwable) {
            return dirname(__DIR__, 3).'/'.$path;
        }
    }

    /** @return array<string,mixed> */
    private function loadManifest(string $root): array
    {
        $path = $root.'/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException('taxonomy_manifest_missing');
        }

        try {
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('taxonomy_manifest_invalid');
        }

        if (! is_array($manifest)) {
            throw new RuntimeException('taxonomy_manifest_invalid');
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function assertManifestShape(array $manifest, string $version): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException('taxonomy_manifest_invalid');
            }
        }

        if (($manifest['schema_version'] ?? null) !== 1
            || ($manifest['version'] ?? null) !== $version
            || ($manifest['published_date'] ?? null) !== '2024-08-30'
            || ($manifest['zip_sha256'] ?? null) !== 'f9dab98514dbb27b53f6bf94cb1980920e6029d5d21963677954275c217850b3'
            || ($manifest['entrypoint'] ?? null) !== 'xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd'
            || ($manifest['core_entrypoint'] ?? null) !== 'xbrl.efrag.org/taxonomy/esrs/2023-12-22/common/esrs_cor.xsd'
            || ($manifest['catalog'] ?? null) !== 'META-INF/catalog.xml'
            || ($manifest['taxonomy_package'] ?? null) !== 'META-INF/taxonomyPackage.xml'
            || ! is_array($manifest['members'])
        ) {
            throw new RuntimeException('taxonomy_manifest_invalid');
        }
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)
        ) {
            throw new RuntimeException('taxonomy_manifest_invalid');
        }
    }
}
