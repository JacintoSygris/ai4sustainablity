<?php

namespace App\Support;

class EsrsDatapointCsvExporter
{
    private const COLUMNS = [
        'block_key',
        'block_title',
        'disclosure_requirement_key',
        'datapoint_id',
        'standard',
        'dr',
        'paragraph',
        'related_ar',
        'name',
        'data_type',
        'conditional_or_alternative',
        'may_disclose',
        'appendix_b',
        'phase_in_less_than_750',
        'phase_in_all_undertakings',
    ];

    /**
     * @param  array<string, mixed>  $corpus
     */
    public function toCsv(array $corpus): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, self::COLUMNS);

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

                fputcsv($handle, $this->row($block, $datapoint, $disclosureRequirementByDatapoint));
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
     * @return array<int, string>
     */
    private function row(array $block, array $datapoint, array $disclosureRequirementByDatapoint): array
    {
        $phaseIn = is_array($datapoint['phase_in'] ?? null) ? $datapoint['phase_in'] : [];
        $datapointId = (string) ($datapoint['id'] ?? '');

        return [
            (string) ($block['key'] ?? ''),
            (string) ($block['title'] ?? ''),
            $disclosureRequirementByDatapoint[$datapointId] ?? '',
            $datapointId,
            (string) ($datapoint['standard'] ?? ''),
            (string) ($datapoint['dr'] ?? ''),
            (string) ($datapoint['paragraph'] ?? ''),
            (string) ($datapoint['related_ar'] ?? ''),
            (string) ($datapoint['name'] ?? ''),
            (string) ($datapoint['data_type'] ?? ''),
            (string) ($datapoint['conditional_or_alternative'] ?? ''),
            ($datapoint['may_disclose'] ?? false) ? 'true' : 'false',
            (string) ($datapoint['appendix_b'] ?? ''),
            (string) ($phaseIn['less_than_750'] ?? ''),
            (string) ($phaseIn['all_undertakings'] ?? ''),
        ];
    }
}
