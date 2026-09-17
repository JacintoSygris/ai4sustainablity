<?php

namespace App\Services\Report;

use RuntimeException;
use Throwable;
use ZipArchive;

class XbrlCountryTaxonomyPackage
{
    private const VERSION = 'xbrl-country-current-2024-snapshot';
    private const RELATIVE_PATH = 'data/xbrl/taxonomies/xbrl-country-current-2024-snapshot.zip';
    private const MANIFEST_RELATIVE_PATH = 'data/xbrl/taxonomies/xbrl-country-current-2024-snapshot.manifest.json';
    private const SOURCE_RELATIVE_PATH = 'data/xbrl/taxonomies/xbrl-country-current-2024-source';
    private const SOURCE_BASE_URL = 'https://www.xbrl.org/taxonomy/int/country/current/';
    private const EXPECTED_SHA256 = 'bbcaa6097bdbfa67880b3f1e7435fc203eaa8ed24fdb6c093efa75687fcf4e55';
    private const EXPECTED_SIZE = 35563;
    private const EXPECTED_MEMBER_COUNT = 9;
    private const REQUIRED_MEMBERS = [
        'xbrl-country-current-2024-snapshot/META-INF/catalog.xml',
        'xbrl-country-current-2024-snapshot/META-INF/taxonomyPackage.xml',
        'xbrl-country-current-2024-snapshot/entry-en.xsd',
        'xbrl-country-current-2024-snapshot/entry.xsd',
        'xbrl-country-current-2024-snapshot/elts.xsd',
        'xbrl-country-current-2024-snapshot/label-en.xml',
        'xbrl-country-current-2024-snapshot/label-code.xml',
        'xbrl-country-current-2024-snapshot/reference.xml',
        'xbrl-country-current-2024-snapshot/definition.xml',
    ];
    private const SOURCE_FILES = [
        'entry-en.xsd' => ['size' => 699, 'sha256' => '6fba8e332c8281002556958efa3c43aa81aec7e1805b5672e51a6bfdb165d324'],
        'entry.xsd' => ['size' => 1064, 'sha256' => '3e54e44c492274b51d2f95e451fd3c49f680746043329122917e1d90e8cb1078'],
        'elts.xsd' => ['size' => 44035, 'sha256' => '1fd8d58b0e07d24611c6baa0b4f88ea1d67d6a65ec9937547b253d2b2cc56bcc'],
        'label-en.xml' => ['size' => 175580, 'sha256' => '7dc28252ea18a2d9261689c4100bef8154461bf282e104cf16131c39601ba155'],
        'label-code.xml' => ['size' => 265189, 'sha256' => '3979fbf969ef766e930cbc0e46fe080e25be4b5331c8cbab6098cd72ae1fd2fa'],
        'reference.xml' => ['size' => 160952, 'sha256' => '59fb5844edccd044c0109a84cb2f651b9a2ce3a67377218e34ab87ace15f1612'],
        'definition.xml' => ['size' => 93071, 'sha256' => 'd4e7fc1b17a635ceef2c36d5304597145e51b1e824200becb4fca29c72a8d973'],
    ];

    public function verifiedPath(): string
    {
        $manifest = $this->manifest();
        $this->verifyManifest($manifest);
        $this->verifySourceMembers($manifest);

        $path = $this->basePath(self::RELATIVE_PATH);

        if (! is_file($path)) {
            throw new RuntimeException('country_taxonomy_package_missing');
        }

        if (filesize($path) !== self::EXPECTED_SIZE || hash_file('sha256', $path) !== self::EXPECTED_SHA256) {
            throw new RuntimeException('country_taxonomy_package_integrity_failed');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('country_taxonomy_package_unreadable');
        }

        try {
            if ($zip->numFiles !== self::EXPECTED_MEMBER_COUNT) {
                throw new RuntimeException('country_taxonomy_package_integrity_failed');
            }

            /** @var array<string, array{size: int, sha256: string}> $expectedZipMembers */
            $expectedZipMembers = [];
            foreach ($manifest['zip_members'] as $member) {
                if (! is_array($member)
                    || ! is_string($member['path'] ?? null)
                    || ! is_int($member['size'] ?? null)
                    || ! is_string($member['sha256'] ?? null)
                ) {
                    throw new RuntimeException('country_taxonomy_manifest_invalid');
                }

                $expectedZipMembers[$member['path']] = [
                    'size' => $member['size'],
                    'sha256' => $member['sha256'],
                ];
            }

            $members = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || $name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true)) {
                    throw new RuntimeException('country_taxonomy_package_integrity_failed');
                }

                $members[$name] = true;
                $contents = $zip->getFromIndex($index);
                if (! is_string($contents)
                    || ! isset($expectedZipMembers[$name])
                    || strlen($contents) !== $expectedZipMembers[$name]['size']
                    || hash('sha256', $contents) !== $expectedZipMembers[$name]['sha256']
                ) {
                    throw new RuntimeException('country_taxonomy_package_integrity_failed');
                }
            }

            foreach (self::REQUIRED_MEMBERS as $required) {
                if (! isset($members[$required])) {
                    throw new RuntimeException('country_taxonomy_package_integrity_failed');
                }
            }

            $catalog = $zip->getFromName($manifest['catalog_member']);
            if (! is_string($catalog) || $catalog === '') {
                throw new RuntimeException('country_taxonomy_package_integrity_failed');
            }

            if (substr_count($catalog, '<rewriteURI ') !== 1
                || ! str_contains($catalog, '<rewriteURI uriStartString="'.self::SOURCE_BASE_URL.'" rewritePrefix="../" />')
                || str_contains($catalog, '<uri ')
            ) {
                throw new RuntimeException('country_taxonomy_catalog_invalid');
            }

            foreach (array_keys(self::SOURCE_FILES) as $filename) {
                if (! isset($members['xbrl-country-current-2024-snapshot/'.$filename])) {
                    throw new RuntimeException('country_taxonomy_catalog_invalid');
                }
            }
        } finally {
            $zip->close();
        }

        return $path;
    }

    /** @return array<string, string|int> */
    public function metadata(): array
    {
        return [
            'version' => self::VERSION,
            'source_base_url' => self::SOURCE_BASE_URL,
            'zip_sha256' => self::EXPECTED_SHA256,
            'zip_size' => self::EXPECTED_SIZE,
            'member_count' => self::EXPECTED_MEMBER_COUNT,
            'path' => self::RELATIVE_PATH,
            'manifest' => self::MANIFEST_RELATIVE_PATH,
        ];
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $path = $this->basePath(self::MANIFEST_RELATIVE_PATH);

        if (! is_file($path)) {
            throw new RuntimeException('country_taxonomy_manifest_missing');
        }

        try {
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('country_taxonomy_manifest_invalid');
        }

        if (! is_array($manifest)) {
            throw new RuntimeException('country_taxonomy_manifest_invalid');
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function verifyManifest(array $manifest): void
    {
        if (($manifest['schema_version'] ?? null) !== 1
            || ($manifest['version'] ?? null) !== self::VERSION
            || ($manifest['source_base_url'] ?? null) !== self::SOURCE_BASE_URL
            || ($manifest['source_directory'] ?? null) !== self::SOURCE_RELATIVE_PATH
            || ($manifest['zip_path'] ?? null) !== self::RELATIVE_PATH
            || ($manifest['zip_sha256'] ?? null) !== self::EXPECTED_SHA256
            || ($manifest['zip_size'] ?? null) !== self::EXPECTED_SIZE
            || ($manifest['member_count'] ?? null) !== self::EXPECTED_MEMBER_COUNT
            || ($manifest['catalog_member'] ?? null) !== 'xbrl-country-current-2024-snapshot/META-INF/catalog.xml'
            || ($manifest['taxonomy_package_member'] ?? null) !== 'xbrl-country-current-2024-snapshot/META-INF/taxonomyPackage.xml'
            || ! is_array($manifest['source_members'] ?? null)
            || ! is_array($manifest['zip_members'] ?? null)
        ) {
            throw new RuntimeException('country_taxonomy_manifest_invalid');
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function verifySourceMembers(array $manifest): void
    {
        $sourceMembers = [];

        foreach ($manifest['source_members'] as $member) {
            if (! is_array($member)
                || ! is_string($member['source_url'] ?? null)
                || ! is_string($member['filename'] ?? null)
                || ! is_int($member['size'] ?? null)
                || ! is_string($member['sha256'] ?? null)
                || ! is_string($member['local_member'] ?? null)
            ) {
                throw new RuntimeException('country_taxonomy_manifest_invalid');
            }

            $filename = $member['filename'];
            if (! isset(self::SOURCE_FILES[$filename])
                || $member['source_url'] !== self::SOURCE_BASE_URL.$filename
                || $member['size'] !== self::SOURCE_FILES[$filename]['size']
                || $member['sha256'] !== self::SOURCE_FILES[$filename]['sha256']
                || $member['local_member'] !== 'xbrl-country-current-2024-snapshot/'.$filename
            ) {
                throw new RuntimeException('country_taxonomy_manifest_invalid');
            }

            $sourceMembers[$filename] = true;
            $path = $this->basePath(self::SOURCE_RELATIVE_PATH.'/'.$filename);
            if (! is_file($path)) {
                throw new RuntimeException('country_taxonomy_source_missing');
            }

            if (filesize($path) !== $member['size'] || hash_file('sha256', $path) !== $member['sha256']) {
                throw new RuntimeException('country_taxonomy_source_integrity_failed');
            }
        }

        if (array_diff(array_keys(self::SOURCE_FILES), array_keys($sourceMembers)) !== []
            || array_diff(array_keys($sourceMembers), array_keys(self::SOURCE_FILES)) !== []
        ) {
            throw new RuntimeException('country_taxonomy_manifest_invalid');
        }
    }

    private function basePath(string $path): string
    {
        try {
            return base_path($path);
        } catch (Throwable) {
            return dirname(__DIR__, 3).'/'.$path;
        }
    }
}
