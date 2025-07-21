<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegisterController;

// ✅ No need to override Sanctum’s built-in CSRF route
// ❌ Remove this:
// Route::get('/sanctum/csrf-cookie', function () {
//     return response()->noContent();
// });

// ✅ Public GET routes that don’t need CSRF protection
Route::get('/api/companies', [CompanyController::class, 'index'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
]);

Route::get('/api/roles', [RoleController::class, 'index'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
]);

// ✅ Protected routes for POST (with CSRF + Sanctum stateful)
Route::middleware(['web'])->group(function () {
    Route::post('/api/register', [AuthController::class, 'register']);
    Route::post('/api/roles', [RoleController::class, 'store']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
