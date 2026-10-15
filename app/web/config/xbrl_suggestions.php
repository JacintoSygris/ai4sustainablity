<?php

return [
    'runtime_enabled' => env('XBRL_RUNTIME_IDENTIFICATION_ENABLED', false),
    // Operator-provisioned private manifest/catalog. No automatic model replacement.
    'runtime_manifest_path' => env('XBRL_RUNTIME_MANIFEST_PATH'),
    'runtime_catalog_path' => env('XBRL_RUNTIME_CATALOG_PATH'),
    'overlay_enabled' => env('XBRL_SUGGESTION_OVERLAY_ENABLED', false),
    'suggestions_path' => base_path('data/xbrl_concept_suggestions_v1.json'),
    // Private operator audit; never commit decisions or reviewer identities.
    'overlay_path' => storage_path('app/private/xbrl-concept-decisions.json'),
];
