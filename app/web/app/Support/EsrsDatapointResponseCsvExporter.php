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
        'response_status',
        'response_value',
        'evidence_reference',
        'note',
        'response_updated_at',
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

                fputcsv($handle, $this->row(
                    $block,
                    $datapoint,
                    $disclosureRequirementByDatapoint,
                    is_array($response) ? $response : []
                ));
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
    private function row(
        array $block,
        array $datapoint,
        array $disclosureRequirementByDatapoint,
        array $response,
    ): array {
        $datapointId = (string) ($datapoint['id'] ?? '');
        $applicability = is_array($datapoint['applicability'] ?? null)
            ? $datapoint['applicability']
            : [];

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
            (string) ($response['status'] ?? ''),
            (string) ($response['value'] ?? ''),
            (string) ($response['evidence_reference'] ?? ''),
            (string) ($response['note'] ?? ''),
            (string) ($response['updated_at'] ?? ''),
        ];
    }
}
