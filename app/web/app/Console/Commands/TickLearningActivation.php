<?php

namespace App\Console\Commands;

use App\Jobs\LearningQueueHeartbeat;
use App\Jobs\RunLearningAdminAction;
use App\Services\ConsentLearningAuthorizationAuthority;
use App\Services\LearningActivationStatus;
use App\Services\LearningBanditFeedback;
use App\Services\LearningDataLifecycle;
use App\Services\LearningPythonBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TickLearningActivation extends Command
{
    protected $signature = 'learning:tick';
    protected $description = 'Check scheduler/worker liveness, reconcile privacy and queue manual-promotion candidates';

    public function handle(LearningDataLifecycle $lifecycle, LearningPythonBridge $bridge): int
    {
        if (! LearningActivationStatus::migrated()) { $this->warn('learning.migrations_missing'); return self::FAILURE; }
        DB::table('learning_heartbeats')->updateOrInsert(['component' => 'scheduler'], ['seen_at' => now()]);
        try {
            if (config('queue.default') !== 'sync') { LearningQueueHeartbeat::dispatch()->onQueue('learning'); }
            $lifecycle->refreshAuthority();
            if (is_file(config('learning_engine.root').'/bandit-catalogue.json')) { $bridge->run('bandit-maintain'); }
            foreach (glob(config('learning_engine.root').'/bandit-dataset-*.json') ?: [] as $path) {
                if (! is_link($path) && filemtime($path) < time() - 1200) { unlink($path); }
            }
            DB::table('learning_bandit_events')->where('created_at', '<', now()->subDays(30))->delete();
            DB::table('learning_admin_actions')->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(25))
                ->update(['status' => 'failed_closed', 'error_code' => 'learning.worker_timeout', 'updated_at' => now()]);
            DB::table('learning_admin_actions')->where('updated_at', '<', now()->subDays(30))->delete();
            if (! ConsentLearningAuthorizationAuthority::enabled() || ! config('learning_engine.scheduled') || config('queue.default') === 'sync') { return self::SUCCESS; }
            foreach (['wp13' => 'train', 'wp23' => 'process'] as $loop => $action) {
                if ($loop === 'wp23' && ! LearningBanditFeedback::enabled()) { continue; }
                $since = $loop === 'wp13' ? now()->subDay() : now()->subMinutes(5);
                if (DB::table('learning_admin_actions')->where('loop', $loop)->where('action', $action)->where('created_at', '>', $since)->exists()) { continue; }
                $id = (string) Str::uuid();
                DB::table('learning_admin_actions')->insert(['id' => $id, 'loop' => $loop, 'action' => $action,
                    'reason' => 'scheduled', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                RunLearningAdminAction::dispatch($id)->onQueue('learning');
            }
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('learning.tick_failed_closed');
            return self::FAILURE;
        }
    }
}
