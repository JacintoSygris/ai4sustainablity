<?php

namespace App\Support;

class EsrsDatapointResponseCsvExporter
{
    private const COLUMNS = [
        'block_key',
        'disclosure_requirement_key',
        'datapoint_id',
        'standard',
        'dr',
        'name',
        'applicability_reason_code',
        'applicability_reason',
        'applicability_mapping_basis',
        'applicability_limitations',
        'default_selected',
        'selection_reasons',
        'response_status',
        'response_value',
        'evidence_reference',
        'note',
        'response_updated_at',
        'schema_version',
        'reporting_entity_identifier_scheme',
        'reporting_entity_identifier',
        'concept_id',
        'taggable_state',
        'taggable_reason_code',
        'fact_id',
        'fact_value_kind',
        'fact_lexical_value',
        'fact_decimals',
        'fact_unit',
        'fact_period_type',
        'fact_start_date',
        'fact_end_date',
        'fact_instant_date',
        'fact_dimensions',
        'fact_evidence_reference',
    ];

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, mixed>  $responseState
     */
    public function toCsv(array $corpus, array $responseState): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::COLUMNS);

        $responses = is_array($responseState['responses'] ?? null)
            ? $responseState['responses']
            : [];

        foreach (['always_required', 'topical', 'minimum_disclosure_requirements'] as $blockKey) {
            $block = $corpus['blocks'][$blockKey] ?? null;

            if (! is_array($block)) {
                continue;
            }

            $disclosureRequirementByDatapoint = $this->disclosureRequirementByDatapoint($block);

            foreach (($block['datapoints'] ?? []) as $datapoint) {
                if (! is_array($datapoint)) {
                    continue;
                }

                $datapointId = (string) ($datapoint['id'] ?? '');
                $response = $responses[$datapointId] ?? [];

                foreach ($this->rows(
                    $block,
                    $datapoint,
                    $disclosureRequirementByDatapoint,
                    is_array($response) ? $response : [],
                    $responseState
                ) as $row) {
                    fputcsv($handle, $row);
                }
            }
        }

        rewind($handle);

        $content = stream_get_contents($handle);
        fclose($handle);

        return $content === false ? '' : $content;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, string>
     */
    private function disclosureRequirementByDatapoint(array $block): array
    {
        $lookup = [];

        foreach (($block['disclosure_requirements'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach (($group['datapoint_ids'] ?? []) as $datapointId) {
                $lookup[(string) $datapointId] = (string) ($group['key'] ?? '');
            }
        }

        return $lookup;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $datapoint
     * @param  array<string, string>  $disclosureRequirementByDatapoint
     * @param  array<string, mixed>  $response
     * @return array<int, string>
     */
    private function rows(
        array $block,
        array $datapoint,
        array $disclosureRequirementByDatapoint,
        array $response,
        array $responseState,
    ): array {
        $facts = is_array($response['facts'] ?? null) && $response['facts'] !== []
            ? array_values($response['facts'])
            : [null];

        return array_map(
            fn ($fact): array => $this->row($block, $datapoint, $disclosureRequirementByDatapoint, $response, $responseState, is_array($fact) ? $fact : null),
            $facts
        );
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $datapoint
     * @param  array<string, string>  $disclosureRequirementByDatapoint
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $responseState
     * @param  array<string, mixed>|null  $fact
     * @return array<int, string>
     */
    private function row(
        array $block,
        array $datapoint,
        array $disclosureRequirementByDatapoint,
        array $response,
        array $responseState,
        ?array $fact,
    ): array {
        $datapointId = (string) ($datapoint['id'] ?? '');
        $applicability = is_array($datapoint['applicability'] ?? null)
            ? $datapoint['applicability']
            : [];
        $selection = is_array($datapoint['selection'] ?? null) ? $datapoint['selection'] : [];
        $concept = is_array($fact['concept'] ?? null)
            ? $fact['concept']
            : (is_array($response['concept'] ?? null) ? $response['concept'] : []);
        $context = is_array($fact['context'] ?? null) ? $fact['context'] : [];
        $unit = is_array($fact['unit'] ?? null) ? $fact['unit'] : [];
        $entity = is_array($responseState['reporting_entity'] ?? null) ? $responseState['reporting_entity'] : [];

        return [
            (string) ($block['key'] ?? ''),
            $disclosureRequirementByDatapoint[$datapointId] ?? '',
            $datapointId,
            (string) ($datapoint['standard'] ?? ''),
            (string) ($datapoint['dr'] ?? ''),
            (string) ($datapoint['name'] ?? ''),
            (string) ($applicability['reason_code'] ?? ''),
            (string) ($applicability['reason'] ?? ''),
            (string) ($applicability['mapping_basis'] ?? ''),
            implode(' | ', array_map('strval', $applicability['limitations'] ?? [])),
            ($selection['default_selected'] ?? true) ? 'true' : 'false',
            implode(' | ', array_map('strval', $selection['reason_codes'] ?? [])),
            (string) ($response['status'] ?? ''),
            (string) ($response['legacy_value'] ?? $response['value'] ?? ''),
            (string) ($response['evidence_reference'] ?? ''),
            (string) ($response['note'] ?? ''),
            (string) ($response['updated_at'] ?? ''),
            (string) ($responseState['schema_version'] ?? ''),
            (string) ($entity['identifier_scheme'] ?? ''),
            (string) ($entity['identifier'] ?? ''),
            (string) ($concept['concept_id'] ?? ''),
            (string) ($concept['taggable_state'] ?? ''),
            (string) ($concept['reason_code'] ?? ''),
            (string) ($fact['fact_id'] ?? ''),
            (string) ($fact['value_kind'] ?? ''),
            is_bool($fact['value'] ?? null) ? (($fact['value'] ?? false) ? 'true' : 'false') : (string) ($fact['value'] ?? ''),
            array_key_exists('decimals', $fact ?? []) && $fact['decimals'] !== null ? (string) $fact['decimals'] : '',
            (string) ($unit['measure'] ?? ''),
            (string) ($context['period_type'] ?? ''),
            (string) ($context['start_date'] ?? ''),
            (string) ($context['end_date'] ?? ''),
            (string) ($context['instant_date'] ?? ''),
            $this->serializedDimensions($context['dimensions'] ?? []),
            (string) ($fact['evidence_reference'] ?? ''),
        ];
    }

    private function serializedDimensions(mixed $dimensions): string
    {
        if (! is_array($dimensions)) {
            return '';
        }

        $normalized = collect($dimensions)
            ->filter(fn ($dimension): bool => is_array($dimension))
            ->map(fn (array $dimension): array => [
                'axis' => (string) ($dimension['axis'] ?? ''),
                'member' => (string) ($dimension['member'] ?? ''),
            ])
            ->sortBy('axis')
            ->values()
            ->all();

        return $normalized === [] ? '' : json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
