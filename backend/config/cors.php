<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],

    // Read from env so dev/prod can differ without code changes
    'allowed_origins' => array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))
    ),

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,

    // You’re using Bearer tokens; keep false.
    // If later you move to Sanctum cookies, set this to true.
    'supports_credentials' => false,
];
