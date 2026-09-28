<?php

namespace App\Services\Report;

use App\Models\Characterization;
use Illuminate\Support\Arr;

final class ReportContentScope
{
    /**
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    public function completedDatapointIds(Characterization $characterization, array $corpus): array
    {
        $responses = Arr::get(
            $characterization->form_data ?? [],
            'esrs_datapoint_responses.responses',
            [],
        );

        if (! is_array($responses)) {
            return [];
        }

        $allowedIds = array_fill_keys($this->datapointIds($corpus), true);
        $completedIds = [];

        foreach ($responses as $datapointId => $response) {
            $datapointId = (string) $datapointId;
            if (! isset($allowedIds[$datapointId])
                || ! is_array($response)
                || ($response['status'] ?? null) !== 'completed') {
                continue;
            }

            $completedIds[$datapointId] = true;
        }

        $ids = array_keys($completedIds);
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    public function datapointIds(array $corpus): array
    {
        $ids = collect(Arr::get($corpus, 'blocks', []))
            ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }
}
