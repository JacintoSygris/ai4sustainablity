<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class CharacterizationStateTransaction
{
    /** Runtime containment only. Validate every declaration before opening any PDO. */
    public static function admitsIsolatedConnections(array $connections, ?string $artifactRoot = null): bool
    {
        $native = [];
        foreach ($connections as $connection) {
            if (! $connection instanceof \Illuminate\Database\Connection) { return false; }
            if ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') { continue; }
            $profile = config('services.learning_native_fixture');
            if (! app()->runningInConsole() || ! app()->environment('testing') || ! is_array($profile)) { return false; }
            $keys = array_keys($profile); sort($keys);
            if ($keys !== ['database', 'driver', 'host', 'namespace', 'port', 'promotion_allowed', 'synthetic_only']
                || $profile['namespace'] !== 'test-namespace:t11-native' || $profile['synthetic_only'] !== true
                || $profile['promotion_allowed'] !== false || $profile['host'] !== '127.0.0.1'
                || $profile['database'] !== 'wp13_t11_5623f7cb97dc4878'
                || ! in_array($profile['driver'], ['mysql', 'pgsql'], true)
                || $profile['port'] !== ($profile['driver'] === 'mysql' ? 13316 : 15433)) { return false; }
            $declared = config('database.connections.'.$connection->getName());
            foreach ([$declared, $connection->getConfig()] as $declaration) {
                if (! is_array($declaration)) { return false; }
                foreach (['url','unix_socket','socket','read','write','sticky','hostaddr','service','dsn'] as $override) {
                    if (array_key_exists($override, $declaration)) { return false; }
                }
                foreach (['driver', 'host', 'port', 'database'] as $field) {
                    if (($declaration[$field] ?? null) !== $profile[$field]) { return false; }
                }
                if (($declaration['username'] ?? null) !== 'wp13_t11' || ($declaration['password'] ?? null) !== '') { return false; }
                foreach ($declaration['options'] ?? [] as $option => $value) {
                    if (! in_array($option, [\PDO::ATTR_STRINGIFY_FETCHES, \PDO::ATTR_EMULATE_PREPARES], true) || $value !== false) { return false; }
                }
            }
            if ($connection->getDriverName() !== $profile['driver'] || $connection->getDatabaseName() !== $profile['database']) { return false; }
            $native[] = [$connection, $profile];
        }
        foreach ($native as [$connection, $profile]) {
            $pdo = $connection->getPdo();
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== $profile['driver']) { return false; }
            $row = $profile['driver'] === 'mysql'
                ? $pdo->query('SELECT DATABASE() AS db, @@version AS version, @@port AS port, @@bind_address AS host')->fetch(\PDO::FETCH_ASSOC)
                : $pdo->query("SELECT current_database() AS db, current_setting('server_version') AS version, inet_server_port() AS port, host(inet_server_addr()) AS host")->fetch(\PDO::FETCH_ASSOC);
            if ($row['db'] !== $profile['database'] || $row['host'] !== $profile['host'] || $row['port'] !== $profile['port']
                || $row['version'] !== ($profile['driver'] === 'mysql' ? '11.4.13-MariaDB' : '17.11')) { return false; }
        }
        if ($artifactRoot !== null) {
            if ($native === [] || PHP_OS_FAMILY !== 'Windows') { return false; }
            $expected = str_replace('\\', '/', dirname(base_path()).'/artifacts/learning-native/tests');
            $path = str_replace('\\', '/', $artifactRoot);
            if (preg_match('~\A'.preg_quote($expected, '~').'/[a-f0-9]{32}\z~D', $path) !== 1 || ! is_dir($path)) { return false; }
            // Reject aliases, traversal and every existing symlink/junction component.
            for ($component = $path; strlen($component) > 3; $component = dirname($component)) {
                if (is_link($component) || str_replace('\\', '/', (string) realpath($component)) !== $component) { return false; }
            }
        }
        return true;
    }
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

            return $this->observeOwners(
                (int) $characterization->user_id,
                fn () => Characterization::query()->whereKey($characterizationId)->first(),
                fn () => $operation($characterization),
            );
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

            return $this->observeOwners(
                $userId,
                fn () => Characterization::query()->where('user_id', $userId)->first(),
                fn () => $operation($characterization, $user),
            );
        }, 3);
    }
    /** Validate the inner owner's witness after the last outer-owner write. */
    private function observeOwners(int $actor, Closure $lookup, Closure $operation): mixed
    {
        $p5Enabled = LearningP5SourceRevisionClock::enabled();
        $p6Enabled = LearningP6BaseSourceRevisionClock::enabled();
        $p8Enabled = LearningP8SourceRevisionClock::enabled();
        $p9Enabled = LearningP9SourceRevisionClock::enabled();
        if (! $p5Enabled && ! $p6Enabled && ! $p8Enabled && ! $p9Enabled) { return $operation(); }
        $p6 = new LearningP6BaseSourceRevisionClock;
        $ownsP6 = $p6Enabled && ! $p6->hasActiveScope($actor);
        $witness = null;
        $p8 = new LearningP8SourceRevisionClock;
        $ownsP8 = $p8Enabled && ! $p8->hasActiveScope($actor);
        $witnessP8 = null;
        $apply = $operation;
        $p9 = new LearningP9SourceRevisionClock;
        $ownsP9 = $p9Enabled && ! $p9->hasActiveScope($actor);
        $witnessP9 = null;
        if ($p9Enabled) {
            $apply = function () use ($p9, $actor, $lookup, $operation, $ownsP9, &$witnessP9): mixed {
                $result = $p9->observe($actor, $lookup, $operation);
                if ($ownsP9) { $witnessP9 = $p9->finalizationWitness($lookup); }
                return $result;
            };
        }
        if ($p8Enabled) {
            $inner = $apply;
            $apply = function () use ($p8, $actor, $lookup, $inner, $ownsP8, &$witnessP8): mixed {
                $result = $p8->observe($actor, $lookup, $inner);
                if ($ownsP8) { $witnessP8 = $p8->finalizationWitness($lookup); }
                return $result;
            };
        }
        if ($p6Enabled) {
            $inner = $apply;
            $apply = function () use ($p6, $actor, $lookup, $inner, $ownsP6, &$witness): mixed {
                $result = $p6->observe($actor, $lookup, $inner);
                if ($ownsP6) { $witness = $p6->finalizationWitness($lookup); }
                return $result;
            };
        }
        $result = $p5Enabled
            ? (new LearningP5SourceRevisionClock)->observe($actor, $lookup, $apply)
            : $apply();
        // An active participant cannot escape final validation by switching modes.
        if (LearningP5SourceRevisionClock::enabled() !== $p5Enabled
            || LearningP6BaseSourceRevisionClock::enabled() !== $p6Enabled
            || LearningP8SourceRevisionClock::enabled() !== $p8Enabled
            || LearningP9SourceRevisionClock::enabled() !== $p9Enabled) {
            throw new \DomainException('learning_source_clock.mode_changed');
        }
        if ($ownsP6 && $p6->finalizationWitness($lookup) !== $witness) {
            throw new \DomainException('learning_p6_base_clock.finalization_mismatch');
        }
        if ($ownsP8 && $p8->finalizationWitness($lookup) !== $witnessP8) {
            throw new \DomainException('learning_p8_clock.finalization_mismatch');
        }
        if ($ownsP9 && $p9->finalizationWitness($lookup) !== $witnessP9) {
            throw new \DomainException('learning_p9_clock.finalization_mismatch');
        }
        return $result;
    }
}
