<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class LearningPythonBridge
{
    public function run(string $operation, array $arguments = []): array
    {
        if (! in_array($operation, ['train', 'promote', 'rollback', 'erase', 'status', 'bandit-sync', 'bandit-promote', 'bandit-rollback', 'bandit-maintain'], true)) {
            throw new \InvalidArgumentException('learning.operation');
        }
        $root = config('learning_engine.root');
        if (! is_string($root) || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('learning.absolute_root_required');
        }
        $source = realpath(config('learning_engine.source'));
        if ($source === false) { throw new \RuntimeException('learning.source_missing'); }
        $runtime = config('learning_engine.serving_runtime');
        if ($runtime !== 'public') {
            throw new \RuntimeException('learning.serving_runtime');
        }
        // The public services alias resolves the same package as FastAPI.
        $script = in_array($operation, ['train', 'promote', 'rollback', 'erase'], true) ? 'learning_production_cli.py' : 'learning_activation_cli.py';
        $command = [config('learning_engine.python'), $source.'/'.$script, $operation, '--root', $root];
        foreach ($arguments as $name => $value) {
            if ($value !== null && $value !== '') { $command[] = '--'.$name; $command[] = (string) $value; }
        }
        // Remove inherited DB/OAuth/cloud credentials. No shell interpolation.
        $environment = array_fill_keys(array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]), false);
        foreach (['PATH', 'LANG', 'LC_ALL', 'SYSTEMROOT', 'I4S_AI_NEW_FORMAT_SCORE_THRESHOLD',
            'ESRS_CRC_RECALL_FLOOR_PATH', 'ESRS_SECTOR_THRESHOLD_GUARD_PATH', 'I4S_AI_ARTIFACT_DIR'] as $key) {
            if (getenv($key) !== false) { $environment[$key] = getenv($key); }
        }
        if (config('learning_engine.gate_metrics_enabled') && getenv('ESRS_FEEDBACK_BANDIT_PATH') !== false) {
            $environment['ESRS_FEEDBACK_BANDIT_PATH'] = getenv('ESRS_FEEDBACK_BANDIT_PATH');
        }
        $environment = array_merge($environment, [
            'PYTHONPATH' => $source, 'PYTHONDONTWRITEBYTECODE' => '1',
            'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1',
            'MLFLOW_ENABLE_ASYNC_LOGGING' => 'false', 'MLFLOW_ENABLE_TELEMETRY' => 'false',
            'LEARNING_ENGINE_ROOT' => $root,
            'LEARNING_GATE_METRICS_ENABLED' => config('learning_engine.gate_metrics_enabled') ? 'true' : 'false',
            'LEARNING_SERVING_RUNTIME' => config('learning_engine.serving_runtime'),
            'LEARNING_BANDIT_MODE' => config('learning_activation.bandit_mode'),
            'LEARNING_ENGINE_ENABLED' => config('learning_engine.enabled') ? 'true' : 'false',
            'LEARNING_ENGINE_SERVING_ENABLED' => config('learning_engine.serving_enabled') ? 'true' : 'false',
            'LEARNING_ENGINE_AUTHORITY' => config('learning_engine.authority'),
            'LEARNING_ENGINE_SYNTHETIC_ONLY' => config('learning_engine.synthetic_only') ? 'true' : 'false',
        ]);
        foreach (config('learning_engine.gate_policy', []) as $key => $value) {
            $environment['LEARNING_GATE_'.strtoupper($key)] = (string) $value;
        }
        foreach ($this->fixedArguments() as $key => $value) {
            $environment['LEARNING_GATE_'.strtoupper(str_replace('-', '_', $key))] = (string) $value;
        }
        $process = new Process($command, $source, $environment, null, $operation === 'status' ? 15 : config('learning_engine.timeout'));
        $bytes = 0;
        $process->run(function ($type, $output) use (&$bytes, $process) {
            $bytes += strlen($output);
            if ($bytes > 1048576) { $process->stop(0); throw new \RuntimeException('learning.output_limit'); }
        });
        if (! $process->isSuccessful()) { throw new \RuntimeException('learning.python_failed_closed'); }
        $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        if ($operation === 'status' && config('learning_engine.gate_metrics_enabled')) {
            if (! is_array($result) || ! is_bool($result['candidate_found'] ?? null)
                || ! is_array($result['wp13']['candidates'] ?? null)
                || ! array_is_list($result['wp13']['candidates']) || count($result['wp13']['candidates']) > 30) {
                throw new \RuntimeException('learning.invalid_status');
            }
            foreach ($result['wp13']['candidates'] as $candidate) {
                if (! is_array($candidate) || ! is_string($candidate['id'] ?? null)
                    || preg_match('/^[a-f0-9]{32}$/D', $candidate['id']) !== 1
                    || ! is_bool($candidate['eligible'] ?? null) || ! is_array($candidate['gate'] ?? null)
                    || ! is_bool($candidate['gate']['passed'] ?? null)) {
                    throw new \RuntimeException('learning.invalid_candidate');
                }
            }
        }
        return $result;
    }

    public function fixedArguments(): array
    {
        return [
            'heldout' => config('learning_engine.heldout_path'),
            'heldout-sha256' => config('learning_engine.heldout_sha256'),
            'gold' => config('learning_engine.gold_path'),
            'gold-sha256' => config('learning_engine.gold_sha256'),
        ];
    }
}
