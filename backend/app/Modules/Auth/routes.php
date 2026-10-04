<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::post('email/verify', [AuthController::class, 'verifyEmail']);
        Route::get('email/available', [AuthController::class, 'emailAvailable']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('email/resend', [AuthController::class, 'resendVerification']);
    });
});

Route::middleware('auth:sanctum')->prefix('account')->group(function () {
    Route::patch('password', [AuthController::class, 'changePassword']);
    Route::post('email', [AuthController::class, 'changeEmail']);
});
