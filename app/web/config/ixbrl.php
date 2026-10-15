<?php

return [
    'package_ui_enabled' => env('IXBRL_PACKAGE_UI_ENABLED', false),
    'taxonomy_profile' => env('IXBRL_TAXONOMY_PROFILE', 'esrs_set1_2023-12-22'),
    'profiles' => [
        'esrs_set1_2023-12-22' => [
            'status' => 'final',
            'enabled' => true,
            'namespace' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22',
            'entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
            'core_schema' => 'xbrl.efrag.org/taxonomy/esrs/2023-12-22/common/esrs_cor.xsd',
            'mapping_profile_id' => 'esrs-2023-preparatory-v1',
            'concept_mapping' => 'data/atomizer_xbrl_concepts_v1.json',
        ],
        'esrs_2026_draft_2026-08-30' => [
            'status' => 'provisional',
            'enabled' => env('IXBRL_2026_DRAFT_ENABLED', false),
            'namespace' => 'https://xbrl.efrag.org/taxonomy/esrs/2026-08-30',
            'entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2026-08-30/esrs.xsd',
            'core_schema' => null,
            'mapping_profile_id' => null,
            'concept_mapping' => null,
        ],
    ],
];
