<?php

use App\Modules\Recommendations\Http\Controllers\ClientRecommendationController;
use App\Modules\Recommendations\Http\Controllers\ProRecommendationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // PRO-07: the author's recommendations (author check in the controller).
    Route::middleware('role:psychologist,supervisor')->group(function () {
        Route::get('pro/clients/{client}/recommendations', [ProRecommendationController::class, 'index'])->whereUuid('client');
        Route::post('pro/recommendations', [ProRecommendationController::class, 'store']);
        Route::get('pro/recommendations/{recommendation}', [ProRecommendationController::class, 'show'])->whereUuid('recommendation');
        Route::patch('pro/recommendations/{recommendation}', [ProRecommendationController::class, 'update'])->whereUuid('recommendation');
        Route::delete('pro/recommendations/{recommendation}', [ProRecommendationController::class, 'destroy'])->whereUuid('recommendation');
        Route::post('pro/recommendations/{recommendation}/send', [ProRecommendationController::class, 'send'])->whereUuid('recommendation');
        Route::post('pro/recommendations/{recommendation}/revoke', [ProRecommendationController::class, 'revoke'])->whereUuid('recommendation');
    });

    // CL-05: the client's recommendations.
    Route::middleware('role:client')->prefix('client/recommendations')->group(function () {
        Route::get('/', [ClientRecommendationController::class, 'index']);
        Route::get('/{recommendation}', [ClientRecommendationController::class, 'show'])->whereUuid('recommendation');
        Route::post('/{recommendation}/done', [ClientRecommendationController::class, 'done'])->whereUuid('recommendation');
        Route::post('/{recommendation}/undo', [ClientRecommendationController::class, 'undo'])->whereUuid('recommendation');
    });
});
