<?php

namespace App\Services\Report;

use RuntimeException;
use Throwable;

class ExternalTaxonomyManifestRepository
{
    /**
     * @return array{
     *     schema_version: string,
     *     profile_id: string,
     *     taxonomy_entrypoint: string,
     *     taxonomy_package_checksum: string,
     *     external_taxonomy_package_confirmed: true
     * }
     */
    public function loadForProfile(ReportingProfile $profile): array
    {
        $manifest = $this->loadInternalForProfile($profile);
        unset($manifest['taxonomy_package_path']);

        return $manifest;
    }

    /**
     * Internal-only loader for renderers/validators that must pass the external
     * package to Arelle. Do not expose this array through HTTP or artefacts.
     *
     * @return array{
     *     schema_version: string,
     *     profile_id: string,
     *     taxonomy_entrypoint: string,
     *     taxonomy_package_path: string,
     *     taxonomy_package_checksum: string,
     *     external_taxonomy_package_confirmed: true
     * }
     */
    public function loadInternalForProfile(ReportingProfile $profile): array
    {
        $manifestPath = config('services.report.external_taxonomy_manifest_path');
        if (! is_string($manifestPath) || trim($manifestPath) === '' || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('external_taxonomy_manifest_missing');
        }

        try {
            $data = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('external_taxonomy_manifest_malformed');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('external_taxonomy_manifest_malformed');
        }

        if (($data['schema_version'] ?? null) !== 'external_taxonomy_manifest_v1') {
            throw new RuntimeException('external_taxonomy_manifest_schema_mismatch');
        }

        if (($data['profile_id'] ?? null) !== $profile->profileId()) {
            throw new RuntimeException('external_taxonomy_profile_mismatch');
        }

        $entrypoint = $data['taxonomy_entrypoint'] ?? null;
        if (! is_string($entrypoint) || $entrypoint !== ($profile->taxonomy()['entrypoint'] ?? null)) {
            throw new RuntimeException('external_taxonomy_entrypoint_mismatch');
        }

        if (($data['external_taxonomy_package_confirmed'] ?? null) !== true) {
            throw new RuntimeException('external_taxonomy_not_confirmed');
        }

        $checksum = $data['taxonomy_package_checksum'] ?? null;
        if (! is_string($checksum) || ! preg_match('/\A[a-fA-F0-9]{64}\z/', $checksum)) {
            throw new RuntimeException('external_taxonomy_package_checksum_invalid');
        }

        $packagePath = $data['taxonomy_package_path'] ?? null;
        if (! is_string($packagePath) || trim($packagePath) === '') {
            throw new RuntimeException('external_taxonomy_package_missing');
        }

        $realPackagePath = realpath($packagePath);
        if ($realPackagePath === false || ! is_file($realPackagePath) || ! is_readable($realPackagePath)) {
            throw new RuntimeException('external_taxonomy_package_missing');
        }

        if ($this->isForbiddenPackagePath($realPackagePath)) {
            throw new RuntimeException('external_taxonomy_package_path_forbidden');
        }

        if (strtolower(hash_file('sha256', $realPackagePath)) !== strtolower($checksum)) {
            throw new RuntimeException('external_taxonomy_package_checksum_mismatch');
        }

        return [
            'schema_version' => 'external_taxonomy_manifest_v1',
            'profile_id' => $profile->profileId(),
            'taxonomy_entrypoint' => $entrypoint,
            'taxonomy_package_path' => $realPackagePath,
            'taxonomy_package_checksum' => strtolower($checksum),
            'external_taxonomy_package_confirmed' => true,
        ];
    }

    private function isForbiddenPackagePath(string $realPackagePath): bool
    {
        foreach ([base_path(), storage_path()] as $forbiddenRoot) {
            $realRoot = realpath($forbiddenRoot);
            if ($realRoot !== false && $this->isSameOrChildPath($realPackagePath, $realRoot)) {
                return true;
            }
        }

        return false;
    }

    private function isSameOrChildPath(string $path, string $root): bool
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot.'/');
    }
}
