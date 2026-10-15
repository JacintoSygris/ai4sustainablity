<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LearningActivationStatus
{
    public static function isAdmin(?User $user): bool
    {
        return $user !== null && $user->hasVerifiedEmail()
            && in_array((string) $user->id, config('learning_activation.admin_ids', []), true);
    }

    public static function migrated(): bool
    {
        foreach (['learning_bandit_events', 'learning_admin_actions', 'learning_heartbeats', 'learning_engine_runs', 'learning_erasure_requests', 'learning_company_memberships', 'learning_company_groups'] as $table) {
            if (! Schema::hasTable($table)) { return false; }
        }
        return Schema::hasColumns('users', ['learning_consent_version', 'learning_consent_at', 'learning_subject', 'learning_synthetic']);
    }

    public function snapshot(?string $candidate = null): array
    {
        $checks = ['migrations' => self::migrated(), 'state_directory' => false, 'python' => false,
            'scheduler' => false, 'queue' => false, 'queue_configuration' => false, 'authority_lease' => false, 'heldout' => false, 'code_commit' => false];
        $root = config('learning_engine.root');
        $checks['state_directory'] = is_string($root) && str_starts_with($root, DIRECTORY_SEPARATOR)
            && is_dir($root) && realpath($root) === $root && ! is_link($root) && is_writable($root);
        $connection = config('queue.connections.'.config('queue.default'), []);
        $checks['queue_configuration'] = in_array($connection['driver'] ?? null, ['database', 'redis', 'beanstalkd'], true)
            && ($connection['retry_after'] ?? 0) > 1200 && config('cache.default') !== 'array';
        $python = config('learning_engine.python');
        $remote = null;
        if ($checks['state_directory'] && is_string($python) && is_executable($python)) {
            try { $remote = app(LearningPythonBridge::class)->run('status', $candidate === null ? [] : ['candidate' => $candidate]); $checks['python'] = true; }
            catch (\Throwable) { /* Expose only a closed prerequisite, never private exception values. */ }
        }
        if ($candidate !== null) {
            abort_if($remote === null, 503);
            abort_unless(($remote['candidate_found'] ?? false) === true, 404);
        }
        $checks['authority_lease'] = ($remote['authority_lease'] ?? false) === true;
        $heldout = config('learning_engine.heldout_path');
        $checks['heldout'] = is_string($heldout) && is_file($heldout) && ! is_link($heldout)
            && hash_file('sha256', $heldout) === config('learning_engine.heldout_sha256');
        if (config('learning_engine.gate_metrics_enabled')) {
            try { app(LearningReservedGroups::class)->snapshot(); }
            catch (\Throwable) { $checks['heldout'] = false; }
        }
        $checks['code_commit'] = preg_match('/^[a-f0-9]{40}$/D', (string) config('learning_engine.code_commit')) === 1;
        $consented = $pending = $processed = 0;
        $runs = $actions = [];
        if ($checks['migrations']) {
            foreach (['scheduler', 'queue'] as $component) {
                $checks[$component] = DB::table('learning_heartbeats')->where('component', $component)
                    ->where('seen_at', '>', now()->subMinutes($component === 'queue' ? 20 : 3))->exists();
            }
            $checks['queue'] = $checks['queue'] && config('queue.default') !== 'sync';
            $consented = User::whereNotNull('learning_consent_at')->whereNotNull('email_verified_at')
                ->where('learning_consent_version', config('learning_engine.consent_version'))->count();
            $pending = DB::table('learning_bandit_events')->whereNull('processed_at')->count();
            $processed = DB::table('learning_bandit_events')->whereNotNull('processed_at')->count();
            $runs = DB::table('learning_engine_runs')->orderByDesc('created_at')->limit(20)->get(['id', 'status', 'candidate_id', 'created_at', 'updated_at'])->all();
            $actions = DB::table('learning_admin_actions')->orderByDesc('created_at')->limit(30)->get()->all();
        }
        return ['gate_metrics_enabled' => (bool) config('learning_engine.gate_metrics_enabled'), 'checks' => $checks, 'engine_enabled' => ConsentLearningAuthorizationAuthority::enabled(),
            'synthetic_only' => (bool) config('learning_engine.synthetic_only'), 'scheduled' => (bool) config('learning_engine.scheduled'),
            'bandit_mode' => config('learning_activation.bandit_mode'), 'consented_users' => $consented,
            'pending_events' => $pending, 'processed_events' => $processed, 'runs' => $runs, 'actions' => $actions,
            'wp13' => $remote['wp13'] ?? null, 'wp23' => $remote['wp23'] ?? null];
    }

    public function ready(array $state, string $loop, string $action): bool
    {
        $required = ['migrations', 'state_directory', 'python', 'queue', 'queue_configuration'];
        if ($action !== 'rollback') { $required = [...$required, 'scheduler', 'authority_lease']; }
        if ($loop === 'wp13' && in_array($action, ['train', 'promote'], true)) { $required = [...$required, 'heldout', 'code_commit']; }
        foreach ($required as $key) { if (($state['checks'][$key] ?? false) !== true) { return false; } }
        return $action === 'rollback' || ($state['engine_enabled'] && ($loop !== 'wp23' || in_array($state['bandit_mode'], ['active', 'shadow'], true)));
    }
}
