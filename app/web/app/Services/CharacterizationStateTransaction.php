<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class CharacterizationStateTransaction
{
    /**
     * Run a characterization state mutation against the latest row while holding
     * the single lock shared by every form_data writer.
     *
     * @template T
     * @param  Closure(Characterization): T  $operation
     * @return T
     */
    public function run(int $characterizationId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($characterizationId, $operation): mixed {
            $characterization = Characterization::query()
                ->whereKey($characterizationId)
                ->lockForUpdate()
                ->firstOrFail();

            return $operation($characterization);
        }, 3);
    }

    /**
     * Serialize creation and mutation for the single characterization owned by a
     * user. Locking the user also closes the no-row-yet creation race.
     *
     * @template T
     * @param  Closure(?Characterization, User): T  $operation
     * @return T
     */
    public function runForUser(int $userId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($userId, $operation): mixed {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $characterization = Characterization::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            return $operation($characterization, $user);
        }, 3);
    }
}
