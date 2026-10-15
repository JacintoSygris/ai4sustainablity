<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LearningQueueHeartbeat implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 120;
    public int $tries = 1;

    public function handle(): void
    {
        if (Schema::hasTable('learning_heartbeats') && config('queue.default') !== 'sync') {
            DB::table('learning_heartbeats')->updateOrInsert(['component' => 'queue'], ['seen_at' => now()]);
        }
    }
}
