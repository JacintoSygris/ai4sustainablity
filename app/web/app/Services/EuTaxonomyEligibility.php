<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\EuTaxonomyDeclaration;
use RuntimeException;

final class EuTaxonomyEligibility
{
    public function catalogue(): array
    {
        $path = base_path('data/eu_taxonomy_activities_v1.json');
        $bytes = file_get_contents($path);
        $expected = explode(' ', trim(file_get_contents($path.'.sha256')))[0];
        if (! hash_equals($expected, hash('sha256', $bytes))) {
            throw new RuntimeException('EU Taxonomy catalogue checksum mismatch.');
        }

        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /** No revision guessing: P5 uses CNAE-2025; direct Rev.2 input is for explicit callers only. */
    public function match(string $code, string $classification = 'cnae_2025'): array
    {
        $catalogue = $this->catalogue();
        $codes = [];
        if ($classification === 'nace_rev2' && preg_match('/^[A-U][0-9]{2}(?:\.[0-9]{1,2})?$/D', $code)) {
            $codes = [$code];
        } elseif (in_array($classification, ['cnae_2025', 'nace_rev21'], true)) {
            foreach ($catalogue['crosswalk'] as $row) {
                $keys = $classification === 'cnae_2025'
                    ? [$row['p5_code'], $row['cnae2025_code']]
                    : [$row['nace_rev21_code']];
                if (in_array($code, $keys, true)) {
                    $codes = $row['nace_rev2_codes'];
                    break;
                }
            }
        }

        $ids = [];
        foreach ($catalogue['activities'] as $activity) {
            foreach ($activity['nace_codes'] as $listed) {
                foreach ($codes as $mapped) {
                    // Only exact classes or a listed parent. Never expand a broad company code.
                    if ($mapped === $listed || (strlen($listed) < strlen($mapped) && str_starts_with($mapped, $listed))) {
                        $ids[] = $activity['id'];
                    }
                }
            }
        }

        return [
            'classification' => $classification,
            'input_code' => $code,
            'status' => $codes === [] ? 'manual_review_required' : 'mapped_subset',
            'nace_rev2_codes' => $codes,
            'potential_activity_ids' => array_values(array_unique($ids)),
        ];
    }

    public function screen(Characterization $characterization): array
    {
        $catalogue = $this->catalogue();
        $hash = hash_file('sha256', base_path('data/eu_taxonomy_activities_v1.json'));
        // Bind declarations to the entity and reporting boundary, even when the
        // same P5 row, sector and year are reused for a different company/group.
        $sourceContext = [
            'characterization_id' => $characterization->id,
            'classification' => 'cnae_2025',
            'input_code' => $characterization->nace_code,
            'reporting_year' => data_get($characterization->form_data, 'company_profile.reporting_year'),
            'catalogue_sha256' => $hash,
            'company_name' => data_get($characterization->form_data, 'company_profile.company_name'),
            'entity_identifier' => data_get($characterization->form_data, 'company_profile.entity_identifier'),
            'entity_identifier_scheme' => data_get($characterization->form_data, 'company_profile.entity_identifier_scheme'),
            'reporting_scope' => data_get($characterization->form_data, 'company_profile.reporting_scope'),
        ];
        $sourceHash = hash('sha256', json_encode($sourceContext, JSON_THROW_ON_ERROR));
        $declaration = EuTaxonomyDeclaration::where('characterization_id', $characterization->id)->first();
        $stale = $declaration !== null && ! hash_equals($sourceHash, $declaration->source_hash);

        return [
            'type' => 'eu_taxonomy_eligibility_screening',
            'locale' => app()->getLocale(),
            'characterization_id' => $characterization->id,
            'reporting_year' => $sourceContext['reporting_year'],
            'catalogue_sha256' => $hash,
            'source_hash' => $sourceHash,
            'source_context' => $sourceContext,
            'revision' => $declaration?->revision ?? 0,
            'complete' => false,
            'coverage' => $catalogue['coverage'],
            'disclaimer' => __('eu_taxonomy.disclaimer'),
            'coverage_notice' => __('eu_taxonomy.coverage'),
            'classification_notice' => __('eu_taxonomy.classification'),
            'mapping' => $this->match((string) $characterization->nace_code),
            'activities' => $catalogue['activities'],
            'declaration_status' => $stale ? 'stale' : ($declaration === null ? 'not_declared' : 'user_declared'),
            'declared_at' => $declaration?->updated_at?->toISOString(),
            'declared_source_hash' => $declaration?->source_hash,
            'declared_context' => $declaration?->source_context,
            // Old declarations remain reviewable, but cannot count as current confirmations.
            'confirmed_activities' => $stale ? [] : ($declaration?->activities ?? []),
            'previous_declarations' => $stale ? $declaration->activities : [],
        ];
    }

    /** Current user declarations are an independent annex, never part of an approved NEIS snapshot. */
    public function evidence(Characterization $characterization): array
    {
        $screen = $this->screen($characterization);
        $catalogue = array_column($screen['activities'], null, 'id');

        return [
            'type' => 'eu_taxonomy_user_declarations_v1',
            'scope' => 'current_declarations_outside_approved_report_snapshot',
            'generated_at' => now()->toISOString(),
            'disclaimer' => $screen['disclaimer'],
            'coverage_notice' => $screen['coverage_notice'],
            'classification_notice' => $screen['classification_notice'],
            'catalogue_sha256' => $screen['catalogue_sha256'],
            'complete' => false,
            'coverage' => $screen['coverage'],
            'source_hash' => $screen['source_hash'],
            'source_context' => $screen['source_context'],
            'reporting_year' => $screen['reporting_year'],
            'declaration_status' => $screen['declaration_status'],
            'declared_at' => $screen['declared_at'],
            'declared_source_hash' => $screen['declared_source_hash'],
            'declared_context' => $screen['declared_context'],
            'mapping' => $screen['mapping'],
            'revision' => $screen['revision'],
            'value_provenance' => 'user_declared_unverified_percentages',
            'alignment_assessed' => false,
            'regulatory_kpis_calculated' => false,
            'confirmed_activities' => array_map(fn (array $row) => [
                ...$row,
                'activity' => $catalogue[$row['activity_id']],
            ], $screen['confirmed_activities']),
            'previous_declarations' => $screen['previous_declarations'],
        ];
    }
}
