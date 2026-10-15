<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LearningDataLifecycle;
use App\Services\LearningPythonBridge;
use Illuminate\Console\Command;

final class RollbackLearningCandidate extends Command
{
    protected $signature = 'learning:rollback {candidate=base} {--admin=}';
    protected $description = 'Return to the base model or a previously promoted eligible version';

    public function handle(LearningPythonBridge $bridge, LearningDataLifecycle $lifecycle): int
    {
        if (! in_array((string) $this->option('admin'), config('learning_engine.admin_ids'), true)
            || ! User::whereKey($this->option('admin'))->whereNotNull('email_verified_at')->exists()) {
            $this->error('learning.admin_denied'); return self::FAILURE;
        }
        $lifecycle->refreshAuthority();
        $bridge->run('rollback', ['candidate' => $this->argument('candidate'), 'actor' => 'admin:'.$this->option('admin')]);
        $this->info('learning.rolled_back');
        return self::SUCCESS;
    }
}
