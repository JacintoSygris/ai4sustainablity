<?php

namespace App\Services;

use App\Jobs\PurgeCharacterizationDocumentJob;
use App\Models\CharacterizationDocument;
use App\Models\CharacterizationDocumentPurge;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CharacterizationDocumentPurgeService
{
    private const DISPATCH_LEASE_SECONDS = 300;

    private const DISPATCH_RETRY_SECONDS = 60;

    /** @var array<int, int> */
    private const PURGE_RETRY_DELAYS = [60, 300, 900, 3600];

    public function __construct(private readonly Cache $cache) {}

    public function stageUnregisteredPath(
        int $userId,
        int $characterizationId,
        int $sizeBytes,
        string $path,
    ): CharacterizationDocumentPurge {
        if ($path === '') {
            throw new RuntimeException('characterization_document_purge_path_missing');
        }

        $disk = CharacterizationDocument::STORAGE_DISK;
        $pathHash = hash('sha256', $disk."\0".$path);

        return CharacterizationDocumentPurge::query()->create([
            'source_document_id' => null,
            'user_id' => $userId,
            'characterization_id' => $characterizationId,
            'size_bytes' => $sizeBytes,
            'path_hash' => $pathHash,
            'storage_disk' => $disk,
            'stored_path' => $path,
            'next_attempt_at' => now()->addSeconds(self::DISPATCH_LEASE_SECONDS),
            'leased_until' => now()->addSeconds(self::DISPATCH_LEASE_SECONDS),
        ]);
    }

    public function recoverUnregisteredPath(int $purgeId): void
    {
        CharacterizationDocumentPurge::query()->whereKey($purgeId)->update([
            'next_attempt_at' => null,
            'leased_until' => null,
            'lease_token' => null,
        ]);

        $this->purgeNowOrQueue([$purgeId]);
    }

    public function stage(CharacterizationDocument $document): CharacterizationDocumentPurge
    {
        $disk = CharacterizationDocument::STORAGE_DISK;
        $path = (string) $document->stored_path;

        if ($path === '') {
            throw new RuntimeException('characterization_document_purge_path_missing');
        }

        $pathHash = hash('sha256', $disk."\0".$path);
        $purge = CharacterizationDocumentPurge::query()->firstOrCreate(
            ['path_hash' => $pathHash],
            [
                'source_document_id' => $document->getKey(),
                'user_id' => $document->characterization?->user_id,
                'characterization_id' => $document->characterization_id,
                'size_bytes' => (int) $document->size_bytes,
                'storage_disk' => $disk,
                'stored_path' => $path,
            ],
        );

        if ($purge->storage_disk !== $disk || $purge->stored_path !== $path) {
            throw new RuntimeException('characterization_document_purge_hash_collision');
        }

        return $purge;
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array<int, int>
     */
    public function intentIdsForDocumentIds(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return CharacterizationDocumentPurge::query()
            ->whereIn('source_document_id', array_values(array_unique($documentIds)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Register physical deletion against the outermost transaction. Outside
     * a transaction Laravel executes the callback immediately; inside an
     * ambient transaction the conservative response remains pending until
     * that transaction commits.
     *
     * @param  array<int, int>  $purgeIds
     */
    public function purgeAfterCommit(array $purgeIds): bool
    {
        $pending = true;

        DB::afterCommit(function () use ($purgeIds, &$pending): void {
            try {
                $pending = $this->purgeNowOrQueue($purgeIds);
            } catch (Throwable $exception) {
                $pending = true;
                Log::error('Private characterization document purge recovery failed after commit', [
                    'error_type' => $exception::class,
                ]);
            }
        });

        return $pending;
    }

    /**
     * Purge immediately after the database transaction is durable. A failed
     * physical delete leaves its locator in the outbox and queues an idempotent
     * retry instead of resurrecting a database row whose bytes are already gone.
     *
     * @param  array<int, int>  $purgeIds
     */
    public function purgeNowOrQueue(array $purgeIds): bool
    {
        $pending = false;

        foreach (array_values(array_unique($purgeIds)) as $purgeId) {
            try {
                $this->purgeOrFail((int) $purgeId);
            } catch (Throwable $exception) {
                $pending = true;
                Log::warning('Private characterization document purge queued for retry', [
                    'purge_id' => (int) $purgeId,
                    'error_type' => $exception::class,
                ]);

                try {
                    $claim = $this->claimForImmediateRetry((int) $purgeId);
                    if ($claim !== null) {
                        $this->dispatchClaim($claim);
                    }
                } catch (Throwable $recoveryException) {
                    Log::error('Private characterization document purge retry recovery failed', [
                        'purge_id' => (int) $purgeId,
                        'error_type' => $recoveryException::class,
                    ]);
                }
            }
        }

        return $pending;
    }

    /**
     * @return array<int, array{id: int, lease_token: string}>
     */
    public function claimEligibleForDispatch(int $limit): array
    {
        return DB::transaction(function () use ($limit): array {
            $now = now();
            $purges = CharacterizationDocumentPurge::query()
                ->where(function ($query) use ($now): void {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('leased_until')
                        ->orWhere('leased_until', '<=', $now);
                })
                ->orderByRaw('COALESCE(next_attempt_at, created_at) ASC')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(max(1, min(1000, $limit)))
                ->get();

            return $purges->map(function (CharacterizationDocumentPurge $purge) use ($now): array {
                $leaseToken = (string) Str::uuid();
                $purge->forceFill([
                    'lease_token' => $leaseToken,
                    'leased_until' => $now->copy()->addSeconds(self::DISPATCH_LEASE_SECONDS),
                    'next_attempt_at' => $now->copy()->addSeconds(self::DISPATCH_RETRY_SECONDS),
                ])->save();

                return ['id' => (int) $purge->id, 'lease_token' => $leaseToken];
            })->all();
        }, 3);
    }

    /** @param array{id: int, lease_token: string} $claim */
    public function dispatchClaim(array $claim): bool
    {
        try {
            PurgeCharacterizationDocumentJob::dispatch($claim['id']);

            return true;
        } catch (Throwable $exception) {
            try {
                (new UniqueLock($this->cache))->release(
                    new PurgeCharacterizationDocumentJob($claim['id']),
                );
            } catch (Throwable $lockException) {
                Log::error('Private characterization document purge unique lock could not be released', [
                    'purge_id' => $claim['id'],
                    'error_type' => $lockException::class,
                ]);
            }

            try {
                $this->recordDispatchFailure($claim['id'], $claim['lease_token']);
            } catch (Throwable $recordException) {
                Log::error('Private characterization document purge dispatch failure could not be recorded', [
                    'purge_id' => $claim['id'],
                    'error_type' => $recordException::class,
                ]);
            }
            Log::error('Private characterization document purge could not be queued', [
                'purge_id' => $claim['id'],
                'error_type' => $exception::class,
            ]);

            return false;
        }
    }

    /** @return array{id: int, lease_token: string}|null */
    private function claimForImmediateRetry(int $purgeId): ?array
    {
        return DB::transaction(function () use ($purgeId): ?array {
            $purge = CharacterizationDocumentPurge::query()->lockForUpdate()->find($purgeId);

            if (! $purge) {
                return null;
            }

            $now = now();
            if ($purge->leased_until !== null && $purge->leased_until->isAfter($now)) {
                return null;
            }

            $leaseToken = (string) Str::uuid();
            $purge->forceFill([
                'lease_token' => $leaseToken,
                'leased_until' => $now->copy()->addSeconds(self::DISPATCH_LEASE_SECONDS),
                'next_attempt_at' => $now->copy()->addSeconds(self::DISPATCH_RETRY_SECONDS),
            ])->save();

            return ['id' => (int) $purge->id, 'lease_token' => $leaseToken];
        }, 3);
    }

    private function recordDispatchFailure(int $purgeId, string $leaseToken): void
    {
        DB::transaction(function () use ($purgeId, $leaseToken): void {
            $purge = CharacterizationDocumentPurge::query()->lockForUpdate()->find($purgeId);

            if (! $purge || $purge->lease_token !== $leaseToken) {
                return;
            }

            $purge->forceFill([
                'last_error' => 'dispatch_failed',
                'last_attempted_at' => now(),
                'next_attempt_at' => now()->addSeconds(self::DISPATCH_RETRY_SECONDS),
                'lease_token' => null,
                'leased_until' => null,
            ])->save();
        }, 3);
    }

    public function purgeOrFail(int $purgeId): void
    {
        $failure = DB::transaction(function () use ($purgeId): ?RuntimeException {
            $purge = CharacterizationDocumentPurge::query()->lockForUpdate()->find($purgeId);

            if (! $purge) {
                return null;
            }

            $path = $purge->stored_path;

            try {
                $disk = Storage::disk($purge->storage_disk);
                $exists = $disk->exists($path);
            } catch (Throwable $exception) {
                $this->recordFailure($purge, 'storage_error');

                return new RuntimeException('characterization_document_purge_storage_failed', 0, $exception);
            }

            if ($exists) {
                try {
                    $deleted = $disk->delete($path);
                } catch (Throwable $exception) {
                    $this->recordFailure($purge, 'storage_error');

                    return new RuntimeException('characterization_document_purge_storage_failed', 0, $exception);
                }
            } else {
                $deleted = true;
            }

            if (! $deleted) {
                $this->recordFailure($purge, 'delete_failed');

                return new RuntimeException('characterization_document_purge_failed');
            }

            try {
                $stillExists = $disk->exists($path);
            } catch (Throwable $exception) {
                $this->recordFailure($purge, 'storage_error');

                return new RuntimeException('characterization_document_purge_storage_failed', 0, $exception);
            }

            if ($stillExists) {
                $this->recordFailure($purge, 'verification_failed');

                return new RuntimeException('characterization_document_purge_verification_failed');
            }

            $purge->delete();

            return null;
        }, 3);

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function recordFailure(CharacterizationDocumentPurge $purge, string $error): void
    {
        $attempts = $purge->attempts + 1;
        $retryDelay = self::PURGE_RETRY_DELAYS[min($attempts - 1, count(self::PURGE_RETRY_DELAYS) - 1)];
        $nextAttempt = now()->addSeconds($retryDelay);

        $purge->forceFill([
            'attempts' => $attempts,
            'last_error' => $error,
            'last_attempted_at' => now(),
            'next_attempt_at' => $nextAttempt,
            'leased_until' => $purge->lease_token !== null
                ? $nextAttempt->copy()->addSeconds(self::DISPATCH_RETRY_SECONDS)
                : null,
        ])->save();
    }
}
