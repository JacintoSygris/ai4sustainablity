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
        'ai_worker_timeout' => env('P6_AI_WORKER_TIMEOUT_SECONDS', 180),
        'extract_timeout' => env('P6_DOCUMENT_EXTRACT_TIMEOUT', 240),
        'job_timeout' => env('P6_DOCUMENT_EXTRACT_JOB_TIMEOUT', 300),
        'max_documents' => env('P6_DOCUMENT_MAX_DOCUMENTS', 5),
        'max_total_bytes' => env('P6_DOCUMENT_MAX_TOTAL_BYTES', 262144000),
        'max_global_bytes' => env('P6_DOCUMENT_MAX_GLOBAL_BYTES', 5368709120),
        'max_global_documents' => env('P6_DOCUMENT_MAX_GLOBAL_DOCUMENTS', 1000),
        // Fail-closed virus scan for public uploads (ClamAV). Default OFF so
        // local/CI pass; turn ON in production with clamav installed on the VPS.
        'scan' => [
            'enabled' => (bool) env('P6_DOCUMENT_SCAN_ENABLED', false),
            'binary' => env('P6_DOCUMENT_SCAN_BINARY', 'clamscan'),
            'timeout_seconds' => env('P6_DOCUMENT_SCAN_TIMEOUT_SECONDS', 30),
            'max_concurrent' => env('P6_DOCUMENT_SCAN_MAX_CONCURRENT', 2),
        ],
    ],

    // Public-launch auth hardening. Production registration stays unavailable
    // until explicitly enabled and every prerequisite passes a fail-closed check.
    'auth_hardening' => [
        'public_registration_enabled' => (bool) env('AUTH_PUBLIC_REGISTRATION_ENABLED', false),
        'password_reset_enabled' => (bool) env('AUTH_PASSWORD_RESET_ENABLED', false),
        'require_email_verification' => (bool) env('AUTH_REQUIRE_EMAIL_VERIFICATION', false),
        'turnstile' => [
            // Cloudflare Turnstile. When site_key+secret are absent the guard is
            // skipped (dev/local). Set both in production to enforce.
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret' => env('TURNSTILE_SECRET'),
            'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            'expected_hostname' => env('TURNSTILE_EXPECTED_HOSTNAME'),
            'expected_action' => env('TURNSTILE_EXPECTED_ACTION', 'register'),
        ],
        // Honeypot: a field bots fill and humans never see; must be empty.
        'honeypot_field' => env('AUTH_HONEYPOT_FIELD', 'company_website'),
    ],

    'private_dev' => [
        'auto_login' => env('PRIVATE_DEV_AUTO_LOGIN', false),
        'user_email' => env('PRIVATE_DEV_USER_EMAIL', 'i4sdev@i4s.local'),
        'user_name' => env('PRIVATE_DEV_USER_NAME', 'I4S Dev'),
    ],

    'esrs_datapoints' => [
        'matter_dr_mapping_path' => env('ESRS_MATTER_DR_MAPPING_PATH'),
    ],

    'report' => [
        'generic_xbrl_root' => env('XBRL_GENERIC_TAXONOMY_ROOT'),
        'external_taxonomy_manifest_path' => env('ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH'),
        'arelle_command' => env('ESRS_ARELLE_COMMAND'),
    ],

];
