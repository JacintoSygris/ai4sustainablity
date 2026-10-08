<?php

namespace App\Services;

use App\Models\Characterization;
use Illuminate\Support\Arr;

final class EsrsDatapointResponseState
{
    public const SCHEMA_VERSION = 'v1';

    public function __construct(private readonly Ar16MatterDrMappingRepository $mappingRepository) {}

    public function projectLearningFeedbackForAccount(int $actor, array $expectedSourceHeaders, EsrsDatapointCorpusBuilder $builder): array
    {
        $connection = $this->learningFeedbackConnection();
        $pdo = $connection->getPdo();
        $parents = array_keys($expectedSourceHeaders); sort($parents, SORT_STRING);
        if ($actor < 1 || $parents !== ['p5', 'p6_base', 'p8', 'p9']) {
            throw new \DomainException('learning_p9.current_headers');
        }
        foreach ($expectedSourceHeaders as $header) {
            if (! is_array($header)) { throw new \DomainException('learning_p9.current_headers'); }
            $keys = array_keys($header); sort($keys, SORT_STRING);
            if ($keys !== ['characterization_id', 'digest', 'epoch', 'generation', 'revision']) {
                throw new \DomainException('learning_p9.current_headers');
            }
        }
        return $connection->transaction(function () use ($actor, $expectedSourceHeaders, $builder, $connection, $pdo): array {
            $assertFrame = null;
            $result = app(CharacterizationStateTransaction::class)->runForUser($actor,
            function (?Characterization $source) use ($actor, $expectedSourceHeaders, $builder, $connection, $pdo, &$assertFrame): array {
                $this->learningFeedbackConnection($connection, $pdo);
                if ($source === null) { throw new \DomainException('learning_p9.current_source_missing'); }
                $copy = new Characterization; $copy->setRawAttributes($source->getRawOriginal(), true);
                $id = $copy->getRawOriginal('id');
                if (! is_int($id) || $id < 1 || $copy->getRawOriginal('user_id') !== $actor
                    || $copy->getRawOriginal('status') !== Characterization::STATUS_COMPLETED) {
                    throw new \DomainException('learning_p9.current_identity');
                }
                $headers = [];
                foreach ($this->learningFeedbackClocks() as $name => $clock) {
                    $headers[$name] = $clock->current($id);
                    $actual = $headers[$name]; $expected = $expectedSourceHeaders[$name];
                    if ($actual === null) { throw new \DomainException('learning_p9.current_headers'); }
                    ksort($actual, SORT_STRING); ksort($expected, SORT_STRING);
                    if ($actual !== $expected) { throw new \DomainException('learning_p9.current_headers'); }
                }
                $raw = $copy->getRawOriginal();
                $lookup = function () use ($id, $raw): Characterization {
                    $fresh = Characterization::query()->whereKey($id)->first();
                    if ($fresh === null || $fresh->getRawOriginal() !== $raw) {
                        throw new \DomainException('learning_p9.current_source_drift');
                    }
                    $copy = new Characterization; $copy->setRawAttributes($fresh->getRawOriginal(), true);
                    return $copy;
                };
                $clocks = $this->learningFeedbackClocks(); $witnesses = [];
                foreach ($clocks as $name => $clock) {
                    if ($name !== 'p5') {
                        $witnesses[$name] = $clock->finalizationWitness($lookup);
                        if ($witnesses[$name]['header'] !== $headers[$name]) {
                            throw new \DomainException('learning_p9.current_source_drift');
                        }
                    }
                }
                $corpus = $builder->build($lookup());
                $state = $this->state($lookup(), $corpus);
                $assertFrame = function () use ($connection, $pdo, $lookup, $builder, $corpus, $clocks, $headers, $witnesses, $id): void {
                    $this->learningFeedbackConnection($connection, $pdo);
                    if ($builder->build($lookup()) !== $corpus) { throw new \DomainException('learning_p9.current_authority_drift'); }
                    foreach ($clocks as $name => $clock) {
                        if ($clock->current($id) !== $headers[$name]
                            || ($name !== 'p5' && $clock->finalizationWitness($lookup) !== $witnesses[$name])) {
                            throw new \DomainException('learning_p9.current_source_drift');
                        }
                    }
                    $lookup();
                    $this->learningFeedbackConnection($connection, $pdo);
                };
                $assertFrame();
                return ['source_headers' => $headers, 'learning_authority_digest' => $state['learning_authority_digest'],
                    'learning_feedback' => $state['learning_feedback']];
            });
            // All Common owners/finalizers have completed inside this same-PDO outer frame.
            $assertFrame();
            return $result;
        });
    }

    private function learningFeedbackClocks(): array
    {
        return ['p5' => new LearningP5SourceRevisionClock, 'p6_base' => new LearningP6BaseSourceRevisionClock,
            'p8' => new LearningP8SourceRevisionClock, 'p9' => new LearningP9SourceRevisionClock];
    }

    /** All declarations precede PDO resolution. Shared aliases are admitted by PDO identity. */
    private function learningFeedbackConnection(?\Illuminate\Database\Connection $expected = null, ?\PDO $expectedPdo = null): \Illuminate\Database\Connection
    {
        if (! LearningP5SourceRevisionClock::enabled() || ! LearningP6BaseSourceRevisionClock::enabled()
            || ! LearningP8SourceRevisionClock::enabled() || ! LearningP9SourceRevisionClock::enabled()
            || ! app()->environment('testing')) { throw new \DomainException('learning_p9.current_guard'); }
        $default = \Illuminate\Support\Facades\DB::connection();
        $models = array_map(fn ($class) => (new $class)->getConnection(),
            [\App\Models\User::class, Characterization::class, \App\Models\EsrsTopic::class]);
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$default, ...$models])) { throw new \DomainException('learning_p9.disposable_connection_required'); }
        if ($expected !== null && $default !== $expected) { throw new \DomainException('learning_p9.connection_mismatch'); }
        $pdo = $default->getPdo();
        foreach ($models as $connection) {
            if ($connection->getPdo() !== $pdo) { throw new \DomainException('learning_p9.connection_mismatch'); }
        }
        if ($expectedPdo !== null && $pdo !== $expectedPdo) { throw new \DomainException('learning_p9.connection_mismatch'); }
        return $default;
    }

    public function learningAuthorityDigest(array $corpus): string
    {
        // The builder captured the accepted normalized mapping exactly once.
        // Never reread a mutable file or recursively include the published token.
        unset($corpus['learning_authority_digest']);
        return hash('sha256', json_encode(['namespace' => 'p9-workspace-v1', 'corpus' => $corpus], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function currentFeedback(array $stored, array $corpus): array
    {
        $feedback = $stored['learning_feedback'] ?? null;
        if (is_array($feedback) && ($feedback['authority_digest'] ?? null) === $this->learningAuthorityDigest($corpus)) {
            try {
                return $this->validateLearningFeedback($feedback, $corpus);
            } catch (\Illuminate\Validation\ValidationException) {
                // Preserve malformed historical evidence in storage, never current labels.
            }
        }
        // Stale evidence remains stored, but is never projected as current labels.
        return ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => $this->learningAuthorityDigest($corpus), 'reviewed_datapoint_ids' => [], 'decisions' => []];
    }

    public function validateLearningFeedback(mixed $feedback, array $corpus): array
    {
        $reject = static function (): never {
            throw \Illuminate\Validation\ValidationException::withMessages(['learning_feedback' => __('Explicit binary decisions must exactly cover reviewed current catalog ids and the current authority.')]);
        };
        $exact = static function (array $value, array $keys): bool {
            $actual = array_keys($value); sort($actual); sort($keys); return $actual === $keys;
        };
        if (!is_array($feedback) || !$exact($feedback, ['schema_version','authority_digest','reviewed_datapoint_ids','decisions'])
            || $feedback['schema_version'] !== 'datapoint-feedback-v1'
            || $feedback['authority_digest'] !== $this->learningAuthorityDigest($corpus)
            || !is_array($feedback['reviewed_datapoint_ids']) || !array_is_list($feedback['reviewed_datapoint_ids'])
            || !is_array($feedback['decisions']) || !array_is_list($feedback['decisions'])) $reject();
        $ids = $feedback['reviewed_datapoint_ids'];
        foreach ($ids as $id) if (!is_string($id) || !in_array($id, $this->corpusDatapointIds($corpus), true)) $reject();
        if (count($ids) !== count(array_unique($ids))) $reject();
        $seen = [];
        foreach ($feedback['decisions'] as $decision) {
            if (!is_array($decision) || !$exact($decision, ['datapoint_id','relevant','selected_to_answer','reason_codes','note'])
                || !is_string($decision['datapoint_id']) || !in_array($decision['datapoint_id'], $ids, true)
                || in_array($decision['datapoint_id'], $seen, true)
                || !is_bool($decision['relevant']) || !is_bool($decision['selected_to_answer'])
                || !is_array($decision['reason_codes']) || !array_is_list($decision['reason_codes'])
                || !(is_null($decision['note']) || (is_string($decision['note']) && mb_strlen($decision['note']) <= 2000))) $reject();
            foreach ($decision['reason_codes'] as $reason) if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 100) $reject();
            if (count($decision['reason_codes']) !== count(array_unique($decision['reason_codes']))) $reject();
            $seen[] = $decision['datapoint_id'];
        }
        if (count($seen) !== count($ids)) $reject();
        return $feedback;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    public function state(Characterization $characterization, array $corpus): array
    {
        $stored = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses', []);
        $stored = is_array($stored) ? $stored : [];
        $responses = Arr::get($stored, 'responses', []);
        $responses = is_array($responses) ? $responses : [];
        $responses = array_filter(
            $responses,
            static fn (mixed $response): bool => is_array($response)
                && in_array($response['status'] ?? null, ['draft', 'completed', 'not_applicable'], true),
        );
        $allowedDatapointIds = $this->corpusDatapointIds($corpus);
        $allowedDatapointLookup = array_flip($allowedDatapointIds);
        $orphanedResponses = array_diff_key($responses, $allowedDatapointLookup);
        $currentResponses = array_intersect_key($responses, $allowedDatapointLookup);

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => Arr::get($stored, 'schema_version', self::SCHEMA_VERSION),
            'revision' => $this->revision($characterization),
            'updated_at' => Arr::get($stored, 'updated_at'),
            'responses' => $currentResponses,
            'source_digest' => hash('sha256', json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'learning_authority_digest' => $this->learningAuthorityDigest($corpus),
            'learning_feedback' => $this->currentFeedback($stored, $corpus),
            'orphaned' => [
                'count' => count($orphanedResponses),
                'responses' => $orphanedResponses,
            ],
            'summary' => $this->summary($currentResponses, $this->requiredDatapointIds($corpus)),
        ];
    }

    /**
     * Flat summary consumed by P10. It is derived from the same required-id
     * policy as the P9 response endpoint.
     *
     * @param  array<string, mixed>  $corpus
     * @return array<string, int|float|string>
     */
    public function reportSummary(Characterization $characterization, array $corpus): array
    {
        $state = $this->state($characterization, $corpus);
        $summary = $state['summary'];

        return [
            'response_count' => $summary['response_count'],
            'completed_count' => $summary['completed_count'],
            'not_applicable_count' => $summary['not_applicable_count'],
            'decided_count' => $summary['decided_count'],
            'effective_required_datapoint_count' => $summary['applicable_datapoint_count'],
            'completion_ratio' => $summary['completion_ratio'],
            'completion_status' => $summary['completion_status'],
            'orphaned_response_count' => $state['orphaned']['count'],
        ];
    }

    public function revision(Characterization $characterization): int
    {
        $revision = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses.revision', 0);

        return is_numeric($revision) ? max(0, (int) $revision) : 0;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    public function requiredDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
            ->filter(fn (array $datapoint): bool => (bool) Arr::get($datapoint, 'selection.default_selected', true))
            ->pluck('id')
            ->filter()
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return list<string>
     */
    public function corpusDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    public function normalizeResponses(array $responses): array
    {
        $updatedAt = now()->toJSON();

        return collect($responses)
            ->mapWithKeys(function (array $response) use ($updatedAt): array {
                $datapointId = trim((string) $response['datapoint_id']);
                $normalized = [
                    'datapoint_id' => $datapointId,
                    'status' => $response['status'],
                    'updated_at' => $updatedAt,
                ];

                foreach (['value', 'evidence_reference', 'note', 'triage'] as $field) {
                    if (array_key_exists($field, $response) && filled($response[$field])) {
                        $normalized[$field] = $response[$field];
                    }
                }

                return [$datapointId => $normalized];
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     * @param  list<string>  $requiredDatapointIds
     * @return array<string, int|float|string>
     */
    private function summary(array $responses, array $requiredDatapointIds): array
    {
        $statusCounts = collect($responses)->countBy('status');
        $completedCount = (int) $statusCounts->get('completed', 0);
        $notApplicableCount = (int) $statusCounts->get('not_applicable', 0);
        $responseCount = count($responses);
        $requiredResponses = array_intersect_key($responses, array_flip($requiredDatapointIds));
        $requiredStatusCounts = collect($requiredResponses)->countBy('status');
        $decidedRequiredCount = (int) $requiredStatusCounts->get('completed', 0)
            + (int) $requiredStatusCounts->get('not_applicable', 0);
        $requiredDatapointCount = count($requiredDatapointIds);

        return [
            'applicable_datapoint_count' => $requiredDatapointCount,
            'response_count' => $responseCount,
            'completed_count' => $completedCount,
            'draft_count' => (int) $statusCounts->get('draft', 0),
            'not_applicable_count' => $notApplicableCount,
            'decided_count' => $completedCount + $notApplicableCount,
            'decided_required_count' => $decidedRequiredCount,
            'optional_response_count' => $responseCount - count($requiredResponses),
            'completion_ratio' => $requiredDatapointCount > 0
                ? round($decidedRequiredCount / $requiredDatapointCount, 4)
                : 1.0,
            'completion_status' => match (true) {
                $responseCount === 0 => 'not_started',
                $requiredDatapointCount === 0 || $decidedRequiredCount >= $requiredDatapointCount => 'completed',
                default => 'in_progress',
            },
        ];
    }
}
