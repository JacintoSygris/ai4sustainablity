<?php

namespace App\Services;

use App\Models\Characterization;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Private synthetic source-state clock; neither rights nor reconstructed history. */
final class LearningP5SourceRevisionClock
{
    private const MAX = 9007199254740991;
    private static array $scopes = [];

    public static function enabled(): bool
    {
        return config('services.learning_source_clock.enabled') === true && app()->environment('testing');
    }

    private function guard(): void
    {
        $connection = DB::connection();
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$connection])) {
            throw new DomainException('learning_p5_clock.disposable_transaction_required');
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
        if ($row === null) { throw new DomainException('learning_p5_clock.orphan'); }
        $this->reconcile($this->state($row), $header);
        return $header;
    }

    /** Coalesce same-connection/actor nesting; outer persisted state owns the event. */
    public function observe(int $actor, Closure $lookup, Closure $operation): mixed
    {
        if (! self::enabled()) { return $operation(); }
        $this->guard();
        if (DB::connection()->transactionLevel() < 1) {
            throw new DomainException('learning_p5_clock.transaction_required');
        }
        $key = spl_object_id(DB::connection()).':'.$actor;
        if (isset(self::$scopes[$key])) { return $operation(); }
        self::$scopes[$key] = true;
        try {
            $beforeRow = $lookup();
            $before = $beforeRow === null ? null : $this->state($beforeRow);
            $beforeActor = $beforeRow === null ? null : $this->integer($beforeRow->getRawOriginal('user_id'), true);
            $header = $before === null ? null : $this->header($before['id']);
            if ($beforeActor !== null && $beforeActor !== $actor) { throw new DomainException('learning_p5_clock.actor_mismatch'); }
            if ($header !== null) { $this->reconcile($before, $header); }
            $result = $operation();
            $afterRow = $lookup();
            if ($afterRow === null) {
                if ($before !== null && Characterization::query()->whereKey($before['id'])
                    ->orWhere('user_id', $beforeActor)->exists()) {
                    throw new DomainException('learning_p5_clock.identity_or_generation_regression');
                }
                if ($before !== null && $this->header($before['id']) !== null) {
                    throw new DomainException('learning_p5_clock.deletion_not_cascaded');
                }
                return $result;
            }
            $after = $this->state($afterRow);
            if ($this->integer($afterRow->getRawOriginal('user_id'), true) !== $actor) {
                throw new DomainException('learning_p5_clock.actor_mismatch');
            }
            if ($before !== null && ($after['id'] !== $before['id'] || $after['generation'] < $before['generation'])) {
                throw new DomainException('learning_p5_clock.identity_or_generation_regression');
            }
            // Detect late header drift even when the callback made no P5 change.
            if ($this->header($after['id']) !== $header) {
                throw new DomainException('learning_p5_clock.header_changed');
            }
            if ($this->sameState($after, $before)) { return $result; }
            if ($header !== null && $header['revision'] === self::MAX) {
                throw new DomainException('learning_p5_clock.revision_exhausted');
            }
            $next = [
                'characterization_id' => $after['id'],
                'generation' => $after['generation'],
                'revision' => $header === null ? 1 : $header['revision'] + 1,
                'epoch' => $header['epoch'] ?? bin2hex(random_bytes(32)),
            ];
            $next['digest'] = $this->digest($after, $next['epoch']);
            if ($header === null) {
                DB::table('learning_p5_source_revisions')->insert($next);
            } else {
                $changed = DB::table('learning_p5_source_revisions')->where($header)->update($next);
                if ($changed !== 1) { throw new DomainException('learning_p5_clock.conflict'); }
            }
            $finalHeader = $this->header($after['id']);
            $finalRow = $lookup();
            if ($finalRow === null) { throw new DomainException('learning_p5_clock.readback_mismatch'); }
            if ($this->integer($finalRow->getRawOriginal('user_id'), true) !== $actor) {
                throw new DomainException('learning_p5_clock.actor_mismatch');
            }
            if ($finalHeader !== $next || ! $this->sameState($this->state($finalRow), $after)) {
                throw new DomainException('learning_p5_clock.readback_mismatch');
            }
            return $result;
        } finally {
            unset(self::$scopes[$key]);
        }
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
            throw new DomainException('learning_p5_clock.invalid_integer');
        }
        return $raw;
    }

    private function header(int $id): ?array
    {
        $raw = DB::table('learning_p5_source_revisions')->where('characterization_id', $id)->first();
        if ($raw === null) { return null; }
        $h = (array) $raw;
        if (array_keys($h) !== ['characterization_id', 'generation', 'revision', 'epoch', 'digest']) {
            throw new DomainException('learning_p5_clock.invalid_header');
        }
        foreach (['characterization_id', 'generation', 'revision'] as $field) {
            $h[$field] = $this->integer($h[$field], $field !== 'generation');
        }
        foreach (['epoch', 'digest'] as $field) {
            if (! is_string($h[$field]) || preg_match('/\A[a-f0-9]{64}\z/D', $h[$field]) !== 1) {
                throw new DomainException('learning_p5_clock.invalid_header');
            }
        }
        return $h;
    }

    private function state(Characterization $row): array
    {
        $raw = $row->getRawOriginal();
        $generation = $this->integer($raw['submission_generation'] ?? null);
        $form = $raw['form_data'] ?? null;
        $formPresent = $form !== null;
        if ($form !== null) {
            if (! is_string($form)) { throw new DomainException('learning_p5_clock.invalid_json'); }
            try {
                $form = json_decode($form, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new DomainException('learning_p5_clock.invalid_json', 0, $error);
            }
            if ($form !== null && $form !== [] && ! $form instanceof stdClass) {
                throw new DomainException('learning_p5_clock.invalid_root');
            }
            $this->canonical($form); // Reject nonfinite numbers anywhere in stored JSON.
        }
        $source = [];
        foreach (['company_profile', 'operations'] as $field) {
            $present = $form instanceof stdClass && property_exists($form, $field);
            $value = $present ? $form->$field : null;
            if ($value !== null && $value !== [] && ! $value instanceof stdClass) {
                throw new DomainException('learning_p5_clock.invalid_section');
            }
            $source[$field] = ['present' => $present, 'value' => $this->canonical($value)];
        }
        if ($formPresent) {
            $protected = json_decode($raw['form_data'], false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            foreach (['company_profile', 'operations'] as $field) {
                $value = $protected instanceof stdClass && property_exists($protected, $field) ? $protected->$field : null;
                if (serialize($source[$field]['value']) !== serialize($this->canonical($value))) {
                    throw new DomainException('learning_p5_clock.unsupported_integer');
                }
            }
        }
        $source['form_data_present'] = $formPresent;
        $source['nace_code'] = [
            'present' => array_key_exists('nace_code', $raw),
            'value' => $raw['nace_code'] ?? null,
        ];
        return [
            'id' => $this->integer($raw['id'] ?? null, true),
            'generation' => $generation,
            'source' => $source,
        ];
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
            throw new DomainException('learning_p5_clock.nonfinite');
        }
        return $value;
    }

    private function digest(array $state, string $epoch): string
    {
        return hash('sha256', json_encode([
            'namespace' => 'learning-p5-source-state',
            'schema' => 'learning-p5-source-clock-v1',
            'epoch' => $epoch, 'characterization_id' => $state['id'],
            'generation' => $state['generation'], 'source' => $state['source'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, 512));
    }

    private function reconcile(array $state, array $header): void
    {
        if ($state['id'] !== $header['characterization_id'] || $state['generation'] !== $header['generation']
            || $this->digest($state, $header['epoch']) !== $header['digest']) {
            throw new DomainException('learning_p5_clock.source_drift');
        }
    }
}
