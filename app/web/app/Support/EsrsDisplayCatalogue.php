<?php

namespace App\Support;

use LogicException;

/** Versioned UI wording, not an official translation. Never changes regulatory source data. */
final class EsrsDisplayCatalogue
{
    private array $catalogue;
    private static ?array $loaded = null;
    private static ?array $canonicalNames = null;

    public function __construct()
    {
        $this->catalogue = self::$loaded ??= json_decode(file_get_contents(dirname(__DIR__, 2).'/data/esrs_datapoint_labels_es_v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function claimLabel(string $id, string $locale = 'es'): ?string
    {
        if (! isset($this->catalogue['labels'][$id])) return null;
        if (ApplicationLocale::normalize($locale) === 'es') return $this->catalogue['labels'][$id];
        self::$canonicalNames ??= array_column(json_decode(file_get_contents(dirname(__DIR__, 2).'/data/esrs_datapoints_ig3.json'), true, 512, JSON_THROW_ON_ERROR)['datapoints'], 'name', 'id');

        return self::$canonicalNames[$id] ?? throw new LogicException('Missing canonical datapoint label: '.$id);
    }

    public function sectionTitle(string $id, string $locale = 'es'): ?string
    {
        return $this->catalogue['disclosure_requirements'][$id][ApplicationLocale::normalize($locale)] ?? null;
    }

    public function qualification(string $id, string $locale): ?string
    {
        return $this->catalogue['qualifications'][$id][ApplicationLocale::normalize($locale)] ?? null;
    }

    public function text(?string $value, string $locale): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (ApplicationLocale::normalize($locale) === 'en') {
            return $this->catalogue['system']['english'][$value]
                ?? throw new LogicException('Missing English system label: '.$value);
        }

        return $this->catalogue['system']['strings'][$value]
            ?? throw new LogicException('Missing Spanish system label: '.$value);
    }

    public function headers(array $columns, string $locale): array
    {
        return array_map(fn (string $column) => $this->catalogue['system']['headers'][$column][ApplicationLocale::normalize($locale)]
            ?? throw new LogicException('Missing localized CSV header: '.$column), $columns);
    }

    public function project(array $corpus, string $locale): array
    {
        $locale = ApplicationLocale::normalize($locale);
        $corpus['locale'] = $locale;
        $corpus['display_catalogue_version'] = $this->catalogue['version'];
        foreach ($corpus['blocks'] ?? [] as $key => $block) {
            foreach (['title', 'note'] as $field) {
                if (isset($block[$field])) {
                    $block[$field] = $this->text($block[$field], $locale);
                }
            }
            foreach ($block['datapoints'] ?? [] as $index => $datapoint) {
                $name = $locale === 'en' ? $datapoint['name'] : ($this->catalogue['labels'][$datapoint['id']]
                    ?? throw new LogicException('Missing Spanish datapoint label: '.$datapoint['id']));
                $block['datapoints'][$index]['display'] = [
                    'locale' => $locale,
                    'name' => $name,
                    'data_type' => $this->text($datapoint['data_type'] ?? 'Unspecified in catalogue', $locale),
                    'disclosure_requirement_title' => $this->sectionTitle((string) ($datapoint['dr'] ?? ''), $locale),
                    'qualification' => $this->qualification($datapoint['id'], $locale),
                    'conditional_or_alternative' => $this->text($datapoint['conditional_or_alternative'] ?? null, $locale),
                    'phase_in' => array_map(fn ($value) => $this->text($value, $locale), $datapoint['phase_in'] ?? []),
                    'applicability_reason' => $this->text($datapoint['applicability']['reason'] ?? null, $locale),
                    'mapping_basis' => $this->text($datapoint['applicability']['mapping_basis'] ?? null, $locale),
                    'limitations' => array_map(fn ($value) => $this->text($value, $locale), $datapoint['applicability']['limitations'] ?? []),
                ];
            }
            foreach ($block['disclosure_requirements'] ?? [] as $index => $requirement) {
                $block['disclosure_requirements'][$index]['display_title'] = $this->sectionTitle((string) ($requirement['dr'] ?? ''), $locale);
            }
            // User-provided E1 explanation and all response/evidence text are deliberately untouched.
            $corpus['blocks'][$key] = $block;
        }
        foreach ($corpus['completion_plan']['phases'] ?? [] as $i => $phase) {
            $corpus['completion_plan']['phases'][$i]['title'] = $this->text($phase['title'], $locale);
            $corpus['completion_plan']['phases'][$i]['status_label'] = $this->text($phase['status'], $locale);
        }
        foreach (['source_note', 'limitations'] as $field) {
            if (isset($corpus['generation'][$field])) {
                $value = $corpus['generation'][$field];
                $corpus['generation'][$field] = is_array($value)
                    ? array_map(fn ($text) => $this->text($text, $locale), $value) : $this->text($value, $locale);
            }
        }
        if (isset($corpus['matter_mapping']['limitation'])) {
            $corpus['matter_mapping']['limitation'] = $this->text($corpus['matter_mapping']['limitation'], $locale);
        }
        foreach (['note', 'application'] as $field) {
            if ($field === 'application' && isset($corpus['phase_in_assessment'][$field]['note'])) {
                $corpus['phase_in_assessment'][$field]['note'] = $this->text($corpus['phase_in_assessment'][$field]['note'], $locale);
            } elseif ($field === 'note' && isset($corpus['phase_in_assessment'][$field])) {
                $corpus['phase_in_assessment'][$field] = $this->text($corpus['phase_in_assessment'][$field], $locale);
            }
        }

        return $corpus;
    }
}
