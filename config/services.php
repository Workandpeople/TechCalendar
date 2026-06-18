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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => env('OPENAI_TIMEOUT', 75),
        'connect_timeout' => env('OPENAI_CONNECT_TIMEOUT', 10),
        'import_chunk_size' => env('OPENAI_IMPORT_CHUNK_SIZE', 10),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'service_account_path' => env('FIREBASE_SERVICE_ACCOUNT_PATH'),
        'service_account_json' => env('FIREBASE_SERVICE_ACCOUNT_JSON'),
    ],

    'coffrac' => [
        'api_url' => env('COFFRAC_API_URL'),
        'api_token' => env('COFFRAC_API_TOKEN'),
        'timeout' => env('COFFRAC_API_TIMEOUT', 15),
        'connect_timeout' => env('COFFRAC_API_CONNECT_TIMEOUT', 5),
    ],

];
