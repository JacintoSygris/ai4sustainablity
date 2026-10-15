<?php

return [
    'enabled' => (bool) env('USABILITY_STUDY_ENABLED', false),
    // Server-controlled allowlist; never accepted from a request or mass assignment.
    'admin_user_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => ctype_digit(trim($id)) ? (int) trim($id) : 0,
        explode(',', (string) env('USABILITY_STUDY_ADMIN_USER_IDS', '')),
    ))),
    'consent_version' => '2026-10-08.1',
];
