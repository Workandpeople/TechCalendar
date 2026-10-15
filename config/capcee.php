<?php

return [
    'enabled' => (bool) env('CAPCEE_ENABLED', false),
    'environment' => env('CAPCEE_ENVIRONMENT', 'uat2'),
    // The attached contract specifies client_credentials, despite its JWT heading.
    'client_id' => env('CAPCEE_CLIENT_ID'),
    'client_secret' => env('CAPCEE_CLIENT_SECRET'),
    'api_version' => 'v58.0',
    'timeout' => 30,
    'upload_timeout' => 90,
    'connect_timeout' => 5,
    'token_cache_seconds' => 300,
    // Persistent queues only: never upload reports inside the web request.
    'queue_connection' => env('CAPCEE_QUEUE_CONNECTION', 'database'),
    'queue' => env('CAPCEE_QUEUE', 'default'),
];
