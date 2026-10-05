<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\LearningAuthorizationRecord;
use App\Models\LearningCase;
use App\Models\LearningCaseP5Snapshot;
use DomainException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/** Private synthetic storage; live P5 capture is restricted to disposable tests. */
final class LearningCaseP5Storage
{
    public function __construct(
        private readonly CharacterizationStateTransaction $transactions,
        private readonly LearningCaseSnapshot $cases,
        private readonly LearningAuthorizationLedger $ledger,
        private readonly LearningP5Snapshot $projections,
    ) {}

    /** Capture P5 only. The other case references remain passive, unverified inputs. */
    public function captureForAccount(mixed $actor, mixed $group, mixed $purpose, mixed $caseJson, mixed $authorityJson): LearningCase
    {
        $connection = $this->captureConnection();
        if (! is_int($actor) || $actor < 1 || ! is_string($caseJson)) {
            throw new InvalidArgumentException('learning_p5.invalid_input');
        }
        $contract = new LearningCaseContract;
        $contract->assertEligibleLearningCase($caseJson, $authorityJson);
        $contract->assertLearningCaseHash($caseJson);
        $payload = json_decode(LearningCaseContract::canonicalLearningCasePayload($caseJson), true, 512, JSON_THROW_ON_ERROR);

        return $this->transactions->runForUser($actor, function (?Characterization $source) use ($actor, $group, $purpose, $caseJson, $authorityJson, $payload, $connection): LearningCase {
            $header = $this->captureHeader($actor, $source, $payload, $connection);
            $projection = $this->projections->project($source);
            if ($payload['p5_snapshot']['schema_version'] !== $projection['schema_version']
                || $payload['p5_snapshot']['digest'] !== $projection['digest']) {
                throw new DomainException('learning_p5.public_reference_mismatch');
            }
            $verify = function () use ($actor, $payload, $connection, $header): void {
                $this->captureConnection($connection);
                \App\Models\User::query()->whereKey($actor)->lockForUpdate()->firstOrFail();
                $fresh = Characterization::query()->where('user_id', $actor)->lockForUpdate()->first();
                if ($this->captureHeader($actor, $fresh, $payload, $connection) !== $header) {
                    throw new DomainException('learning_p5.source_changed');
                }
            };
            return $this->persistForAccount($actor, $group, $purpose, $caseJson, $authorityJson, $projection, $this->encode($header), $verify);
        });
    }

    /** Metadata only: resolving these connections must not open PDO or inspect files. */
    private function captureConnection(?\Illuminate\Database\Connection $expected = null): \Illuminate\Database\Connection
    {
        if (config('services.learning_p5_live_capture.enabled') !== true
            || config('services.learning_source_clock.enabled') !== true || ! app()->environment('testing')) {
            throw new DomainException('learning_p5.capture_disabled');
        }
        $connection = DB::connection();
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$connection])
            || ($expected !== null && $connection !== $expected)) {
            throw new DomainException('learning_p5.disposable_connection_required');
        }
        foreach ([Characterization::class, \App\Models\User::class, LearningCase::class,
            \App\Models\LearningCaseState::class, \App\Models\LearningCompanyMembership::class,
            \App\Models\LearningCompanyGroup::class, LearningCaseP5Snapshot::class,
            LearningAuthorizationRecord::class, \App\Models\LearningAuthorizationState::class] as $model) {
            if ((new $model)->getConnection() !== $connection) {
                throw new DomainException('learning_p5.connection_mismatch');
            }
        }
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$connection])) { throw new DomainException('learning_p5.disposable_connection_required'); }
        return $connection;
    }

    private function captureHeader(int $actor, ?Characterization $source, array $payload, \Illuminate\Database\Connection $connection): array
    {
        $this->captureConnection($connection);
        if ($source === null) { throw new DomainException('learning_p5.source_missing'); }
        $this->assertRawInteger($source->getRawOriginal('user_id'), $actor);
        $header = (new LearningP5SourceRevisionClock)->current($source->id);
        if ($header === null) { throw new DomainException('learning_p5.source_header_missing'); }
        foreach (['generation', 'revision', 'digest'] as $field) {
            if ($payload['source_revisions']['p5'][$field] !== $header[$field]) {
                throw new DomainException('learning_p5.source_reference_mismatch');
            }
        }
        return $header;
    }

    public function createForAccount(mixed $actor, mixed $group, mixed $purpose, mixed $caseJson, mixed $authorityJson, mixed $p5Projection): LearningCase
    {
        return $this->persistForAccount($actor, $group, $purpose, $caseJson, $authorityJson, $p5Projection);
    }

    private function persistForAccount(mixed $actor, mixed $group, mixed $purpose, mixed $caseJson, mixed $authorityJson, mixed $p5Projection, ?string $sourceHeader = null, ?\Closure $verifySource = null): LearningCase
    {
        if (! is_int($actor) || $actor < 1 || ! is_int($group) || $group < 1
            || ! is_string($purpose) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9:._-]{0,127}\z/D', $purpose) !== 1
            || ! is_string($caseJson) || ! is_string($authorityJson)) {
            throw new InvalidArgumentException('learning_p5.invalid_input');
        }
        $projection = $this->validatedProjection($p5Projection);
        // The accepted raw JSON contract remains the duplicate-key/schema/hash authority.
        $contract = new LearningCaseContract;
        $contract->assertEligibleLearningCase($caseJson, $authorityJson);
        $contract->assertLearningCaseHash($caseJson);
        $payload = json_decode(LearningCaseContract::canonicalLearningCasePayload($caseJson), true, 512, JSON_THROW_ON_ERROR);
        if ($payload['p5_snapshot']['schema_version'] !== $projection['schema_version']
            || $payload['p5_snapshot']['digest'] !== $projection['digest']) {
            throw new InvalidArgumentException('learning_p5.public_reference_mismatch');
        }
        if ($payload['closure_evidence']['server_actor_id'] !== (string) $actor) {
            throw new DomainException('learning_p5.actor_mismatch');
        }

        return $this->transactions->runForUser($actor, function () use ($actor, $group, $purpose, $caseJson, $authorityJson, $projection, $payload, $sourceHeader, $verifySource): LearningCase {
            $verifySource?->__invoke();
            $evidence = $this->verifiedEvidence($actor, $group, $purpose, $payload);
            $this->assertEvidenceParity($evidence, $this->verifiedEvidence($actor, $group, $purpose, $payload));
            $case = $this->cases->createForAccount($actor, $caseJson, $authorityJson);
            if ((int) $case->learning_company_group_id !== $group) {
                throw new DomainException('learning_p5.group_mismatch');
            }
            $expected = [
                'learning_case_id' => $case->id,
                'values_text' => $this->encode($projection['values']),
                'schema_version' => $projection['schema_version'], 'digest' => $projection['digest'],
                'feature_schema_version' => LearningP5Snapshot::FEATURE_SCHEMA_VERSION,
                'transform_version' => LearningP5Snapshot::TRANSFORM_VERSION,
                'actor_id' => $actor, 'group_id' => $group, 'purpose' => $purpose,
                'authorization_digest' => $evidence['current_digest'],
                'authorization_generation' => $evidence['generation'],
            ];
            $row = LearningCaseP5Snapshot::query()->where('learning_case_id', $case->id)->lockForUpdate()->first();
            $receipt = DB::table('learning_case_p5_storage_receipts')->where('learning_case_id', $case->id)->lockForUpdate()->first();
            if (($row === null) !== ($receipt === null)) {
                throw new DomainException('learning_p5.persisted_incoherent');
            }
            if ($row === null) {
                // Legacy passive cases may be filled only in their initial stored state.
                if ($case->state->status !== 'stored' || $case->state->state_version !== 0) {
                    throw new DomainException('learning_p5.initial_state_required');
                }
                $this->assertEvidenceParity($evidence, $this->verifiedEvidence($actor, $group, $purpose, $payload));
                $created = LearningCaseP5Snapshot::query()->forceCreate($expected + ['created_at' => now()]);
                $row = $created->fresh();
                $this->assertStored($row, $expected);
                DB::table('learning_case_p5_storage_receipts')->insert([
                    'learning_case_id' => $case->id, 'snapshot_id' => $row->id,
                    'completion_reference' => $this->completionReference($row, $expected, $sourceHeader),
                    ...($sourceHeader === null ? [] : ['source_header_text' => $sourceHeader]),
                ]);
            }
            // Read back both subordinate stores inside the same outer rollback domain.
            $row = LearningCaseP5Snapshot::query()->where('learning_case_id', $case->id)->lockForUpdate()->first();
            $receipt = DB::table('learning_case_p5_storage_receipts')->where('learning_case_id', $case->id)->lockForUpdate()->first();
            if ($row === null || $receipt === null) { throw new DomainException('learning_p5.persisted_incoherent'); }
            $this->assertStored($row, $expected);
            $this->assertRawInteger($receipt->learning_case_id ?? null, $case->id);
            $this->assertRawInteger($receipt->snapshot_id ?? null, $this->snapshotId($row));
            if (($receipt->source_header_text ?? null) !== $sourceHeader
                || ($receipt->completion_reference ?? null) !== $this->completionReference($row, $expected, $sourceHeader)) {
                throw new DomainException('learning_p5.persisted_incoherent');
            }
            $this->assertEvidenceParity($evidence, $this->verifiedEvidence($actor, $group, $purpose, $payload));
            // Fresh account/group ownership check also applies on an exact replay.
            $this->cases->findForUser($actor, $case->id);
            $verifySource?->__invoke();
            return $case;
        });
    }

    private function validatedProjection(mixed $value): array
    {
        $this->exactKeys($value, ['schema_version', 'digest', 'values']);
        $this->exactKeys($value['values'], ['employee_count_range', 'headquarters_country', 'stock_listed']);
        if ($value['schema_version'] !== LearningP5Snapshot::P5_INPUT_SCHEMA_VERSION
            || ! is_string($value['digest']) || preg_match('/\A[a-f0-9]{64}\z/D', $value['digest']) !== 1) {
            throw new InvalidArgumentException('learning_p5.projection_invalid');
        }
        // Transient reconstruction reuses the accepted vocabulary/types/digest only.
        // This detached model is never saved and establishes no live-source provenance.
        $model = new Characterization;
        $model->setRawAttributes(['form_data' => $this->encode([
            'operations' => ['employee_count_range' => $value['values']['employee_count_range']],
            'company_profile' => ['headquarters_country' => $value['values']['headquarters_country'], 'stock_listed' => $value['values']['stock_listed']],
        ])]);
        $verified = $this->projections->project($model);
        if ($value['digest'] !== $verified['digest']) {
            throw new InvalidArgumentException('learning_p5.digest_mismatch');
        }
        return $verified;
    }

    private function exactKeys(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('learning_p5.projection_invalid');
        }
        $actual = array_keys($value);
        sort($actual); sort($keys);
        if ($actual !== $keys) { throw new InvalidArgumentException('learning_p5.projection_invalid'); }
    }

    private function verifiedEvidence(int $actor, int $group, string $purpose, array $payload): array
    {
        $e = $this->ledger->current($actor, $group, $purpose);
        if ($e['status'] !== 'granted' || $e['provenance'] !== 'synthetic-only' || $e['promotion_allowed'] !== false) {
            throw new DomainException('learning_p5.authorization_denied');
        }
        // current() has reconciled history and verified the fresh issuer before this read.
        $record = LearningAuthorizationRecord::query()->where('event_digest', $e['current_digest'])->firstOrFail();
        $p = json_decode($record->payload_text, true, 32, JSON_THROW_ON_ERROR);
        $w = $p['witness'];
        if ($p['actor_id'] !== $actor || $p['group_id'] !== $group || $p['purpose'] !== $purpose
            || $p['generation'] !== $e['generation'] || $p['status'] !== 'granted'
            || $payload['rights']['authorization_generation'] !== $e['generation']
            || $payload['rights']['policy_version'] !== $w['policy_version']
            || $payload['rights']['policy_digest'] !== $w['policy_digest']) {
            throw new DomainException('learning_p5.authorization_mismatch');
        }
        return $e;
    }

    private function assertEvidenceParity(array $before, array $after): void
    {
        if ($before !== $after) { throw new DomainException('learning_p5.authorization_changed'); }
    }

    private function assertStored(LearningCaseP5Snapshot $row, array $expected): void
    {
        $raw = $row->getRawOriginal();
        $this->snapshotId($row);
        foreach (['learning_case_id', 'actor_id', 'group_id', 'authorization_generation'] as $key) {
            $this->assertRawInteger($raw[$key] ?? null, $expected[$key]);
        }
        $date = $raw['created_at'] ?? null;
        if (! is_string($date) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $date) !== 1) {
            throw new DomainException('learning_p5.persisted_incoherent');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new DomainException('learning_p5.persisted_incoherent');
        }
        try {
            $projection = $this->validatedProjection([
                'schema_version' => $row->schema_version, 'digest' => $row->digest,
                'values' => json_decode($row->values_text, true, 32, JSON_THROW_ON_ERROR),
            ]);
        } catch (\JsonException|InvalidArgumentException $error) {
            throw new DomainException('learning_p5.persisted_incoherent', 0, $error);
        }
        if ($row->values_text !== $this->encode($projection['values'])) {
            throw new DomainException('learning_p5.persisted_incoherent');
        }
        foreach ($expected as $key => $value) {
            if (is_int($value)) { $this->assertRawInteger($raw[$key] ?? null, $value); }
            elseif (($raw[$key] ?? null) !== $value) { throw new DomainException('learning_p5.replay_conflict'); }
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function completionReference(LearningCaseP5Snapshot $row, array $expected, ?string $sourceHeader = null): string
    {
        return hash('sha256', ($sourceHeader === null ? "learning-p5-storage-completion-v1\0" : "learning-p5-live-capture-completion-v1\0").$this->encode([
            ...($sourceHeader === null ? [] : ['source_header_text' => $sourceHeader]),
            'snapshot_id' => $this->snapshotId($row), 'fields' => $expected,
            'created_at' => $row->getRawOriginal('created_at'),
        ]));
    }

    private function assertRawInteger(mixed $raw, int $expected): void
    {
        // PDO may expose integers as native ints or their exact decimal bytes.
        if ($raw !== $expected && $raw !== (string) $expected) {
            throw new DomainException('learning_p5.persisted_incoherent');
        }
    }

    private function snapshotId(LearningCaseP5Snapshot $row): int
    {
        $raw = $row->getRawOriginal('id');
        if (is_int($raw) && $raw > 0) { return $raw; }
        if (is_string($raw) && preg_match('/\A[1-9][0-9]*\z/D', $raw) === 1
            && (string) (int) $raw === $raw && (int) $raw > 0) { return (int) $raw; }
        throw new DomainException('learning_p5.persisted_incoherent');
    }
}
