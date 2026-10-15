<?php

$deployed = (bool) env('LEARNING_DEPLOYED', in_array(env('APP_ENV', 'local'), ['production', 'staging'], true));

return [
    // Gate metrics are opt-in even in deployed environments.
    'gate_metrics_enabled' => (bool) env('LEARNING_GATE_METRICS_ENABLED', false),
    'serving_runtime' => env('LEARNING_SERVING_RUNTIME', 'public'),
    'gate_policy' => [
        'min_companies' => env('LEARNING_GATE_MIN_COMPANIES', 30),
        'min_positives' => env('LEARNING_GATE_MIN_POSITIVES', 30),
        'min_negatives' => env('LEARNING_GATE_MIN_NEGATIVES', 30),
        'precision_delta' => env('LEARNING_GATE_PRECISION_DELTA', 0.01),
        'replicates' => env('LEARNING_GATE_REPLICATES', 2000),
        'confidence' => env('LEARNING_GATE_CONFIDENCE', 0.95),
        'seed' => env('LEARNING_GATE_SEED', 17),
    ],
    'enabled' => (bool) env('LEARNING_ENGINE_ENABLED', $deployed),
    'authority' => env('LEARNING_ENGINE_AUTHORITY', $deployed ? 'consent' : 'deny'),
    'synthetic_only' => (bool) env('LEARNING_ENGINE_SYNTHETIC_ONLY', ! $deployed),
    'serving_enabled' => (bool) env('LEARNING_ENGINE_SERVING_ENABLED', $deployed),
    'scheduled' => (bool) env('LEARNING_ENGINE_SCHEDULED', $deployed),
    'consent_version' => 'learning-feedback-bandit-2026-10-08',
    'root' => env('LEARNING_ENGINE_ROOT', storage_path('app/private/learning-engine')),
    'source' => env('LEARNING_ENGINE_SOURCE', dirname(base_path()).'/ai-service/src'),
    'python' => env('LEARNING_ENGINE_PYTHON', dirname(base_path()).'/ai-service/.venv-learning/bin/python'),
    // Preserve the qualified launcher's lexical path (also used by container
    // wrappers). Never silently switch to a different Python/dependency set.
    'synthetic_python' => env('LEARNING_ENGINE_PYTHON',
        dirname(base_path()).'/ai-service/.venv-learning/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python')),
    'code_commit' => env('LEARNING_ENGINE_CODE_COMMIT', ''),
    'heldout_path' => env('LEARNING_ENGINE_HELDOUT_PATH', ''),
    'heldout_sha256' => env('LEARNING_ENGINE_HELDOUT_SHA256', ''),
    'gold_path' => env('LEARNING_ENGINE_GOLD_PATH', ''),
    'gold_sha256' => env('LEARNING_ENGINE_GOLD_SHA256', ''),
    'admin_ids' => array_values(array_filter(explode(',', (string) env('LEARNING_ENGINE_ADMIN_IDS', '')))),
    'max_rows' => 5000,
    'timeout' => 900,
];
