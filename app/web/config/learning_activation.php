<?php

$deployed = (bool) env('LEARNING_DEPLOYED', in_array(env('APP_ENV', 'local'), ['production', 'staging'], true));

return [
    'bandit_mode' => env('LEARNING_BANDIT_MODE', $deployed ? 'active' : 'off'),
    'admin_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEARNING_ADMIN_USER_IDS', env('LEARNING_ENGINE_ADMIN_IDS', '')))))),
    'retention_days' => 30,
    'max_events' => 20000,
];
