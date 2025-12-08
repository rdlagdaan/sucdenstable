<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogSetCookies
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->is('sanctum/csrf-cookie')) {
            // Authoritative: raw Set-Cookie headers on the final response
            $raw = $response->headers->all('set-cookie');
            Log::info('Raw Set-Cookie headers on /sanctum/csrf-cookie', [
                'count' => is_array($raw) ? count($raw) : 0,
                'lines' => $raw,
            ]);
        }

        return $response;
    }
}
