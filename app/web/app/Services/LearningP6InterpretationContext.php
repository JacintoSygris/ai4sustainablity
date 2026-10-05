<?php

namespace App\Services;

use App\Models\EsrsTopic;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Private transient interpretation bytes and raw semantic catalog; no durable authority. */
final class LearningP6InterpretationContext
{
    private function __construct(
        private readonly string $path,
        private readonly string $raw,
        private readonly array $catalog,
    ) {}

    public static function requested(): bool
    {
        $flag = config('services.learning_p6_interpretation_context.enabled');
        return $flag !== null && $flag !== false;
    }

    public static function capture(): self
    {
        $context = self::snapshot();
        $context->validateMapping();
        return $context;
    }

    public static function guard(): void
    {
        if (config('services.learning_p6_interpretation_context.enabled') !== true
            || ! app()->environment('testing')
            || config('services.learning_p6_prepared_request.enabled') !== true) {
            throw new DomainException('learning_p6_interpretation.guard');
        }
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([DB::connection(), (new EsrsTopic)->getConnection()])) { throw new DomainException('learning_p6_interpretation.guard'); }
    }

    private static function snapshot(): self
    {
        self::guard();
        $path = config('services.characterization.prediction_mapping_path');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new DomainException('learning_p6_interpretation.mapping');
        }
        $raw = file_get_contents($path);
        if (! is_string($raw)) { throw new DomainException('learning_p6_interpretation.mapping'); }
        $model = new EsrsTopic;
        $fields = array_merge(['id'], $model->getFillable());
        $catalog = $model->newQuery()->select($fields)->orderBy('id')->get()
            ->map(fn ($row) => array_intersect_key($row->getRawOriginal(), array_flip($fields)))->all();
        self::guard();
        return new self($path, $raw, $catalog);
    }

    private function validateMapping(): void
    {
        $mapping = json_decode($this->raw, true);
        if (! is_array($mapping)) { throw new DomainException('learning_p6_interpretation.mapping'); }
        $modern = ($mapping['mapping_version'] ?? null) === 'new_format_732_v1';
        $rows = $mapping[$modern ? 'keys' : 'candidate_topics'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) { throw new DomainException('learning_p6_interpretation.mapping'); }
        foreach ($rows as $row) {
            if (! is_array($row)) { throw new DomainException('learning_p6_interpretation.mapping'); }
            if (($row[$modern ? 'status' : 'mapping_status'] ?? null) !== 'approved') { continue; }
            $ids = $modern ? ($row['ar16_topic_ids'] ?? null) : [$row['ar16_topic_id'] ?? null];
            if (! is_array($ids) || ! array_is_list($ids)) { throw new DomainException('learning_p6_interpretation.topic_id'); }
            foreach ($ids as $id) {
                self::exactId($id);
                if (! in_array((int) $id, array_column($this->catalog, 'id'), true)) {
                    throw new DomainException('learning_p6_interpretation.topic_id');
                }
            }
        }
    }

    private static function exactId(mixed $id): void
    {
        if (is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id) && (string) (int) $id === $id) { $id = (int) $id; }
        if (! is_int($id) || $id < 1 || $id > 9007199254740991) {
            throw new DomainException('learning_p6_interpretation.topic_id');
        }
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([$this->path, $this->raw, $this->catalog], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function mapper(): CharacterizationPredictionMapper
    {
        return CharacterizationPredictionMapper::fromCapturedRaw($this->raw);
    }

    public function assertCurrent(): void
    {
        if (self::snapshot()->digest() !== $this->digest()) {
            throw new DomainException('learning_p6_interpretation.changed');
        }
        self::guard();
    }

    public function candidateTopicIds(array $response): array
    {
        $ids = [];
        foreach ($response['candidate_topics'] ?? [] as $topic) {
            $id = $topic['ar16_topic_id'] ?? null;
            if (! is_int($id) || ! in_array($id, array_column($this->catalog, 'id'), true)) {
                throw new DomainException('learning_p6_interpretation.topic_id');
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }
}
