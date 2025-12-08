<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogQueuedCookies
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->is('sanctum/csrf-cookie')) {
            // Inspect queued cookies (those Laravel will turn into Set-Cookie headers)
            $jar = app('cookie'); // Illuminate\Cookie\CookieJar
            $queued = method_exists($jar, 'getQueuedCookies')
                ? $jar->getQueuedCookies()
                : [];

            $names = [];
            foreach ($queued as $cookie) {
                // $cookie is Symfony\Component\HttpFoundation\Cookie
                $names[] = $cookie->getName();
            }

            Log::info('Queued cookie names for /sanctum/csrf-cookie', [
                'names' => $names,
                // Short backtrace to locate who queued them
                'trace' => collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12))
                    ->map(fn($f) => ($f['class'] ?? '').'@'.($f['function'] ?? '').':'.($f['line'] ?? ''))
                    ->take(8)
                    ->all(),
            ]);
        }

        return $response;
    }
}
