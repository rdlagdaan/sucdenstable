<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class KeepOnlyKnownCookies
{
    /**
     * Only these cookies are always allowed.
     * (Add more if you truly need them.)
     */
    private array $allow = [
        'XSRF-TOKEN',
        'sucden_session',
        // 'remember_web_xxx',  // example if you ever need it
    ];

    /**
     * Returns true if the cookie name is allow-listed.
     */
    private function isAllowed(string $name): bool
    {
        return in_array($name, $this->allow, true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Let the app generate its response first.
        /** @var Response $response */
        $response = $next($request);

        // Collect Set-Cookie headers the app wants to send.
        $setCookies = $response->headers->getCookies();

        // Separate known vs unknown cookies from the *response*.
        $knownByName = [];
        $unknown = [];
        foreach ($setCookies as $c) {
            $n = $c->getName();
            if ($this->isAllowed($n)) {
                // keep the latest of each known cookie name
                $knownByName[$n] = $c;
            } else {
                // collect unknown cookies in order they were set
                $unknown[] = $c;
            }
        }

        // Policy for unknown cookies:
        // - keep at most ONE (the last one set this response),
        // - delete every other unknown cookie that the browser previously sent.
        $keptUnknown = count($unknown) ? end($unknown) : null;

        // Replace all Set-Cookie headers with: known + keptUnknown + deletions
        $response->headers->remove('Set-Cookie');

        // 1) Re-attach known cookies (deduped by name)
        foreach ($knownByName as $c) {
            $response->headers->setCookie($c);
        }

        // 2) Keep at most one unknown cookie (if any)
        if ($keptUnknown) {
            $response->headers->setCookie($keptUnknown);
        }

        // 3) For every unknown cookie that came from the browser this request
        //    (not allow-listed and NOT the one we decided to keep),
        //    send an expired Set-Cookie to remove it client-side.
        foreach ($request->cookies->all() as $reqName => $reqValue) {
            if ($this->isAllowed($reqName)) {
                continue;
            }
            if ($keptUnknown && $reqName === $keptUnknown->getName()) {
                continue; // keep the one we're using now
            }

            // Expire it on the browser (name only; domain/path defaults are fine)
            $response->headers->setCookie(
                new Cookie(
                    $reqName,      // name
                    null,          // value
                    1,             // expire in the past (Unix timestamp)
                    '/',           // path
                    null,          // domain (let browser match)
                    false,         // secure (unchanged)
                    false,         // httpOnly (unchanged)
                    false,         // raw
                    'lax'          // sameSite (unchanged)
                )
            );
        }

        // Optional: debug fingerprint (comment out once stable)
        // $response->headers->set('X-Cookie-Guard', 'ok');

        return $response;
    }
}
