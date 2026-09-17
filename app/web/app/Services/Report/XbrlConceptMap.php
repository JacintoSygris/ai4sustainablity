<?php

namespace App\Services\Report;

class XbrlConceptMap
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    public function conceptFor(string $datapointId): ?array
    {
        $concepts = $this->load()['concepts'];

        if (! array_key_exists($datapointId, $concepts)) {
            return ['concept_id' => null, 'taggable_state' => 'not_taggable', 'reason_code' => 'unknown_datapoint'];
        }

        $entry = $concepts[$datapointId];

        return [
            'concept_id' => $entry['concept_id'] ?? null,
            'taggable_state' => $entry['taggable_state'] ?? 'unmapped',
            'reason_code' => $entry['reason_code'] ?? null,
        ];
    }

    public function version(): string
    {
        return $this->load()['version'];
    }

    public function sha256(): string
    {
        return hash('sha256', file_get_contents($this->path()));
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        return self::$data ??= json_decode(file_get_contents($this->path()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function path(): string
    {
        return base_path('data/atomizer_xbrl_concepts_v1.json');
    }
}
