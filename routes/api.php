<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Motafawweq Platform
|--------------------------------------------------------------------------
| Middleware layers used below:
|   auth:sanctum             -> valid, non-expired bearer token
|   abilities:access         -> must be an ACCESS token (refresh tokens rejected)
|   abilities:refresh        -> must be a REFRESH token
|   account.active           -> blocks suspended accounts (re-checked every request)
|   account.active:strict    -> also blocks accounts pending approval
|   email.verified           -> email must be confirmed via OTP
|   role:admin,...           -> role-based access control
|   throttle:*               -> rate limiting (see AppServiceProvider)
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

Route::prefix('v1')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Public (guest) auth endpoints
    |----------------------------------------------------------------------
    */
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
        });

        Route::middleware('throttle:otp')->prefix('password')->group(function () {
            Route::post('/forgot', [PasswordResetController::class, 'forgot']);
            Route::post('/reset', [PasswordResetController::class, 'reset']);
        });

        // Refresh: only a REFRESH token is accepted here
        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware(['auth:sanctum', 'abilities:refresh', 'throttle:refresh']);
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated — usable even before email verification / approval
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'abilities:access', 'account.active', 'throttle:api'])
        ->prefix('auth')
        ->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);

            Route::middleware('throttle:otp')->prefix('email')->group(function () {
                Route::post('/verify', [EmailVerificationController::class, 'verify']);
                Route::post('/resend', [EmailVerificationController::class, 'resend']);
            });
        });

    /*
    |----------------------------------------------------------------------
    | Fully protected — verified email + active (approved) account
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'abilities:access', 'account.active:strict', 'email.verified', 'throttle:api'])
        ->group(function () {

            Route::prefix('auth')->group(function () {
                Route::put('/profile', [AuthController::class, 'updateProfile']);
                Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:strict');

                Route::get('/sessions', [SessionController::class, 'index']);
                Route::delete('/sessions/{sessionId}', [SessionController::class, 'destroy'])->whereUuid('sessionId');
                Route::get('/login-history', [SessionController::class, 'history']);
            });

            // Admin area
            Route::prefix('admin')->middleware('role:admin')->group(function () {
                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
                Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
            });
        });
});
