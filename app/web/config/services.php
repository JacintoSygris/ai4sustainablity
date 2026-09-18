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

    'social_login' => [
        'enabled' => env('SOCIAL_LOGIN_ENABLED', false),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
        'include_tenant_info' => true,
        'include_avatar' => true,
    ],

    'characterization' => [
        'driver' => env('CHARACTERIZATION_GATEWAY', 'mock'),
        'mock_outcome' => env('CHARACTERIZATION_MOCK_OUTCOME', 'success'),
        'prediction_mapping_path' => env('CHARACTERIZATION_PREDICTION_MAPPING_PATH')
            ?: (str_starts_with((string) env('CHARACTERIZATION_AI_MODEL_PROFILE', ''), 'new_format_732_v1_')
                ? base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json')
                : base_path('data/ar16_to_python_esrs_mapping.json')),
        'defaults' => [
            'company_name' => env('CHARACTERIZATION_DEFAULT_COMPANY_NAME', 'Unknown company'),
            'headquarters_country' => env('CHARACTERIZATION_DEFAULT_HEADQUARTERS_COUNTRY', 'Spain'),
            'reporting_currency' => env('CHARACTERIZATION_DEFAULT_REPORTING_CURRENCY', 'EUR'),
        ],
        'api' => [
            'base_url' => env('CHARACTERIZATION_API_BASE_URL'),
            'token' => env('CHARACTERIZATION_API_TOKEN'),
            'timeout' => env('CHARACTERIZATION_API_TIMEOUT', 30),
            'model_profile' => env('CHARACTERIZATION_AI_MODEL_PROFILE'),
        ],
    ],

    'p6_document_upload' => [
        'enabled' => (bool) env('P6_DOCUMENT_UPLOAD_ENABLED', false),
        'extract_timeout' => env('P6_DOCUMENT_EXTRACT_TIMEOUT', 240),
        'job_timeout' => env('P6_DOCUMENT_EXTRACT_JOB_TIMEOUT', 300),
        // Fail-closed virus scan for public uploads (ClamAV). The configured
        // binary must be available in the web runtime when scanning is enabled.
        'scan' => [
            'enabled' => (bool) env('P6_DOCUMENT_SCAN_ENABLED', false),
            'binary' => env('P6_DOCUMENT_SCAN_BINARY', 'clamdscan'),
        ],
    ],

    // Public-launch auth hardening. Both guards FAIL-OPEN when unconfigured so
    // local/CI stay green; the production env turns them on (see
    // app/web/.env.production.example). Verification enforcement is a separate
    // flag applied to wizard/API routes via the `verified.optional` middleware.
    'auth_hardening' => [
        'require_email_verification' => (bool) env('AUTH_REQUIRE_EMAIL_VERIFICATION', false),
        'turnstile' => [
            // Cloudflare Turnstile. When site_key+secret are absent the guard is
            // skipped (dev/local). Set both in production to enforce.
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret' => env('TURNSTILE_SECRET'),
            'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        ],
        // Honeypot: a field bots fill and humans never see; must be empty.
        'honeypot_field' => env('AUTH_HONEYPOT_FIELD', 'company_website'),
    ],

    'esrs_datapoints' => [
        'matter_dr_mapping_path' => env('ESRS_MATTER_DR_MAPPING_PATH'),
    ],

    'report' => [
        'external_taxonomy_manifest_path' => env('ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH'),
        'arelle_command' => env('ESRS_ARELLE_COMMAND'),
    ],

];
