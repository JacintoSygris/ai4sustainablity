<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CharacterizationPredictionMapper
{
    private ?array $mapping = null;

    /**
     * @param  array<string, int|bool>  $rawPrediction
     * @return array<int, array<string, mixed>>
     */
    public function candidateTopics(array $rawPrediction): array
    {
        $suggestedKeys = collect($rawPrediction)
            ->filter(fn ($value) => (int) $value === 1)
            ->keys()
            ->flip();

        return collect(Arr::get($this->mapping(), 'candidate_topics', []))
            ->filter(fn (array $topic) => ($topic['mapping_status'] ?? null) === 'approved')
            ->map(function (array $topic) use ($suggestedKeys) {
                $matchingKeys = collect($topic['python_esrs_keys'] ?? [])
                    ->filter(fn (string $key) => $suggestedKeys->has($key))
                    ->values()
                    ->all();

                if ($matchingKeys === []) {
                    return null;
                }

                return [
                    'ar16_topic_id' => (int) $topic['ar16_topic_id'],
                    'web_esrs' => $topic['web_esrs'],
                    'web_label_en' => $topic['web_label_en'],
                    'python_esrs_keys' => $matchingKeys,
                    'score_source' => 'python_predict',
                    'suggested' => true,
                ];
            })
            ->filter()
            ->sortBy('ar16_topic_id')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|bool>  $rawPrediction
     * @return array<int, string>
     */
    public function reviewRequiredKeys(array $rawPrediction): array
    {
        $mappedKeys = collect(Arr::get($this->mapping(), 'candidate_topics', []))
            ->filter(fn (array $topic) => ($topic['mapping_status'] ?? null) === 'approved')
            ->flatMap(fn (array $topic) => $topic['python_esrs_keys'] ?? [])
            ->flip();

        $keyStatuses = Arr::get($this->mapping(), 'python_key_statuses', []);
        $keyStatuses = is_array($keyStatuses) ? $keyStatuses : [];

        return collect($rawPrediction)
            ->filter(fn ($value) => (int) $value === 1)
            ->keys()
            ->filter(function (string $key) use ($mappedKeys, $keyStatuses) {
                if ($mappedKeys->has($key)) {
                    return false;
                }

                $status = array_key_exists($key, $keyStatuses) ? $keyStatuses[$key] : null;

                return in_array($status, ['needs_review', 'review_only'], true) || $status === null;
            })
            ->values()
            ->all();
    }

    private function mapping(): array
    {
        if ($this->mapping !== null) {
            return $this->mapping;
        }

        $path = config('services.characterization.prediction_mapping_path');

        if (! is_string($path) || blank($path) || ! File::exists($path)) {
            throw new RuntimeException('Characterization prediction mapping file is not configured.');
        }

        $mapping = json_decode(File::get($path), true);

        if (! is_array($mapping)) {
            throw new RuntimeException('Characterization prediction mapping file is invalid JSON.');
        }

        return $this->mapping = $mapping;
    }
}
