<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;


class Authenticate extends Middleware
{
    protected function redirectTo($request): ?string
    {
        // Never redirect unauthenticated requests; let the handler return 401.
        return null;
    }
}
