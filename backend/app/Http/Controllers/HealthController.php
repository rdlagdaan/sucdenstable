<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'ok'      => true,
            'app'     => config('app.name'),
            'env'     => config('app.env'),
            'version' => \Illuminate\Foundation\Application::VERSION,
            'time'    => now()->toIso8601String(),
        ], 200);
    }
}
