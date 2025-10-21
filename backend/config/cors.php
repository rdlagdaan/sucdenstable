<?php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/*'],
    'allowed_methods' => ['*'],

    // Allow both localhost and your LAN IP while developing on the network
    'allowed_origins' => [
        'http://localhost:3001',
        'http://192.168.3.105:3001',
        // Optional: backend direct, only if you actually browse :8686 in a browser
        'http://192.168.3.105:8686',
    ],

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,

    // We use Bearer tokens (not cookies), so keep this false
    'supports_credentials' => false,
];
