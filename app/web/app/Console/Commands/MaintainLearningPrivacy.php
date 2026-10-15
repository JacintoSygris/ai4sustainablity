<?php

namespace App\Console\Commands;

use App\Services\LearningDataLifecycle;
use App\Services\LearningPythonBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MaintainLearningPrivacy extends Command
{
    protected $signature = 'learning:maintain-privacy';
    protected $description = 'Erase withdrawn/expired candidates and renew the short serving consent lease';

    public function handle(LearningPythonBridge $bridge, LearningDataLifecycle $lifecycle): int
    {
        if (! Schema::hasTable('learning_erasure_requests')) { return self::SUCCESS; }
        $lifecycle->refreshAuthority();
        $root = config('learning_engine.root');
        foreach (DB::table('learning_erasure_requests')->orderBy('created_at')->cursor() as $request) {
            // Always write a registry tombstone, even if training is concurrent
            // and has not registered its first candidate yet.
            $bridge->run('erase', ['subject' => $request->subject]);
            DB::table('learning_erasure_requests')->where('subject', $request->subject)->delete();
        }
        if (is_file($root.'/registry.sqlite3')) { $bridge->run('erase'); }
        // Recover exports left by killed workers only after their hard timeout.
        foreach (glob($root.'/dataset-*.json') ?: [] as $path) {
            if (! is_link($path) && filemtime($path) < time() - 1200) { unlink($path); }
        }
        DB::table('learning_engine_runs')->where('updated_at', '<', now()->subDays(30))->delete();
        return self::SUCCESS;
    }
}
