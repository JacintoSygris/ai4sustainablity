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
        if ($this->usesNewFormatInventory()) {
            return $this->newFormatCandidateTopics($rawPrediction);
        }

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
        if ($this->usesNewFormatInventory()) {
            return $this->newFormatReviewRequiredKeys($rawPrediction);
        }

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

    /**
     * @return array<string, mixed>
     */
    public function mappingMetadata(): array
    {
        $path = config('services.characterization.prediction_mapping_path');
        $mapping = $this->mapping();

        if ($this->usesNewFormatInventory()) {
            $approvedKeys = collect(Arr::get($mapping, 'keys', []))
                ->filter(fn (array $row) => ($row['status'] ?? null) === 'approved')
                ->filter(fn (array $row) => is_array($row['ar16_topic_ids'] ?? null) && $row['ar16_topic_ids'] !== [])
                ->pluck('python_esrs_key')
                ->filter(fn ($key) => is_string($key) && filled($key))
                ->unique()
                ->values();

            return [
                'mapping_version' => Arr::get($mapping, 'mapping_version'),
                'mapping_status' => Arr::get($mapping, 'status'),
                'mapping_key_count' => $approvedKeys->count(),
                'mapping_model_key_count' => Arr::get($mapping, 'model_key_count'),
                'mapping_sha256' => is_string($path) && File::exists($path)
                    ? hash_file('sha256', $path)
                    : null,
            ];
        }

        $mappedKeys = collect(Arr::get($mapping, 'candidate_topics', []))
            ->filter(fn (array $topic) => ($topic['mapping_status'] ?? null) === 'approved')
            ->flatMap(fn (array $topic) => $topic['python_esrs_keys'] ?? [])
            ->unique()
            ->values();

        return [
            'mapping_version' => Arr::get($mapping, 'version'),
            'mapping_status' => Arr::get($mapping, 'status'),
            'mapping_key_count' => $mappedKeys->count(),
            'mapping_sha256' => is_string($path) && File::exists($path)
                ? hash_file('sha256', $path)
                : null,
        ];
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

    private function usesNewFormatInventory(): bool
    {
        return is_array(Arr::get($this->mapping(), 'keys'))
            && Arr::get($this->mapping(), 'mapping_version') === 'new_format_732_v1';
    }

    /**
     * @param  array<string, int|bool>  $rawPrediction
     * @return array<int, array<string, mixed>>
     */
    private function newFormatCandidateTopics(array $rawPrediction): array
    {
        $suggestedKeys = collect($rawPrediction)
            ->filter(fn ($value) => (int) $value === 1)
            ->keys()
            ->flip();

        return collect(Arr::get($this->mapping(), 'keys', []))
            ->filter(fn (array $row) => ($row['status'] ?? null) === 'approved')
            ->filter(fn (array $row) => $suggestedKeys->has((string) ($row['python_esrs_key'] ?? '')))
            ->flatMap(function (array $row) {
                $topicIds = $row['ar16_topic_ids'] ?? [];
                if (! is_array($topicIds) || $topicIds === []) {
                    return [];
                }

                return collect($topicIds)
                    ->filter(fn ($topicId) => is_int($topicId) || ctype_digit((string) $topicId))
                    ->map(fn ($topicId) => [
                        'ar16_topic_id' => (int) $topicId,
                        'web_esrs' => $row['web_esrs'] ?? $this->esrsCodeFromPredictionKey((string) $row['python_esrs_key']),
                        'web_label_en' => $row['web_label_en'] ?? (string) $row['python_esrs_key'],
                        'python_esrs_keys' => [(string) $row['python_esrs_key']],
                        'score_source' => 'python_predict',
                        'suggested' => true,
                    ]);
            })
            ->sortBy('ar16_topic_id')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|bool>  $rawPrediction
     * @return array<int, string>
     */
    private function newFormatReviewRequiredKeys(array $rawPrediction): array
    {
        $statusByKey = collect(Arr::get($this->mapping(), 'keys', []))
            ->filter(fn (array $row) => is_string($row['python_esrs_key'] ?? null))
            ->mapWithKeys(fn (array $row) => [
                $row['python_esrs_key'] => [
                    'status' => $row['status'] ?? null,
                    'ar16_topic_ids' => $row['ar16_topic_ids'] ?? [],
                ],
            ]);

        return collect($rawPrediction)
            ->filter(fn ($value) => (int) $value === 1)
            ->keys()
            ->filter(function (string $key) use ($statusByKey) {
                $status = data_get($statusByKey->get($key), 'status');
                $topicIds = data_get($statusByKey->get($key), 'ar16_topic_ids', []);

                if ($status === 'approved' && is_array($topicIds) && $topicIds !== []) {
                    return false;
                }

                if ($status === 'approved') {
                    return true;
                }

                return in_array($status, ['review_only', 'aggregate_only'], true) || $status === null;
            })
            ->values()
            ->all();
    }

    private function esrsCodeFromPredictionKey(string $key): string
    {
        if (preg_match('/^esrs_([egs]\d)_/i', $key, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'ESRS';
    }
}
