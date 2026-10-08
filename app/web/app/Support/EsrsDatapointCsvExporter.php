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
        'default_selected',
        'selection_reasons',
    ];

    /**
     * @param  array<string, mixed>  $corpus
     */
    public function toCsv(array $corpus, ?string $locale = null): string
    {
        $catalogue = $locale === null ? null : new EsrsDisplayCatalogue;
        if ($catalogue) {
            $corpus = $catalogue->project($corpus, $locale);
        }
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $catalogue ? $catalogue->headers([...self::COLUMNS, 'selection_reason_labels'], $locale) : self::COLUMNS);

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

                fputcsv($handle, $this->row($block, $datapoint, $disclosureRequirementByDatapoint, $locale, $catalogue));
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
    private function row(array $block, array $datapoint, array $disclosureRequirementByDatapoint, ?string $locale, ?EsrsDisplayCatalogue $catalogue): array
    {
        $phaseIn = is_array($datapoint['phase_in'] ?? null) ? $datapoint['phase_in'] : [];
        $selection = is_array($datapoint['selection'] ?? null) ? $datapoint['selection'] : [];
        $datapointId = (string) ($datapoint['id'] ?? '');

        $display = $datapoint['display'] ?? [];
        $boolean = fn (bool $value) => $catalogue ? $catalogue->text($value ? 'true' : 'false', $locale) : ($value ? 'true' : 'false');

        return [
            (string) ($block['key'] ?? ''),
            (string) ($block['title'] ?? ''),
            $disclosureRequirementByDatapoint[$datapointId] ?? '',
            $datapointId,
            (string) ($datapoint['standard'] ?? ''),
            (string) ($datapoint['dr'] ?? ''),
            (string) ($datapoint['paragraph'] ?? ''),
            (string) ($datapoint['related_ar'] ?? ''),
            (string) ($display['name'] ?? $datapoint['name'] ?? ''),
            (string) ($display['data_type'] ?? $datapoint['data_type'] ?? ''),
            (string) ($display['conditional_or_alternative'] ?? $datapoint['conditional_or_alternative'] ?? ''),
            $boolean((bool) ($datapoint['may_disclose'] ?? false)),
            (string) ($datapoint['appendix_b'] ?? ''),
            (string) ($display['phase_in']['less_than_750'] ?? $phaseIn['less_than_750'] ?? ''),
            (string) ($display['phase_in']['all_undertakings'] ?? $phaseIn['all_undertakings'] ?? ''),
            $boolean((bool) ($selection['default_selected'] ?? true)),
            implode(' | ', array_map('strval', $selection['reason_codes'] ?? [])),
            ...($catalogue ? [implode(' | ', array_map(fn ($code) => $catalogue->text($code, $locale), $selection['reason_codes'] ?? []))] : []),
        ];
    }
}
