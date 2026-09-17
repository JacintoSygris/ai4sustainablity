<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Throwable;

class Ar16MatterDrMappingRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function approved(): ?array
    {
        $path = config('services.esrs_datapoints.matter_dr_mapping_path');

        if (blank($path) || ! is_string($path) || ! is_file($path)) {
            return null;
        }

        try {
            $payload = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (Arr::get($payload, 'source.status') !== 'approved') {
            return null;
        }

        $duplicateTopicIds = [];
        $seenTopicIds = [];
        $byTopicId = [];

        foreach (Arr::get($payload, 'mappings', []) as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $topicId = (int) Arr::get($mapping, 'ar16_topic_id');
            $disclosureRequirements = collect(Arr::get($mapping, 'disclosure_requirements', []))
                ->filter(fn ($key) => filled($key))
                ->map(fn ($key) => trim((string) $key))
                ->unique()
                ->values()
                ->all();

            if ($topicId <= 0 || $disclosureRequirements === []) {
                continue;
            }

            if (isset($seenTopicIds[$topicId])) {
                $duplicateTopicIds[$topicId] = $topicId;
            }

            $seenTopicIds[$topicId] = true;
            $byTopicId[$topicId] = [
                'ar16_topic_id' => $topicId,
                'esrs_code' => trim((string) Arr::get($mapping, 'esrs_code', '')),
                'disclosure_requirements' => $disclosureRequirements,
            ];
        }

        if ($byTopicId === []) {
            return null;
        }

        return [
            'version' => (string) Arr::get($payload, 'version', 'v0'),
            'source' => [
                'name' => Arr::get($payload, 'source.name'),
                'status' => Arr::get($payload, 'source.status'),
                'approved_at' => Arr::get($payload, 'source.approved_at'),
            ],
            'by_topic_id' => $byTopicId,
            'duplicate_topic_ids' => array_values($duplicateTopicIds),
        ];
    }
}
