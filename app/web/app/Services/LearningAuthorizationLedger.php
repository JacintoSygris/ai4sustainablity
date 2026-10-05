<?php

namespace App\Services;

use App\Models\LearningAuthorizationRecord;
use App\Models\LearningAuthorizationState;
use App\Models\User;
use DomainException;
use InvalidArgumentException;

/** Private synthetic mechanism. No operational eligibility or HTTP caller. */
final class LearningAuthorizationLedger
{
    public function __construct(
        private readonly LearningAuthorization $authorization,
        private readonly CharacterizationStateTransaction $transactions,
    ) {}

    // Mixed arguments deliberately reject weak PHP scalar coercion at this boundary.
    public function grant(mixed $actor, mixed $group, mixed $purpose, mixed $expectedGeneration, mixed $command): array
    {
        return $this->transition('grant', $actor, $group, $purpose, $expectedGeneration, $command);
    }

    public function revoke(mixed $actor, mixed $group, mixed $purpose, mixed $expectedGeneration, mixed $command): array
    {
        return $this->transition('revoke', $actor, $group, $purpose, $expectedGeneration, $command);
    }

    public function tombstone(mixed $actor, mixed $group, mixed $purpose, mixed $expectedGeneration, mixed $command): array
    {
        return $this->transition('tombstone', $actor, $group, $purpose, $expectedGeneration, $command);
    }

    /** Internal account withdrawal; no authority, client command or new subject. */
    public function tombstoneForAccount(int $actor): void
    {
        // Keep the account/first-row fence throughout enumeration and transitions.
        // Nested transactions remain subordinate to the supported deletion transaction.
        $this->transactions->runForUser($actor, function () use ($actor): void {
            $subjects = LearningAuthorizationState::query()->where('user_id', $actor)->orderBy('id')->get();
            foreach ($subjects as $subject) {
                $command = $this->digest(['learning-ledger:account-deletion:v1', $actor,
                    $subject->subject_digest, $subject->generation]);
                // Accepted transition reconciles even already-terminal history.
                $this->tombstone($actor, $subject->learning_company_group_id, $subject->purpose,
                    $subject->generation, $command);
            }
        });
    }

    public function current(mixed $actor, mixed $group, mixed $purpose): array
    {
        $this->validate($actor, $group, $purpose, 0, str_repeat('0', 64));
        if (! User::query()->whereKey($actor)->exists()) { return $this->evidence(null); }
        return $this->transactions->runForUser($actor, function () use ($actor, $group, $purpose) {
            [$header, $last, $fresh] = $this->lockedProjection($actor, $group, $purpose);
            return $this->readEvidence($header, $last, $fresh);
        });
    }

    private function transition(string $operation, mixed $actor, mixed $group, mixed $purpose, mixed $expected, mixed $command): array
    {
        $this->validate($actor, $group, $purpose, $expected, $command);
        if (! User::query()->whereKey($actor)->exists()) { return $this->evidence(null); }
        return $this->transactions->runForUser($actor, function () use ($operation, $actor, $group, $purpose, $expected, $command) {
            $intent = $this->digest([$operation, $actor, $group, $purpose, $expected, $command]);
            $receipt = LearningAuthorizationRecord::query()->where('command_id', $command)->first();
            if ($receipt && $receipt->intent_digest !== $intent) { throw new DomainException('learning_ledger.command_conflict'); }

            [$header, $last, $witness] = $this->lockedProjection(
                $actor, $group, $purpose, ! $receipt && $operation === 'grant', $receipt !== null,
            );
            if ($receipt) {
                if (! $header || $receipt->learning_authorization_state_id !== $header->id) {
                    throw new DomainException('learning_ledger.incoherent');
                }
                return array_merge($this->readEvidence($header, $last, $witness), [
                    'replayed' => true, 'receipt_generation' => $receipt->generation,
                    'receipt_digest' => $receipt->event_digest,
                ]);
            }
            if (($header?->generation ?? 0) !== $expected) { throw new DomainException('learning_ledger.stale_generation'); }
            if ($header?->status === 'deleted') { return $this->evidence($header); }
            if (($operation === 'grant' && $witness === null) || ($operation !== 'grant' && $header === null)) {
                return $this->evidence($header, 'denied');
            }
            if ($expected === PHP_INT_MAX) { throw new DomainException('learning_ledger.generation_exhausted'); }
            $status = match ($operation) { 'grant' => 'granted', 'revoke' => 'revoked', 'tombstone' => 'deleted' };
            $subject = $this->subject($actor, $group, $purpose);
            // Opaque fixture reference only. Real holder semantics remain unapproved.
            $holder = 'synthetic-holder:'.$this->digest([$actor, $group]);
            $previous = $header?->event_digest;
            $generation = $expected + 1;
            $payload = [
                'operation' => $operation, 'actor_id' => $actor, 'group_id' => $group, 'purpose' => $purpose,
                'subject_digest' => $subject, 'holder_ref' => $holder,
                'command_id' => $command, 'intent_digest' => $intent,
                'previous_generation' => $expected, 'generation' => $generation, 'previous_digest' => $previous,
                'status' => $status, 'witness' => $witness,
                'provenance' => 'synthetic-only', 'promotion_allowed' => false,
                'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ];
            $text = $this->canonical($payload);
            $digest = hash('sha256', $text);
            if (! $header) {
                $header = LearningAuthorizationState::query()->forceCreate([
                    'user_id' => $actor, 'learning_company_group_id' => $group, 'purpose' => $purpose,
                    'subject_digest' => $subject, 'holder_ref' => $holder,
                    'status' => $status, 'generation' => $generation, 'event_digest' => $digest,
                    'provenance' => 'synthetic-only', 'promotion_allowed' => false,
                ]);
            }
            LearningAuthorizationRecord::query()->forceCreate([
                'learning_authorization_state_id' => $header->id, 'command_id' => $command, 'intent_digest' => $intent,
                'previous_generation' => $expected, 'generation' => $generation, 'previous_digest' => $previous,
                'event_digest' => $digest, 'payload_text' => $text,
                'provenance' => 'synthetic-only', 'promotion_allowed' => false, 'created_at' => now(),
            ]);
            if ($operation === 'grant') {
                $fresh = $this->authorization->resolveForAccount($actor, $group, $purpose);
                if ($fresh === null || $this->canonical($fresh) !== $this->canonical($witness)) {
                    throw new DomainException('learning_ledger.witness_changed');
                }
            }
            $header->forceFill(['status' => $status, 'generation' => $generation, 'event_digest' => $digest])->save();
            $this->reconcile($header->fresh(), $actor, $group, $purpose);
            return array_merge($this->evidence($header), ['receipt_generation' => $generation, 'receipt_digest' => $digest]);
        });
    }

    /** Called only under runForUser: membership must precede the header lock. */
    private function lockedProjection(int $actor, int $group, string $purpose, bool $newGrant = false, bool $readCurrent = true): array
    {
        // The user lock serializes same-account transitions. This preliminary read
        // only selects whether authority is needed; the locked projection governs.
        $projection = LearningAuthorizationState::query()
            ->where('subject_digest', $this->subject($actor, $group, $purpose))->first();
        $needsAuthority = ($newGrant && $projection?->status !== 'deleted')
            || ($readCurrent && $projection?->status === 'granted');
        $witness = $needsAuthority ? $this->authorization->resolveForAccount($actor, $group, $purpose) : null;
        $header = $this->header($actor, $group, $purpose);
        if ($projection?->getRawOriginal() !== $header?->getRawOriginal()) {
            throw new DomainException('learning_ledger.incoherent');
        }
        return [$header, $this->reconcile($header, $actor, $group, $purpose), $witness];
    }

    private function header(int $actor, int $group, string $purpose): ?LearningAuthorizationState
    {
        return LearningAuthorizationState::query()->where('subject_digest', $this->subject($actor, $group, $purpose))->lockForUpdate()->first();
    }

    private function reconcile(?LearningAuthorizationState $header, int $actor, int $group, string $purpose): ?array
    {
        if (! $header) {
            // A changed/missing subject index must not turn an existing subject into absence.
            if (LearningAuthorizationState::query()->where('user_id', $actor)->where('learning_company_group_id', $group)->where('purpose', $purpose)->exists()) {
                throw new DomainException('learning_ledger.incoherent');
            }
            return null;
        }
        $subject = $this->subject($actor, $group, $purpose);
        $holder = 'synthetic-holder:'.$this->digest([$actor, $group]);
        $valid = $header->user_id === $actor && $header->learning_company_group_id === $group
            && $header->purpose === $purpose && $header->subject_digest === $subject && $header->holder_ref === $holder
            && $header->provenance === 'synthetic-only' && $header->promotion_allowed === false;
        $generation = 0;
        $previous = null;
        $status = 'denied';
        $last = null;
        foreach (LearningAuthorizationRecord::query()->where('learning_authorization_state_id', $header->id)->orderBy('generation')->get() as $record) {
            try { $p = json_decode($record->payload_text, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new DomainException('learning_ledger.incoherent'); }
            if (! is_array($p) || array_is_list($p)) { throw new DomainException('learning_ledger.incoherent'); }
            $op = $p['operation'] ?? null;
            $newStatus = match ($op) { 'grant' => 'granted', 'revoke' => 'revoked', 'tombstone' => 'deleted', default => null };
            $valid = $valid && $status !== 'deleted' && $newStatus !== null
                && $record->generation === $generation + 1 && $record->previous_generation === $generation
                && $record->previous_digest === $previous && $record->provenance === 'synthetic-only' && $record->promotion_allowed === false
                && preg_match('/\A[a-f0-9]{64}\z/D', $record->command_id) === 1
                && preg_match('/\A[a-f0-9]{64}\z/D', $record->event_digest) === 1
                && $record->intent_digest === $this->digest([$op, $actor, $group, $purpose, $generation, $record->command_id]);
            $expected = [
                'operation' => $op, 'actor_id' => $actor, 'group_id' => $group, 'purpose' => $purpose,
                'subject_digest' => $subject, 'holder_ref' => $holder, 'command_id' => $record->command_id,
                'intent_digest' => $record->intent_digest, 'previous_generation' => $generation,
                'generation' => $generation + 1, 'previous_digest' => $previous, 'status' => $newStatus,
                'witness' => $p['witness'] ?? null, 'provenance' => 'synthetic-only', 'promotion_allowed' => false,
                'recorded_at' => $p['recorded_at'] ?? null,
            ];
            $valid = $valid && is_string($expected['recorded_at'])
                && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $expected['recorded_at']) === 1
                && $this->canonical($expected) === $record->payload_text && $this->canonical($p) === $record->payload_text
                && hash('sha256', $record->payload_text) === $record->event_digest
                && ($op === 'grant' ? $this->validWitness($expected['witness'], $actor, $group, $purpose) : $expected['witness'] === null);
            if (! $valid) { throw new DomainException('learning_ledger.incoherent'); }
            $generation++;
            $previous = $record->event_digest;
            $status = $newStatus;
            $last = $p;
        }
        if (! $valid || $generation === 0 || $header->generation !== $generation || $header->event_digest !== $previous || $header->status !== $status) {
            throw new DomainException('learning_ledger.incoherent');
        }
        return $last;
    }

    private function validWitness(mixed $w, int $actor, int $group, string $purpose): bool
    {
        if (! is_array($w) || count($w) !== 15 || ($w['actor_id'] ?? null) !== $actor || ($w['group_id'] ?? null) !== $group
            || ($w['purpose'] ?? null) !== $purpose || ! is_int($w['membership_id'] ?? null) || $w['membership_id'] < 1
            || ($w['state'] ?? null) !== 'available' || ($w['provenance'] ?? null) !== 'synthetic-only' || ($w['promotion_allowed'] ?? null) !== false) { return false; }
        foreach (['issuer_ref', 'issuer_version', 'issuer_digest', 'capability_ref', 'capability_version', 'capability_digest', 'policy_version', 'policy_digest'] as $key) {
            $value = $w[$key] ?? null;
            if (! is_string($value) || $value === '' || trim($value) !== $value
                || (str_ends_with($key, '_digest') && preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1)) { return false; }
        }
        return true;
    }

    private function readEvidence(?LearningAuthorizationState $header, ?array $last, ?array $fresh): array
    {
        if ($header?->status === 'granted') {
            if ($fresh === null || $this->canonical($fresh) !== $this->canonical($last['witness'])) { return $this->evidence($header, 'denied'); }
        }
        return $this->evidence($header);
    }

    private function evidence(?LearningAuthorizationState $header, ?string $status = null): array
    {
        return ['status' => $status ?? $header?->status ?? 'denied', 'generation' => $header?->generation ?? 0,
            'stored_status' => $header?->status ?? 'denied', 'current_digest' => $header?->event_digest,
            'provenance' => 'synthetic-only', 'promotion_allowed' => false, 'replayed' => false];
    }

    private function validate(mixed $actor, mixed $group, mixed $purpose, mixed $generation, mixed $command): void
    {
        if (! is_int($actor) || $actor < 1 || ! is_int($group) || $group < 1 || ! is_int($generation) || $generation < 0
            || ! is_string($purpose) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}\z/D', $purpose) !== 1
            || ! is_string($command) || preg_match('/\A[a-f0-9]{64}\z/D', $command) !== 1) {
            throw new InvalidArgumentException('learning_ledger.invalid_input');
        }
    }

    private function subject(int $actor, int $group, string $purpose): string { return $this->digest([$actor, $group, $purpose]); }
    private function digest(array $value): string { return hash('sha256', $this->canonical($value)); }

    /** Sorted object keys; ordered primitive lists; exact UTF-8 JSON, no normalization. */
    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) { return '['.implode(',', array_map($this->canonical(...), $value)).']'; }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $member) { $parts[] = $this->canonical((string) $key).':'.$this->canonical($member); }
            return '{'.implode(',', $parts).'}';
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
    }
}
