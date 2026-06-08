<?php

namespace App\Services;

use App\Models\Characterization;
use App\Services\Contracts\CharacterizationGateway;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MockCharacterizationGateway implements CharacterizationGateway
{
    public function submit(Characterization $characterization): array
    {
        $payload = [
            'characterization_id' => $characterization->id,
            'timestamp' => now()->toIso8601String(),
            'nace_code' => $characterization->nace_code,
            'topics' => $characterization->esrs_topic_ids,
            'notes' => Arr::get($characterization->form_data, 'notes'),
        ];

        $mockOutcome = config('services.characterization.mock_outcome', 'success');

        if ($mockOutcome === 'fail') {
            throw new \RuntimeException('Mocked external service failure');
        }

        return [
            'status' => 'completed',
            'score' => random_int(60, 95),
            'summary' => Str::title('mocked characterization result'),
            'payload' => $payload,
        ];
    }
}
