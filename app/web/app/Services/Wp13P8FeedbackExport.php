<?php

namespace App\Services;

use App\Http\Requests\CharacterizationRequest;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Support\CharacterizationOptions;
use App\Support\Wp13TrainingSourceFingerprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Offline, read-only export of explicitly supplied records; never discovers users.
 * No consent is inferred: use with real records requires separate authorization.
 * Invalid input aborts the entire batch, rather than silently dropping rows.
 */
final class Wp13P8FeedbackExport
{
    public function export(Collection $characterizations, array $topicIds): array
    {
        $topicIds = $this->ids($topicIds);
        if ($topicIds === []) {
            throw new InvalidArgumentException('Explicit topic columns are required.');
        }
        $this->knownIds($topicIds);
        $seen = [];
        foreach ($characterizations as $source) {
            if (! $source instanceof Characterization || ! $source->exists
                || ! is_int($source->id) || $source->id < 1 || isset($seen[$source->id])) {
                throw new InvalidArgumentException('Expected unique persisted Characterization models.');
            }
            $seen[$source->id] = true;
        }

        return $characterizations->sortBy('id')->map(function (Characterization $source) use ($topicIds): array {
            $confirmation = $this->confirmation($source);
            $topics = [];
            foreach ($topicIds as $id) {
                $answer = $confirmation['guided_answers'][$id] ?? [];
                $result = $answer['final_result'] ?? null;
                $known = ($answer['revisar'] ?? null) === false && in_array($result, ['material', 'no_material'], true);
                $topics[$id] = ['value' => $known ? (int) ($result === 'material') : null, 'mask' => (int) $known];
            }

            return [
                'characterization_id' => $source->id,
                'submission_generation' => $source->submission_generation,
                'p8_revision' => $confirmation['revision'],
                'p6_snapshot' => [
                    'topic_ids' => $this->ids($confirmation['p6_snapshot']['topic_ids']),
                    'captured_at' => $confirmation['p6_snapshot']['captured_at'],
                ],
                'p5_input_sha256' => $confirmation['training_source']['p5_input_sha256'],
                'p5_snapshot' => Wp13TrainingSourceFingerprint::snapshot($source),
                'topics' => $topics,
            ];
        })->values()->all();
    }

    private function confirmation(Characterization $source): array
    {
        $this->requireSubmitInputs($source);
        $confirmation = $source->form_data['materiality_confirmation'] ?? null;
        $seal = is_array($confirmation) ? ($confirmation['training_source'] ?? null) : null;
        if ($source->status !== Characterization::STATUS_COMPLETED
            || ! is_array($confirmation) || ! is_array($seal)
            || ($seal['schema_version'] ?? null) !== Wp13TrainingSourceFingerprint::SCHEMA_VERSION
            || ! is_int($source->submission_generation) || $source->submission_generation < 0
            || ($seal['submission_generation'] ?? null) !== $source->submission_generation
            || ! is_string($seal['p5_input_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $seal['p5_input_sha256'])
            || ! hash_equals(Wp13TrainingSourceFingerprint::hash($source), $seal['p5_input_sha256'])
            || ! is_int($confirmation['revision'] ?? null) || $confirmation['revision'] < 1
            || ! $this->timestamp($confirmation['confirmed_at'] ?? null)) {
            throw new InvalidArgumentException('Invalid or stale P5/P8 training provenance.');
        }

        $snapshot = $confirmation['p6_snapshot'] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['topic_ids'] ?? null)
            || ! $this->timestamp($snapshot['captured_at'] ?? null)
            || ! is_array($source->esrs_topic_ids)
            || ! is_array($confirmation['confirmed_topic_ids'] ?? null)) {
            throw new InvalidArgumentException('Missing P6 snapshot or P8 selected set.');
        }
        $saved = $this->ids($snapshot['topic_ids']);
        $current = $this->ids($source->esrs_topic_ids);
        $selectedIds = $this->ids($confirmation['confirmed_topic_ids']);
        if ($saved === [] || $saved !== $current) {
            throw new InvalidArgumentException('P6 proposal differs from its P8 snapshot.');
        }
        $answers = $confirmation['guided_answers'] ?? [];
        if (! is_array($answers) || (array_key_exists('guided_answers', $confirmation) && $confirmation['guided_answers'] === null)) {
            throw new InvalidArgumentException('Malformed guided decision map.');
        }
        foreach ($answers as $id => $answer) {
            if (! is_array($answer)) {
                throw new InvalidArgumentException('Malformed guided decision.');
            }
            $result = $answer['final_result'] ?? null;
            $selected = in_array($id, $selectedIds, true);
            if (($result === 'material' && ! $selected) || ($result === 'no_material' && $selected)) {
                throw new InvalidArgumentException('Guided decision conflicts with the P8 selected set.');
            }
        }
        $guidedIds = $this->ids(array_keys($answers));
        $this->knownIds(array_values(array_unique([...$saved, ...$current, ...$selectedIds, ...$guidedIds])));

        return $confirmation;
    }

    /** Eligibility belongs to offline export; the public P8 v1 seal stays unchanged. */
    private function requireSubmitInputs(Characterization $source): void
    {
        $input = ['nace_code' => $source->nace_code, 'form_data' => $source->form_data];
        $request = CharacterizationRequest::create('/offline', 'POST', ['step' => 'review', 'action' => 'submit']);
        $submitRules = $request->rules();
        $rules = [];
        $requiredInput = [];
        foreach (CharacterizationOptions::submitRequiredFields() as $field) {
            $rules[$field] = $submitRules[$field];
            if (Arr::has($input, $field)) {
                Arr::set($requiredInput, $field, Arr::get($input, $field));
            }
            if (isset($submitRules[$field.'.*'])) {
                $rules[$field.'.*'] = [...$submitRules[$field.'.*'], 'distinct:strict'];
                $value = Arr::get($input, $field);
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new InvalidArgumentException('Noncanonical required P5 list.');
                }
            }
        }
        // No request normalization, defaults, writes, or unwhitelisted text.
        // Error details can contain identity: expose only a fixed rejection.
        if (Validator::make($requiredInput, $rules)->fails()) {
            throw new InvalidArgumentException('Missing or invalid required P5 submission inputs.');
        }
    }

    /** Lists must contain canonical positive integer IDs, with no coercion. */
    private function ids(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Expected an ID list.');
        }
        $seen = [];
        foreach ($values as $id) {
            if (! is_int($id) || $id < 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('Noncanonical or duplicate topic ID.');
            }
            $seen[$id] = true;
        }
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** Only a bounded catalogue read; never a Characterization or user query. */
    private function knownIds(array $ids): void
    {
        if ($ids !== [] && EsrsTopic::whereIn('id', $ids)->count() !== count($ids)) {
            throw new InvalidArgumentException('Unknown topic ID.');
        }
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);

            return \DateTimeImmutable::getLastErrors() === false;
        } catch (\Exception) {
            return false;
        }
    }
}
