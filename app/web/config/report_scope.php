<?php

return [
    'enabled' => env('REPORT_SCOPE_INTEGRITY_ENABLED', false),
    // Operator-owned input; never accepted from a request or stored in a manifest.
    'corpus_path' => base_path('data/esrs_datapoints_ig3.json'),
];
