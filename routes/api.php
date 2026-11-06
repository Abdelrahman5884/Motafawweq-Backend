<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Motafawweq Platform
|--------------------------------------------------------------------------
| Hardened security with layered architecture, throttling & role management.
*/

// Health check endpoint
Route::get('/ping', function () {
    return response()->json([
        'success'   => true,
        'message'   => 'Motafawweq Platform API is active and healthy.',
        'timestamp' => now()->toIso8601String(),
        'version'   => 'v1',
    ]);
});

// API Version 1
Route::prefix('v1')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Public Authentication Routes (Throttled to 5 attempts/min)
    |----------------------------------------------------------------------
    */
    Route::prefix('auth')->middleware(['throttle:auth'])->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated User Routes (Bearer Token via Sanctum)
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        // User Profile & Session Management
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
            Route::post('/change-password', [AuthController::class, 'changePassword']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        });

        /*
        |------------------------------------------------------------------
        | Protected Admin Routes (Requires role: admin)
        |------------------------------------------------------------------
        */
        Route::prefix('admin')->middleware(['role:admin'])->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::get('/users/{id}', [UserController::class, 'show']);
            Route::delete('/users/{id}', [UserController::class, 'destroy']);
        });
    });
});
