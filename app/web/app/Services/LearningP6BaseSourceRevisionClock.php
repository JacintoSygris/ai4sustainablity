<?php

namespace App\Services;

use App\Models\Characterization;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Private synthetic source-state clock; neither rights nor reconstructed history. */
final class LearningP6BaseSourceRevisionClock
{
    private const MAX = 9007199254740991;
    private static array $scopes = [];

    public static function enabled(): bool
    {
        return config('services.learning_p6_base_source_clock.enabled') === true && app()->environment('testing');
    }

    private function guard(): void
    {
        $connection = DB::connection();
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$connection])) {
            throw new DomainException('learning_p6_base_clock.disposable_transaction_required');
        }
    }

    /** Only metadata leaves this service. A passive read never seeds or repairs. */
    public function current(int $characterizationId): ?array
    {
        if (! self::enabled()) { return null; }
        $this->guard();
        $row = Characterization::query()->find($characterizationId);
        $header = $this->header($characterizationId);
        if ($header === null) { return null; }
        if ($row === null) { throw new DomainException('learning_p6_base_clock.orphan'); }
        $this->reconcile($this->state($row), $header);
        return $header;
    }

    /** Coalesce same-connection/actor nesting; outer persisted state owns the event. */
    public function observe(int $actor, Closure $lookup, Closure $operation): mixed
    {
        if (! self::enabled()) { return $operation(); }
        $this->guard();
        if (DB::connection()->transactionLevel() < 1) {
            throw new DomainException('learning_p6_base_clock.transaction_required');
        }
        $key = spl_object_id(DB::connection()).':'.$actor;
        if (isset(self::$scopes[$key])) { return $operation(); }
        self::$scopes[$key] = true;
        try {
            $beforeRow = $lookup();
            $before = $beforeRow === null ? null : $this->state($beforeRow);
            $beforeActor = $beforeRow === null ? null : $this->integer($beforeRow->getRawOriginal('user_id'), true);
            $header = $before === null ? null : $this->header($before['id']);
            if ($beforeActor !== null && $beforeActor !== $actor) { throw new DomainException('learning_p6_base_clock.actor_mismatch'); }
            if ($header !== null) { $this->reconcile($before, $header); }
            $result = $operation();
            $afterRow = $lookup();
            if ($afterRow === null) {
                if ($before !== null && Characterization::query()->whereKey($before['id'])
                    ->orWhere('user_id', $beforeActor)->exists()) {
                    throw new DomainException('learning_p6_base_clock.identity_or_generation_regression');
                }
                if ($before !== null && $this->header($before['id']) !== null) {
                    throw new DomainException('learning_p6_base_clock.deletion_not_cascaded');
                }
                return $result;
            }
            $after = $this->state($afterRow);
            if ($this->integer($afterRow->getRawOriginal('user_id'), true) !== $actor) {
                throw new DomainException('learning_p6_base_clock.actor_mismatch');
            }
            if ($before !== null && ($after['id'] !== $before['id'] || $after['generation'] < $before['generation'])) {
                throw new DomainException('learning_p6_base_clock.identity_or_generation_regression');
            }
            // Detect late header drift even when the callback made no BASE change.
            if ($this->header($after['id']) !== $header) {
                throw new DomainException('learning_p6_base_clock.header_changed');
            }
            if ($this->sameState($after, $before)) { return $result; }
            if ($header !== null && $header['revision'] === self::MAX) {
                throw new DomainException('learning_p6_base_clock.revision_exhausted');
            }
            $next = [
                'characterization_id' => $after['id'],
                'generation' => $after['generation'],
                'revision' => $header === null ? 1 : $header['revision'] + 1,
                'epoch' => $header['epoch'] ?? bin2hex(random_bytes(32)),
            ];
            $next['digest'] = $this->digest($after, $next['epoch']);
            if ($header === null) {
                DB::table('learning_p6_base_source_revisions')->insert($next);
            } else {
                $changed = DB::table('learning_p6_base_source_revisions')->where($header)->update($next);
                if ($changed !== 1) { throw new DomainException('learning_p6_base_clock.conflict'); }
            }
            $finalHeader = $this->header($after['id']);
            $finalRow = $lookup();
            if ($finalRow === null) { throw new DomainException('learning_p6_base_clock.readback_mismatch'); }
            if ($this->integer($finalRow->getRawOriginal('user_id'), true) !== $actor) {
                throw new DomainException('learning_p6_base_clock.actor_mismatch');
            }
            if ($finalHeader !== $next || ! $this->sameState($this->state($finalRow), $after)) {
                throw new DomainException('learning_p6_base_clock.readback_mismatch');
            }
            return $result;
        } finally {
            unset(self::$scopes[$key]);
        }
    }

    /** Same connection/actor ownership; inspection performs no database reads. */
    public function hasActiveScope(int $actor): bool
    {
        return isset(self::$scopes[spl_object_id(DB::connection()).':'.$actor]);
    }

    /** Internal checksum only, with exact nullable metadata; never a source tuple. */
    public function finalizationWitness(Closure $lookup): array
    {
        if (! self::enabled()) { throw new DomainException('learning_p6_base_clock.mode_changed'); }
        $this->guard();
        if (DB::connection()->transactionLevel() < 1) {
            throw new DomainException('learning_p6_base_clock.transaction_required');
        }
        $row = $lookup();
        $state = $row === null ? null : $this->state($row);
        return [
            'checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512)),
            'header' => $state === null ? null : $this->header($state['id']),
        ];
    }

    private function sameState(?array $left, ?array $right): bool
    {
        return json_encode($left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512)
            === json_encode($right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512);
    }

    private function integer(mixed $raw, bool $positive = false): int
    {
        if (is_string($raw) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) === 1
            && (string) (int) $raw === $raw) {
            $raw = (int) $raw;
        }
        if (! is_int($raw) || $raw < ($positive ? 1 : 0) || $raw > self::MAX) {
            throw new DomainException('learning_p6_base_clock.invalid_integer');
        }
        return $raw;
    }

    private function header(int $id): ?array
    {
        $raw = DB::table('learning_p6_base_source_revisions')->where('characterization_id', $id)->first();
        if ($raw === null) { return null; }
        $h = (array) $raw;
        if (array_keys($h) !== ['characterization_id', 'generation', 'revision', 'epoch', 'digest']) {
            throw new DomainException('learning_p6_base_clock.invalid_header');
        }
        foreach (['characterization_id', 'generation', 'revision'] as $field) {
            $h[$field] = $this->integer($h[$field], $field !== 'generation');
        }
        foreach (['epoch', 'digest'] as $field) {
            if (! is_string($h[$field]) || preg_match('/\A[a-f0-9]{64}\z/D', $h[$field]) !== 1) {
                throw new DomainException('learning_p6_base_clock.invalid_header');
            }
        }
        return $h;
    }

    private function state(Characterization $row): array
    {
        $raw = $row->getRawOriginal();
        $generation = $this->integer($raw['submission_generation'] ?? null);
        $form = $this->json($raw['form_data'] ?? null);
        $this->map($form);
        $result = $this->json($raw['result_data'] ?? null);
        $this->map($result);
        $ids = $this->json($raw['esrs_topic_ids'] ?? null);
        $this->list($ids);
        $focus = $this->member($form, 'esg_focus');
        $this->map($focus['value']);
        $topics = $this->member($focus['value'], 'topic_ids');
        $this->list($topics['value']);
        $review = $this->member($form, 'materiality_proposal_review');
        $this->map($review['value']);
        // Protective decoding is only a witness for this owner's projection.
        // Shape/parse/nonfinite checks above keep their existing priority.
        $protected = [];
        foreach (['result_data', 'esrs_topic_ids'] as $field) {
            $protected[] = ($raw[$field] ?? null) === null ? null
                : json_decode($raw[$field], false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        $protectedForm = ($raw['form_data'] ?? null) === null ? null
            : json_decode($raw['form_data'], false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $protectedFocus = $this->member($protectedForm, 'esg_focus');
        $protected[] = $this->member($protectedFocus['value'], 'topic_ids');
        $protected[] = $this->member($protectedForm, 'materiality_proposal_review');
        if (serialize([$result, $ids, $topics, $review]) !== serialize($this->canonical($protected))) {
            throw new DomainException('learning_p6_base_clock.unsupported_integer');
        }
        $source = [
            'result_data' => ['present' => array_key_exists('result_data', $raw), 'sql_null' => ($raw['result_data'] ?? null) === null, 'value' => $result],
            'esrs_topic_ids' => ['present' => array_key_exists('esrs_topic_ids', $raw), 'sql_null' => ($raw['esrs_topic_ids'] ?? null) === null, 'value' => $ids],
            'form_data' => ['present' => array_key_exists('form_data', $raw), 'sql_null' => ($raw['form_data'] ?? null) === null, 'container' => $form instanceof stdClass ? 'object' : ($form === [] ? 'array' : 'null')],
            'esg_focus' => ['present' => $focus['present'], 'container' => $focus['value'] instanceof stdClass ? 'object' : ($focus['value'] === [] ? 'array' : 'null')],
            'topic_ids' => $topics,
            'materiality_proposal_review' => $review,
        ];
        return [
            'id' => $this->integer($raw['id'] ?? null, true),
            'generation' => $generation,
            'source' => $source,
        ];
    }

    private function json(mixed $raw): mixed
    {
        if ($raw === null) { return null; }
        if (! is_string($raw)) { throw new DomainException('learning_p6_base_clock.invalid_json'); }
        try {
            return $this->canonical(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw new DomainException('learning_p6_base_clock.invalid_json', 0, $error);
        }
    }

    private function map(mixed $value): void
    {
        if ($value !== null && $value !== [] && ! $value instanceof stdClass) {
            throw new DomainException('learning_p6_base_clock.invalid_map');
        }
    }

    private function list(mixed $value): void
    {
        if ($value !== null && ! is_array($value)) {
            throw new DomainException('learning_p6_base_clock.invalid_list');
        }
    }

    private function member(mixed $map, string $key): array
    {
        $present = $map instanceof stdClass && property_exists($map, $key);
        return ['present' => $present, 'value' => $present ? $map->$key : null];
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $result = new stdClass;
            foreach ($fields as $key => $child) { $result->$key = $this->canonical($child); }
            return $result;
        }
        if (is_array($value)) { return array_map(fn ($child) => $this->canonical($child), $value); }
        if (is_float($value) && ! is_finite($value)) {
            throw new DomainException('learning_p6_base_clock.nonfinite');
        }
        return $value;
    }

    private function digest(array $state, string $epoch): string
    {
        return hash('sha256', json_encode([
            'namespace' => 'learning-p6-base-source-state',
            'schema' => 'learning-p6-base-source-clock-v1',
            'epoch' => $epoch, 'characterization_id' => $state['id'],
            'generation' => $state['generation'], 'source' => $state['source'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 512));
    }

    private function reconcile(array $state, array $header): void
    {
        if ($state['id'] !== $header['characterization_id'] || $state['generation'] !== $header['generation']
            || $this->digest($state, $header['epoch']) !== $header['digest']) {
            throw new DomainException('learning_p6_base_clock.source_drift');
        }
    }
}
