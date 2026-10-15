<?php

namespace App\Services\Report;

final class TaxonomyProfileRegistry
{
    public function selectedId(): string
    {
        return (string) config('ixbrl.taxonomy_profile');
    }

    /** Public metadata only; never a local package path. */
    public function describe(string $id): array
    {
        $profiles = config('ixbrl.profiles', []);
        $profile = $profiles[$id] ?? null;
        if (! is_array($profile)) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_profile_unknown');
        }

        return ['id' => $id, ...$profile];
    }

    public function candidate(string $id, ReportingProfile $reportingProfile): array
    {
        $profile = $this->describe($id);
        if (($profile['enabled'] ?? false) !== true) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_profile_disabled');
        }
        if (! is_string($profile['concept_mapping'] ?? null)
            || ! is_string($profile['core_schema'] ?? null)
            || ! in_array($profile['concept_mapping'], array_column($reportingProfile->raw()['mapping_assets'], 'path'), true)
            || ($profile['mapping_profile_id'] ?? null) !== $reportingProfile->profileId()
            || ($profile['entrypoint'] ?? null) !== $reportingProfile->taxonomy()['entrypoint']) {
            throw new XhtmlIxbrlCandidateException('xhtml_ixbrl_taxonomy_mapping_unavailable');
        }

        return $profile;
    }
}
