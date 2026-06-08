<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'characterization' => [
        'driver' => env('CHARACTERIZATION_GATEWAY', 'mock'),
        'mock_outcome' => env('CHARACTERIZATION_MOCK_OUTCOME', 'success'),
        'prediction_mapping_path' => env(
            'CHARACTERIZATION_PREDICTION_MAPPING_PATH',
            base_path('data/ar16_to_python_esrs_mapping.json')
        ),
        'defaults' => [
            'company_name' => env('CHARACTERIZATION_DEFAULT_COMPANY_NAME', 'Unknown company'),
            'headquarters_country' => env('CHARACTERIZATION_DEFAULT_HEADQUARTERS_COUNTRY', 'Spain'),
            'reporting_currency' => env('CHARACTERIZATION_DEFAULT_REPORTING_CURRENCY', 'EUR'),
        ],
        'api' => [
            'base_url' => env('CHARACTERIZATION_API_BASE_URL'),
            'token' => env('CHARACTERIZATION_API_TOKEN'),
            'timeout' => env('CHARACTERIZATION_API_TIMEOUT', 30),
        ],
    ],

    'esrs_datapoints' => [
        'matter_dr_mapping_path' => env('ESRS_MATTER_DR_MAPPING_PATH'),
    ],

];
