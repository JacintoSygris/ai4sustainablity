<?php

namespace App\Services;

use App\Models\Characterization;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Private synthetic P9 history observer; no labels, rights or applicability authority. */
final class LearningP9SourceRevisionClock
{
    private const MAX = 9007199254740991;
    private static array $scopes = [];
    public static function enabled(): bool
    {
        return config('services.learning_p9_source_clock.enabled') === true && app()->environment('testing');
    }

    private function guard(): void
    {
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([DB::connection()])) {
            throw new DomainException('learning_p9_clock.disposable_transaction_required');
        }
    }

    public function current(int $characterizationId): ?array
    {
        if (! self::enabled()) { return null; }
        $this->guard();
        $header = $this->header($characterizationId);
        if ($header === null) { return null; }
        $row = Characterization::query()->find($characterizationId);
        if ($row === null) { throw new DomainException('learning_p9_clock.orphan'); }
        $this->reconcile($this->state($row), $header);
        return $header;
    }

    public function observe(int $actor, Closure $lookup, Closure $operation): mixed
    {
        if (! self::enabled()) { return $operation(); }
        $this->guard();
        if (DB::connection()->transactionLevel() < 1) {
            throw new DomainException('learning_p9_clock.transaction_required');
        }
        $key = spl_object_id(DB::connection()).':'.$actor;
        if (isset(self::$scopes[$key])) { return $operation(); }
        self::$scopes[$key] = true;
        try {
            $beforeRow = $lookup();
            $before = $beforeRow === null ? null : $this->state($beforeRow);
            $header = $before === null ? null : $this->header($before['id']);
            if ($before !== null && $before['user_id'] !== $actor) { throw new DomainException('learning_p9_clock.actor_mismatch'); }
            if ($header !== null) { $this->reconcile($before, $header); }
            $result = $operation();
            $afterRow = $lookup();
            if ($afterRow === null) {
                if ($before !== null && Characterization::query()->whereKey($before['id'])->orWhere('user_id', $actor)->exists()) {
                    throw new DomainException('learning_p9_clock.identity_or_generation_regression');
                }
                if ($before !== null && $this->header($before['id']) !== null) { throw new DomainException('learning_p9_clock.deletion_not_cascaded'); }
                return $result;
            }
            $after = $this->state($afterRow);
            if ($after['user_id'] !== $actor) { throw new DomainException('learning_p9_clock.actor_mismatch'); }
            if ($before !== null && ($after['id'] !== $before['id'] || $after['generation'] < $before['generation'])) {
                throw new DomainException('learning_p9_clock.identity_or_generation_regression');
            }
            if ($this->header($after['id']) !== $header) { throw new DomainException('learning_p9_clock.header_changed'); }
            if ($this->sameState($after, $before)) { return $result; }
            if ($header !== null && $header['revision'] === self::MAX) { throw new DomainException('learning_p9_clock.revision_exhausted'); }
            $next = ['characterization_id' => $after['id'], 'generation' => $after['generation'],
                'revision' => $header === null ? 1 : $header['revision'] + 1,
                'epoch' => $header['epoch'] ?? bin2hex(random_bytes(32))];
            $next['digest'] = $this->digest($after, $next['epoch']);
            if ($header === null) {
                DB::table('learning_p9_source_revisions')->insert($next);
            } else {
                if (DB::table('learning_p9_source_revisions')->where($header)->update($next) !== 1) {
                    throw new DomainException('learning_p9_clock.conflict');
                }
            }
            $finalHeader = $this->header($after['id']);
            $finalRow = $lookup();
            if ($finalRow === null) { throw new DomainException('learning_p9_clock.readback_mismatch'); }
            $final = $this->state($finalRow);
            if ($final['user_id'] !== $actor) { throw new DomainException('learning_p9_clock.actor_mismatch'); }
            if ($finalHeader !== $next || ! $this->sameState($final, $after)) { throw new DomainException('learning_p9_clock.readback_mismatch'); }
            return $result;
        } finally { unset(self::$scopes[$key]); }
    }

    public function hasActiveScope(int $actor): bool
    {
        return isset(self::$scopes[spl_object_id(DB::connection()).':'.$actor]);
    }

    /** Exact nullable private witness, checked after the outer owners finish. */
    public function finalizationWitness(Closure $lookup): array
    {
        if (! self::enabled()) { throw new DomainException('learning_p9_clock.mode_changed'); }
        $this->guard();
        if (DB::connection()->transactionLevel() < 1) { throw new DomainException('learning_p9_clock.transaction_required'); }
        $row = $lookup(); $state = $row === null ? null : $this->state($row);
        return ['checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512)),
            'header' => $state === null ? null : $this->header($state['id'])];
    }

    private function header(int $id): ?array
    {
        $raw = DB::table('learning_p9_source_revisions')->where('characterization_id', $id)->first();
        if ($raw === null) { return null; }
        $h = (array) $raw;
        if (array_keys($h) !== ['characterization_id','generation','revision','epoch','digest']) { throw new DomainException('learning_p9_clock.invalid_header'); }
        foreach (['characterization_id','generation','revision'] as $field) { $h[$field] = $this->integer($h[$field], $field !== 'generation'); }
        foreach (['epoch','digest'] as $field) {
            if (! is_string($h[$field]) || preg_match('/\A[a-f0-9]{64}\z/D', $h[$field]) !== 1) { throw new DomainException('learning_p9_clock.invalid_header'); }
        }
        return $h;
    }

    private function integer(mixed $raw, bool $positive = false): int
    {
        if (is_string($raw) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) === 1 && (string) (int) $raw === $raw) { $raw = (int) $raw; }
        if (! is_int($raw) || $raw < ($positive ? 1 : 0) || $raw > self::MAX) { throw new DomainException('learning_p9_clock.invalid_integer'); }
        return $raw;
    }

    private function state(Characterization $row): array
    {
        $raw = $row->getRawOriginal();
        $form = $this->json($raw['form_data'] ?? null);
        return ['id' => $this->integer($raw['id'] ?? null, true), 'user_id' => $this->integer($raw['user_id'] ?? null, true),
            'generation' => $this->integer($raw['submission_generation'] ?? null), 'source' => [
                'form_data' => ['present' => array_key_exists('form_data', $raw),
                    'sql_null' => ($raw['form_data'] ?? null) === null,
                    'container' => $form instanceof stdClass ? 'object' : (is_array($form) ? 'array' : 'null')],
                'esrs_datapoint_responses' => $this->member($form),
            ]];
    }

    private function json(mixed $raw): mixed
    {
        if ($raw === null) { return null; }
        if (! is_string($raw)) { throw new DomainException('learning_p9_clock.invalid_json'); }
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            if ($decoded !== null && $decoded !== [] && ! $decoded instanceof stdClass) { throw new DomainException('learning_p9_clock.invalid_root'); }
            $this->canonical($decoded); // Strict finite admission, including unowned root members.
            $protected = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (serialize($this->member($decoded)) !== serialize($this->member($protected))) {
                throw new DomainException('learning_p9_clock.unsupported_integer');
            }
            return $decoded; // Protective decode is admission evidence, never digest truth.
        } catch (\JsonException $error) { throw new DomainException('learning_p9_clock.invalid_json', 0, $error); }
    }

    private function member(mixed $form): array
    {
        $present = $form instanceof stdClass && property_exists($form, 'esrs_datapoint_responses');
        return ['present' => $present, 'value' => $present ? $this->canonical($form->esrs_datapoint_responses) : null];
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value); ksort($fields, SORT_STRING);
            $result = new stdClass;
            foreach ($fields as $key => $child) { $result->$key = $this->canonical($child); }
            return $result;
        }
        if (is_array($value)) { return array_map(fn ($child) => $this->canonical($child), $value); }
        if (is_float($value) && ! is_finite($value)) { throw new DomainException('learning_p9_clock.nonfinite'); }
        return $value;
    }

    private function sameState(?array $left, ?array $right): bool
    {
        return json_encode($left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512)
            === json_encode($right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 512);
    }

    private function digest(array $state, string $epoch): string
    {
        return hash('sha256', json_encode(['namespace' => 'learning-p9-source-state',
            'schema' => 'learning-p9-source-clock-v1', 'epoch' => $epoch,
            'characterization_id' => $state['id'], 'user_id' => $state['user_id'],
            'generation' => $state['generation'], 'source' => $state['source']],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 512));
    }

    private function reconcile(array $state, array $header): void
    {
        if ($state['id'] !== $header['characterization_id'] || $state['generation'] !== $header['generation']
            || $this->digest($state, $header['epoch']) !== $header['digest']) {
            throw new DomainException('learning_p9_clock.source_drift');
        }
    }
}
