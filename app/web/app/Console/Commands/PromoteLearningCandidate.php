<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ConsentLearningAuthorizationAuthority;
use App\Services\LearningDataLifecycle;
use App\Services\LearningPythonBridge;
use Illuminate\Console\Command;

final class PromoteLearningCandidate extends Command
{
    protected $signature = 'learning:promote {candidate} {--admin=}';
    protected $description = 'Re-evaluate fixed sets and explicitly promote an eligible candidate';

    public function handle(LearningPythonBridge $bridge, LearningDataLifecycle $lifecycle): int
    {
        if (! ConsentLearningAuthorizationAuthority::enabled()
            || ! in_array((string) $this->option('admin'), config('learning_engine.admin_ids'), true)
            || ! User::whereKey($this->option('admin'))->whereNotNull('email_verified_at')->exists()
            || ! preg_match('/^[a-f0-9]{32}$/D', (string) $this->argument('candidate'))) {
            $this->error('learning.admin_or_mode_denied'); return self::FAILURE;
        }
        $lifecycle->refreshAuthority();
        $bridge->run('promote', array_merge($bridge->fixedArguments(), [
            'candidate' => $this->argument('candidate'), 'actor' => 'admin:'.$this->option('admin'),
        ]));
        $this->info('learning.promoted');
        return self::SUCCESS;
    }
}
