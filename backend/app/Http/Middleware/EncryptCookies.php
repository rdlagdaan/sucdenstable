<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Symfony\Component\HttpFoundation\Response;

class EncryptCookies extends BaseEncryptCookies
{
    /**
     * Cookies that should NOT be encrypted.
     */
    protected $except = [
        'XSRF-TOKEN',
        'sucden_session', // optional; not required for CSRF, but fine to keep
    ];

    /**
     * TEMP: add a fingerprint header so we can see this middleware ran.
     */
    public function handle($request, Closure $next): Response
    {
        /** @var Response $response */
        $response = parent::handle($request, $next);
        $response->headers->set('X-EncryptCookies', 'app'); // debug only
        return $response;
    }
}
