<?php

namespace App\Services;

use App\Models\Characterization;
use App\Services\Contracts\CharacterizationGateway;
use App\Services\Contracts\PreparedCharacterizationGateway;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Transient claim-time parent witness, never durable restart authority. */
final class LearningP6JobParentFence
{
    private bool $usable = true;

    public function __construct(
        private readonly array $identity,
        private readonly array $p5,
        private readonly array $base,
        private readonly LearningP6PreparedRequest $request,
        private readonly ?array $documents = null,
    ) {}

    public function __wakeup(): void { $this->usable = false; }

    public static function requested(): bool
    {
        $flag = config('services.learning_p6_job_parent_fence.enabled');
        return $flag !== null && $flag !== false;
    }

    public static function guard(CharacterizationGateway $gateway): void
    {
        self::guardOwned($gateway);
        if (DB::connection()->transactionLevel() !== 0) {
            throw new DomainException('learning_p6_job.disposable_unowned_connection_required');
        }
    }

    /** Same admission inside our owned transaction; entry alone requires level zero. */
    private static function guardOwned(CharacterizationGateway $gateway): void
    {
        if (LearningP6InterpretationContext::requested()) {
            LearningP6InterpretationContext::guard();
            if (get_class($gateway) !== ApiCharacterizationGateway::class) { throw new DomainException('learning_p6_interpretation.gateway'); }
        }
        if (config('services.learning_p6_job_parent_fence.enabled') !== true
            || ! app()->environment('testing')
            || ! LearningP5SourceRevisionClock::enabled()
            || ! LearningP6BaseSourceRevisionClock::enabled()
            || config('services.learning_p6_prepared_request.enabled') !== true
            || ! $gateway instanceof PreparedCharacterizationGateway) {
            throw new DomainException('learning_p6_job.guard');
        }
        $connection = DB::connection();
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$connection])) {
            throw new DomainException('learning_p6_job.disposable_unowned_connection_required');
        }
    }

    /** Raw submitted markers permit first capture, never a waiting/replayed retry. */
    public static function assertFreshClaim(array $raw, mixed $queueAttempt): void
    {
        foreach (['status', 'retry_count', 'next_retry_at', 'last_error', 'last_job_attempted_at'] as $key) {
            if (! array_key_exists($key, $raw)) { throw new DomainException('learning_p6_job.fresh_claim_required'); }
        }
        if ($queueAttempt !== 1 || $raw['status'] !== Characterization::STATUS_SUBMITTED
            || ! in_array($raw['retry_count'], [0, '0'], true)
            || $raw['next_retry_at'] !== null || $raw['last_error'] !== null
            || ($raw['last_job_attempted_at'] !== null && (! is_string($raw['last_job_attempted_at'])
                || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $raw['last_job_attempted_at'])))) {
            throw new DomainException('learning_p6_job.fresh_claim_required');
        }
        // A prior timestamp alone does not invalidate a new explicit submitted dispatch.
    }

    public static function identity(array $raw): array
    {
        $out = [];
        foreach (['id', 'user_id', 'submission_generation'] as $key) {
            $value = $raw[$key] ?? null;
            if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)
                && (string) (int) $value === $value) { $value = (int) $value; }
            if (! is_int($value) || $value < ($key === 'submission_generation' ? 0 : 1)
                || $value > 9007199254740991) { throw new DomainException('learning_p6_job.raw_identity'); }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function capture(array $identity, Characterization $row, PreparedCharacterizationGateway $gateway, bool $withDocuments = false): self
    {
        self::guardOwned($gateway);
        if ($withDocuments) { self::documentConnection(); }
        self::matchesIdentity($identity, $row);
        $p5 = (new LearningP5SourceRevisionClock)->current($identity['id']);
        $base = (new LearningP6BaseSourceRevisionClock)->current($identity['id']);
        if ($p5 === null || $base === null) { throw new DomainException('learning_p6_job.missing_parent'); }
        $row->unsetRelation('user');
        $request = $gateway->prepare($row);
        if (LearningP6InterpretationContext::requested() && $request->interpretation() === null) {
            throw new DomainException('learning_p6_interpretation.missing');
        }
        return new self($identity, $p5, $base, $request,
            $withDocuments ? self::documentWitness($identity['id']) : null);
    }

    /** Record SHA is metadata, never a checksum of stored file bytes. */
    private static function documentConnection(): \Illuminate\Database\Connection
    {
        $default = DB::connection();
        $models = array_map(fn ($class) => (new $class)->getConnection(), [\App\Models\User::class,
            Characterization::class, \App\Models\CharacterizationDocument::class,
            \App\Models\EsrsTopic::class, \App\Models\NaceCode::class]);
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$default, ...$models])) { throw new DomainException('learning_p6_job.document_connection'); }
        $pdo = $default->getPdo();
        foreach ($models as $connection) {
            if ($connection->getPdo() !== $pdo) { throw new DomainException('learning_p6_job.document_connection'); }
        }
        return $default;
    }

    private static function documentWitness(int $id): array
    {
        self::documentConnection();
        $fields = ['id', 'characterization_id', 'sha256', 'size_bytes', 'mime', 'status',
            'extraction_generation', 'extraction_lease_token', 'extraction_dispatched_at',
            'extraction_started_at', 'extraction_json', 'merged_state_version'];
        $rows = (new \App\Models\CharacterizationDocument)->newQuery()->select($fields)
            ->where('characterization_id', $id)->orderBy('id')->get()->map(function ($row) use ($fields, $id) {
                $raw = array_intersect_key($row->getRawOriginal(), array_flip($fields));
                foreach (['id', 'characterization_id'] as $key) {
                    $value = $raw[$key];
                    if (! is_int($value) || $value < 1 || $value > 9007199254740991
                        || ($key === 'characterization_id' && $value !== $id)) {
                        throw new DomainException('learning_p6_job.document_identity');
                    }
                }
                return $raw;
            })->all();
        self::documentConnection();
        return $rows;
    }

    public function documentsDigest(): ?string
    {
        return $this->documents === null ? null : hash('sha256',
            json_encode($this->documents, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function matchesIdentity(array $identity, Characterization $row): void
    {
        if (self::identity($row->getRawOriginal()) !== $identity) {
            throw new DomainException('learning_p6_job.identity_changed');
        }
    }

    public function prepared(): LearningP6PreparedRequest { return $this->request; }

    public function assertParents(Characterization $row, PreparedCharacterizationGateway $gateway, array $statuses): void
    {
        self::guardOwned($gateway);
        if ($this->documents !== null) { self::documentConnection(); }
        if (! $this->usable) { throw new DomainException('learning_p6_job.restart_denied'); }
        self::matchesIdentity($this->identity, $row);
        if (! in_array($row->status, $statuses, true)
            || (new LearningP5SourceRevisionClock)->current($row->id) !== $this->p5
            || (new LearningP6BaseSourceRevisionClock)->current($row->id) !== $this->base) {
            throw new DomainException('learning_p6_job.parent_changed');
        }
        $this->assertInput($row, $gateway);
        if ($this->documents !== null && self::documentWitness($this->identity['id']) !== $this->documents) {
            throw new DomainException('learning_p6_job.documents_changed');
        }
    }

    private function assertInput(Characterization $row, PreparedCharacterizationGateway $gateway): void
    {
        $this->request->interpretation()?->assertCurrent();
        $row->unsetRelation('user');
        $fresh = $gateway->prepare($row);
        if (LearningP6InterpretationContext::requested() && $this->request->interpretation() === null) {
            throw new DomainException('learning_p6_interpretation.missing');
        }
        if ($fresh->interpretation()?->digest() !== $this->request->interpretation()?->digest()) {
            throw new DomainException('learning_p6_interpretation.changed');
        }
        if ($fresh->digest() !== $this->request->digest()) {
            throw new DomainException('learning_p6_job.input_changed');
        }
        self::guardOwned($gateway);
        $this->request->interpretation()?->assertCurrent();
    }

    /** Outer transaction retains locks through every owner finalization. */
    public function mutate(PreparedCharacterizationGateway $gateway, CharacterizationStateTransaction $transactions,
        array $statuses, Closure $operation, bool $output = false): Characterization
    {
        self::guard($gateway);
        return DB::transaction(function () use ($gateway, $transactions, $statuses, $operation, $output) {
            $expected = null;
            $baseClock = new LearningP6BaseSourceRevisionClock;
            $lookup = fn () => Characterization::query()->findOrFail($this->identity['id']);
            $before = $baseClock->finalizationWitness($lookup);
            $transactions->runForUser($this->identity['user_id'], function ($row) use ($gateway, $statuses, $operation, &$expected, $baseClock, $lookup) {
                if ($row === null) { throw new DomainException('learning_p6_job.row_missing'); }
                $this->assertParents($row, $gateway, $statuses);
                $operation($row);
                $expected = ['row' => $row->getRawOriginal(), 'status' => $row->status, 'witness' => $baseClock->finalizationWitness($lookup)];
            });
            $row = $lookup();
            self::matchesIdentity($this->identity, $row);
            $after = $baseClock->finalizationWitness($lookup);
            if ($row->getRawOriginal() !== $expected['row'] || $row->status !== $expected['status'] || $after['checksum'] !== $expected['witness']['checksum']
                || (new LearningP5SourceRevisionClock)->current($row->id) !== $this->p5) {
                throw new DomainException('learning_p6_job.finalization_changed');
            }
            $header = $baseClock->current($row->id);
            $changed = $before['checksum'] !== $expected['witness']['checksum'];
            if ((! $output && $changed) || $header['epoch'] !== $this->base['epoch']
                || $header['generation'] !== $this->base['generation']
                || $header['revision'] !== $this->base['revision'] + ($changed ? 1 : 0)
                || (! $changed && $header !== $this->base)) {
                throw new DomainException('learning_p6_job.base_evolution');
            }
            $this->assertInput($row, $gateway);
            return $row;
        });
    }
}
