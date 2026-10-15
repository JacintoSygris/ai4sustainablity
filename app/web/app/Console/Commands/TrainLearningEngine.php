<?php

namespace App\Console\Commands;

use App\Jobs\TrainLearningFeedback;
use App\Services\ConsentLearningAuthorizationAuthority;
use Illuminate\Console\Command;

final class TrainLearningEngine extends Command
{
    protected $signature = 'learning:train';
    protected $description = 'Queue a consented feedback candidate; never promote it';

    public function handle(): int
    {
        if (! ConsentLearningAuthorizationAuthority::enabled()) { $this->error('learning.disabled'); return self::FAILURE; }
        TrainLearningFeedback::dispatch()->onQueue('learning');
        $this->info('learning.queued');
        return self::SUCCESS;
    }
}
