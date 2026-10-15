<?php

namespace App\Services\Report;

use DomainException;

class XbrlConceptMap
{
    /** One immutable revision per instance; generation operations capture a fresh instance. */
    private ?array $effective = null;

    public function capture(bool $includeOverlay = true, ?string $mappingPath = null): self
    {
        $mappingPath ??= 'data/atomizer_xbrl_concepts_v1.json';
        $bytes = file_get_contents(base_path($mappingPath));
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $overlay = $includeOverlay && $mappingPath === 'data/atomizer_xbrl_concepts_v1.json'
            && (bool) config('xbrl_suggestions.overlay_enabled', false)
            ? (new XbrlConceptSuggestionRepository())->accepted() : [];
        $overlayBytes = $overlay === [] ? '' : json_encode($overlay, JSON_THROW_ON_ERROR);
        foreach ($overlay as $id => $entry) {
            $data['concepts'][$id] = array_replace($data['concepts'][$id] ?? [], $entry);
        }
        $map = clone $this;
        $map->profileData = [];
        $map->effective = [
            'version' => $data['version'].($overlay === [] ? '' : '+reviewed-'.substr(hash('sha256', $overlayBytes), 0, 12)),
            'sha256' => hash('sha256', $bytes.($overlay === [] ? '' : "\n".$overlayBytes)),
            'concepts' => $data['concepts'],
        ];

        return $map;
    }

    /** Restore approval-bound bytes, without reading live candidates or decisions. */
    public static function fromFrozen(array $frozen): self
    {
        if (! is_string($frozen['version'] ?? null)
            || ! is_string($frozen['sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $frozen['sha256'])
            || ! is_array($frozen['concepts'] ?? null)) {
            throw new DomainException('report_snapshot_xbrl_mapping_invalid');
        }
        foreach ($frozen['concepts'] as $id => $entry) {
            if (! is_string($id) || ! is_array($entry)
                || ! in_array($entry['taggable_state'] ?? null, ['mapped', 'unmapped', 'not_taggable'], true)
                || (($entry['concept_id'] ?? null) !== null
                    && (! is_string($entry['concept_id']) || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9_]*\z/', $entry['concept_id'])))
                || (($entry['taggable_state'] ?? null) === 'mapped' && ($entry['concept_id'] ?? null) === null)) {
                throw new DomainException('report_snapshot_xbrl_mapping_invalid');
            }
        }
        $map = new self();
        $map->effective = $frozen;

        return $map;
    }

    public function frozen(): array
    {
        return $this->effective ??= $this->capture()->effective;
    }

    private array $profileData = [];

    public function conceptFor(string $datapointId, ?string $mappingPath = null): ?array
    {
        // Captured approval bytes take precedence over live profile mappings and overlays.
        $concepts = $this->effective !== null || $mappingPath === null
            ? $this->frozen()['concepts']
            : $this->load($mappingPath)['concepts'];
        if (! array_key_exists($datapointId, $concepts)) {
            return ['concept_id' => null, 'taggable_state' => 'not_taggable', 'reason_code' => 'unknown_datapoint'];
        }
        $entry = $concepts[$datapointId];

        return [
            'concept_id' => $entry['concept_id'] ?? null,
            'taggable_state' => $entry['taggable_state'] ?? 'unmapped',
            'reason_code' => $entry['reason_code'] ?? null,
            'required_context' => $entry['required_context'] ?? [],
        ];
    }

    public function version(): string
    {
        return $this->frozen()['version'];
    }

    public function sha256(): string
    {
        return $this->frozen()['sha256'];
    }

    /** @return array<string,mixed> */
    private function load(string $mappingPath): array
    {
        return $this->profileData[$mappingPath] ??= $this->capture(true, $mappingPath)->frozen();
    }
}
