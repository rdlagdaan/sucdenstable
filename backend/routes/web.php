<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AuthController;


Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent();
});

//Route::get('/api/companies', [CompanyController::class, 'index']);
Route::get('/api/companies', [CompanyController::class, 'index'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
]);


//Route::get('/api/roles', [RoleController::class, 'index']);
Route::get('/api/roles', [RoleController::class, 'index'])->withoutMiddleware([
    \App\Http\Middleware\VerifyCsrfToken::class,
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
]);


Route::post('/api/register', [AuthController::class, 'register']);
//Route::post('/api/register', [AuthController::class, 'register'])->withoutMiddleware([
//    \App\Http\Middleware\VerifyCsrfToken::class,
//    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
//]);
/*Route::middleware(['web'])->group(function () {
    Route::get('/sanctum/csrf-cookie', function () {
        return response()->noContent();
    });

    Route::post('/api/register', [AuthController::class, 'register']);
});*/


//Route::post('/api/roles', [RoleController::class, 'store']);
Route::middleware(['web'])->group(function () {
    Route::post('/api/roles', [RoleController::class, 'store']);
});

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);
