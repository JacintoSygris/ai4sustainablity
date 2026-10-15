<?php

namespace App\Jobs;

use App\Services\ConsentLearningAuthorizationAuthority;
use App\Services\LearningDataLifecycle;
use App\Services\LearningFeedbackDataset;
use App\Services\LearningPythonBridge;
use App\Services\LearningReservedGroups;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrainLearningFeedback implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 960;
    public int $uniqueFor = 1200;

    public function handle(LearningFeedbackDataset $dataset, LearningPythonBridge $bridge, LearningDataLifecycle $lifecycle): void
    {
        if (! ConsentLearningAuthorizationAuthority::enabled()) { return; }
        $lock = Cache::lock('learning-engine-training', 1000);
        if (! $lock->get()) { return; }
        $id = (string) Str::uuid();
        $path = null;
        try {
            DB::table('learning_engine_runs')->insert(['id' => $id, 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);
            $root = config('learning_engine.root');
            if (! is_dir($root) && ! mkdir($root, 0700, true)) { throw new \RuntimeException('learning.root'); }
            if (realpath($root) !== $root || is_link($root)) { throw new \RuntimeException('learning.root_alias'); }
            $reserved = config('learning_engine.gate_metrics_enabled') ? app(LearningReservedGroups::class)->snapshot() : null;
            $document = $dataset->export($reserved['groups'] ?? null);
            $exclusions = $dataset->reservedExclusions;
            if ($document['rows'] === []) {
                $empty = ['status' => 'no_eligible_feedback', 'updated_at' => now()];
                if ($reserved !== null) { $empty['receipt'] = json_encode(['reserved_exclusions' => $exclusions], JSON_THROW_ON_ERROR); }
                DB::table('learning_engine_runs')->where('id', $id)->update($empty);
                return;
            }
            $path = $root.'/dataset-'.$id.'.json';
            $stream = fopen($path, 'x');
            if ($stream === false) { throw new \RuntimeException('learning.export'); }
            try {
                chmod($path, 0600);
                $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (fwrite($stream, $bytes) !== strlen($bytes)) { throw new \RuntimeException('learning.export'); }
            } finally { fclose($stream); }
            $sha = hash_file('sha256', $path);
            DB::table('learning_engine_runs')->where('id', $id)->update(['dataset_sha256' => $sha]);
            $lifecycle->refreshAuthority();
            $mapping = config('services.characterization.prediction_mapping_path');
            if ($reserved !== null && app(LearningReservedGroups::class)->snapshot() !== $reserved) {
                throw new \RuntimeException('learning.reserved_changed');
            }
            $receipt = $bridge->run('train', array_merge($bridge->fixedArguments(), [
                'dataset' => $path, 'dataset-sha256' => $sha,
                'mapping' => $mapping, 'mapping-sha256' => hash_file('sha256', $mapping),
                'code-commit' => config('learning_engine.code_commit'),
            ]));
            if ($reserved !== null) { $receipt['reserved_exclusions'] = $exclusions; }
            DB::table('learning_engine_runs')->where('id', $id)->update([
                'status' => 'registered', 'candidate_id' => $receipt['candidate_id'],
                'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        } catch (\Throwable $error) {
            DB::table('learning_engine_runs')->where('id', $id)->update(['status' => 'failed_closed', 'updated_at' => now()]);
            throw new \RuntimeException('learning.training_failed_closed');
        } finally {
            if ($path !== null && is_file($path)) { unlink($path); }
            $lock->release();
        }
    }
}
