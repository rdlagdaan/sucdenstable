<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    | Include both host and host:port used by the SPA (3001).
    */
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS',
        implode(',', [
            'localhost', 'localhost:3001',
            '10.63.10.65', '10.63.10.65:3001',
        ])
    )),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    | ✅ IMPORTANT: use *your* VerifyCsrfToken so the same behavior (and any
    |    exceptions you keep) is applied consistently for stateful requests.
    */
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => App\Http\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => App\Http\Middleware\VerifyCsrfToken::class,
    ],
];
