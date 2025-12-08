<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class LogUnknownCookies {
  public function handle(Request $request, Closure $next) {
    $resp = $next($request);
    $allowed = ["XSRF-TOKEN", config("session.cookie")];
    $names = [];
    foreach ($resp->headers->getCookies() as $c) {
      $n = $c->getName();
      if (!in_array($n, $allowed, true) && !str_starts_with($n, "remember_web_")) {
        $names[] = $n;
      }
    }
    if ($names) \Log::warning("Unknown Set-Cookie", ["names"=>$names, "path"=>$request->path()]);
    return $resp;
  }
}
