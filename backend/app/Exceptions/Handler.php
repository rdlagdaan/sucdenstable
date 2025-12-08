<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        //
    }

    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $e)
    {
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

}
