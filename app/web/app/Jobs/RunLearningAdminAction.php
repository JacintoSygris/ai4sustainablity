<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LearningActivationStatus;
use App\Services\LearningBanditFeedback;
use App\Services\LearningDataLifecycle;
use App\Services\LearningPythonBridge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RunLearningAdminAction implements ShouldQueue
{
    use Queueable;

    // Contention can outlast one training run; execution is fenced by the durable status.
    public int $tries = 45;
    public int $timeout = 1100;

    public function __construct(public readonly string $actionId) {}

    public function handle(LearningActivationStatus $status, LearningPythonBridge $bridge, LearningDataLifecycle $lifecycle, LearningBanditFeedback $feedback): void
    {
        $lock = Cache::lock('learning-admin-action', 1150);
        if (! $lock->get()) { $this->release(30); return; }
        $path = null;
        try {
            $action = DB::table('learning_admin_actions')->where('id', $this->actionId)->first();
            if ($action === null || $action->status !== 'queued') { return; }
            DB::table('learning_admin_actions')->where('id', $action->id)->update(['status' => 'running', 'updated_at' => now()]);
            if ($action->admin_id !== null && ! LearningActivationStatus::isAdmin(User::find($action->admin_id))) { throw new \RuntimeException('learning.admin_denied'); }
            if ($action->admin_id === null && ! in_array($action->action, ['train', 'process'], true)) { throw new \RuntimeException('learning.manual_only'); }
            $lifecycle->refreshAuthority();
            $state = $status->snapshot();
            if (! $status->ready($state, $action->loop, $action->action)) { throw new \RuntimeException('learning.prerequisites'); }
            $before = $this->hash($state, $action->loop, $action->action);
            DB::table('learning_admin_actions')->where('id', $action->id)->update(['before_sha256' => $before]);
            $args = ['actor' => 'admin:'.$action->admin_id, 'candidate' => $action->candidate, 'reason' => $action->reason];
            if ($action->loop === 'wp13') {
                if ($action->action === 'train') {
                    app()->call([new TrainLearningFeedback, 'handle']);
                } elseif ($action->action === 'promote') {
                    $bridge->run('promote', array_merge($bridge->fixedArguments(), $args));
                } else {
                    $args['candidate'] = $state['engine_enabled'] ? ($state['wp13']['previous'] ?? 'base') : 'base';
                    $bridge->run('rollback', $args);
                }
            } elseif ($action->action === 'process') {
                $rows = $feedback->ledger();
                $path = config('learning_engine.root').'/bandit-dataset-'.$action->id.'.json';
                $stream = fopen($path, 'x');
                if ($stream === false) { throw new \RuntimeException('learning.export'); }
                try {
                    chmod($path, 0600);
                    $bytes = json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    if (fwrite($stream, $bytes) !== strlen($bytes)) { throw new \RuntimeException('learning.export'); }
                } finally { fclose($stream); }
                $lifecycle->refreshAuthority();
                $bridge->run('bandit-sync', ['dataset' => $path, 'digest' => hash_file('sha256', $path)]);
                foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
                    DB::table('learning_bandit_events')->whereIn('source_key', $ids)->update(['processed_at' => now()]);
                }
            } else {
                $bridge->run('bandit-'.$action->action, [...$args, 'reason' => $action->reason]);
            }
            $after = $this->hash($status->snapshot(), $action->loop, $action->action);
            DB::table('learning_admin_actions')->where('id', $action->id)->update(['status' => 'completed', 'after_sha256' => $after, 'updated_at' => now()]);
        } catch (\Throwable) {
            DB::table('learning_admin_actions')->where('id', $this->actionId)->update([
                'status' => 'failed_closed', 'error_code' => 'learning.action_failed_closed', 'updated_at' => now()]);
        } finally {
            if ($path !== null && is_file($path)) { unlink($path); }
            $lock->release();
        }
    }

    private function hash(array $state, string $loop, string $action): ?string
    {
        if ($action === 'train') { return $state['wp13']['candidates'][0]['sha256'] ?? null; }
        if ($action === 'process') { return $state['wp23']['candidate']['sha256'] ?? null; }
        return $loop === 'wp13' ? ($state['wp13']['active_sha256'] ?? null) : ($state['wp23']['active']['sha256'] ?? null);
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('learning_admin_actions')->where('id', $this->actionId)->update([
            'status' => 'failed_closed', 'error_code' => 'learning.worker_failed', 'updated_at' => now()]);
    }
}
