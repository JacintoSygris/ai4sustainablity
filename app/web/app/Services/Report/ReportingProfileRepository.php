<?php

namespace App\Services\Report;

use RuntimeException;
use Throwable;

class ReportingProfileRepository
{
    private const PROFILE_ID = 'esrs-2023-preparatory-v1';

    /**
     * @throws ReportingProfileException
     */
    public function load(string $profileId = self::PROFILE_ID): ReportingProfile
    {
        if ($profileId !== self::PROFILE_ID) {
            throw new ReportingProfileException("unknown_reporting_profile:{$profileId}");
        }

        $path = $this->profilePath($profileId);
        $sidecar = $path.'.sha256';

        if (! is_file($path) || ! is_file($sidecar)) {
            throw new ReportingProfileException('missing_reporting_profile_asset');
        }

        $actualHash = hash('sha256', file_get_contents($path));
        $expectedHash = trim(file_get_contents($sidecar));

        if ($actualHash !== $expectedHash) {
            throw new ReportingProfileException('profile_checksum_mismatch');
        }

        try {
            $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new ReportingProfileException('malformed_reporting_profile_json', previous: $e);
        }

        if (! is_array($data)) {
            throw new ReportingProfileException('malformed_reporting_profile_json');
        }

        $this->assertEssentialContract($data);
        $this->assertNoForbiddenPackageReferences($data);
        $this->assertMappingIntegrity($data);

        return new ReportingProfile($data, $actualHash);
    }

    private function profilePath(string $profileId): string
    {
        return base_path("data/reporting_profiles/{$profileId}.json");
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws ReportingProfileException
     */
    private function assertEssentialContract(array $data): void
    {
        if (($data['profile_id'] ?? null) !== self::PROFILE_ID) {
            throw new ReportingProfileException('invalid_profile_id');
        }

        if (($data['schema_version'] ?? null) !== 'reporting_profile_v1') {
            throw new ReportingProfileException('invalid_profile_schema_version');
        }

        if (($data['status'] ?? null) !== 'active') {
            throw new ReportingProfileException('invalid_profile_status');
        }

        if (($data['publication_boundary'] ?? null) !== 'preparatory_candidate') {
            throw new ReportingProfileException('invalid_publication_boundary');
        }

        $taxonomy = $data['taxonomy'] ?? null;
        if (! is_array($taxonomy)) {
            throw new ReportingProfileException('missing_profile_taxonomy');
        }

        if (($taxonomy['entrypoint'] ?? null) !== 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd') {
            throw new ReportingProfileException('invalid_profile_taxonomy_entrypoint');
        }

        if (($taxonomy['provisioning_mode'] ?? null) !== 'external_package_required') {
            throw new ReportingProfileException('invalid_profile_provisioning_mode');
        }

        if (($taxonomy['local_manifest_required'] ?? null) !== true) {
            throw new ReportingProfileException('missing_external_taxonomy_manifest_contract');
        }

        $required = $taxonomy['local_manifest_schema']['required_fields'] ?? null;
        if (! is_array($required)
            || array_values($required) !== [
                'taxonomy_package_path',
                'taxonomy_package_checksum',
                'external_taxonomy_package_confirmed',
            ]) {
            throw new ReportingProfileException('invalid_external_taxonomy_manifest_contract');
        }

        $states = $data['validation']['publication_states'] ?? null;
        if (! is_array($states) || array_values($states) !== ['validated_candidate']) {
            throw new ReportingProfileException('invalid_profile_publication_states');
        }

        $artefacts = $data['rendering']['allowed_artefacts'] ?? null;
        if (! is_array($artefacts) || array_values($artefacts) !== ['html', 'docx', 'evidence_json', 'xhtml_ixbrl_candidate']) {
            throw new ReportingProfileException('invalid_profile_allowed_artefacts');
        }
    }

    /**
     * @param array<string,mixed> $data
     *
     * @throws ReportingProfileException
     */
    private function assertMappingIntegrity(array $data): void
    {
        $assets = $data['mapping_assets'] ?? null;

        if (! is_array($assets) || $assets === []) {
            throw new ReportingProfileException('missing_profile_mapping_assets');
        }

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                throw new ReportingProfileException('invalid_profile_mapping_asset');
            }

            $path = $asset['path'] ?? null;
            $sidecar = $asset['sha256_sidecar'] ?? null;

            if (! is_string($path) || ! is_string($sidecar)) {
                throw new ReportingProfileException('invalid_profile_mapping_asset');
            }

            $absolutePath = base_path($path);
            $absoluteSidecar = base_path($sidecar);

            if (! is_file($absolutePath) || ! is_file($absoluteSidecar)) {
                throw new ReportingProfileException("missing_profile_mapping_asset:{$path}");
            }

            if (hash('sha256', file_get_contents($absolutePath)) !== trim(file_get_contents($absoluteSidecar))) {
                throw new ReportingProfileException("mapping_checksum_mismatch:{$path}");
            }
        }
    }

    /**
     * @param array<string,mixed> $value
     *
     * @throws ReportingProfileException
     */
    private function assertNoForbiddenPackageReferences(array $value): void
    {
        $this->walkProfile($value, function (string $key, mixed $node, array $path): void {
            if (in_array($key, ['package_sha256', 'local_package_path', 'source_package_path'], true)) {
                throw new ReportingProfileException('taxonomy_package_reference_forbidden');
            }

            if (is_string($node) && str_starts_with(strtolower($node), 'file:')) {
                throw new ReportingProfileException('taxonomy_package_reference_forbidden');
            }

            if (is_string($node)
                && preg_match('/(^|[\/\\\\])[^\/\\\\]*\.zip$/i', $node)
                && ! $this->isPolicyForbiddenArtifactPattern($path, $node)
                && ! $this->isExternalSourceUri($key, $node)) {
                throw new ReportingProfileException('taxonomy_package_reference_forbidden');
            }
        });
    }

    private function isExternalSourceUri(string $key, string $value): bool
    {
        if ($key !== 'source_uri') {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }

    /**
     * @param list<string> $path
     */
    private function isPolicyForbiddenArtifactPattern(array $path, string $value): bool
    {
        return count($path) === 3
            && $path[0] === 'taxonomy'
            && $path[1] === 'forbidden_repo_artifacts'
            && in_array(strtolower($value), ['*.zip', '*.xsd', '*.xbrl'], true);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $path
     */
    private function walkProfile(array $value, callable $visit, array $path = []): void
    {
        foreach ($value as $key => $node) {
            $nodePath = [...$path, (string) $key];

            $visit((string) $key, $node, $nodePath);

            if (is_array($node)) {
                $this->walkProfile($node, $visit, $nodePath);
            }
        }
    }
}

class ReportingProfile
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $hash,
    ) {}

    public function profileId(): string
    {
        return $this->data['profile_id'];
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * @return array<string,mixed>
     */
    public function taxonomy(): array
    {
        return $this->data['taxonomy'];
    }

    /**
     * @return array<string,mixed>
     */
    public function validationConfig(): array
    {
        return $this->data['validation'];
    }

    /**
     * @return list<string>
     */
    public function allowedArtefacts(): array
    {
        return array_values($this->data['rendering']['allowed_artefacts']);
    }

    public function supportsValidatedCandidate(): bool
    {
        return in_array('validated_candidate', $this->data['validation']['publication_states'], true);
    }

    public function supportsFilingReady(): bool
    {
        return in_array('filing_ready', $this->data['validation']['publication_states'], true);
    }

    public function requiresExternalTaxonomyPackage(): bool
    {
        return ($this->data['taxonomy']['provisioning_mode'] ?? null) === 'external_package_required';
    }

    /**
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->data;
    }
}

class ReportingProfileException extends RuntimeException
{
    //
}
