<?php

use App\Modules\Promo\Http\Controllers\AdminPromoBatchController;
use App\Modules\Promo\Http\Controllers\AdminPromoController;
use App\Modules\Promo\Http\Controllers\ClientInviteController;
use Illuminate\Support\Facades\Route;

// CL-13: invite a friend
Route::middleware(['auth:sanctum', 'role:client'])->get('client/invite', [ClientInviteController::class, 'show']);

// ADM-09: promo codes
Route::middleware(['auth:sanctum', 'permission:admin.promo.view'])->prefix('admin/promo')->group(function () {
    Route::get('overview', [AdminPromoController::class, 'overview']);
    Route::get('lookups', [AdminPromoController::class, 'lookups']);
    Route::get('referral-settings', [AdminPromoController::class, 'referralSettings']);
    Route::put('referral-settings', [AdminPromoController::class, 'updateReferralSettings'])->middleware('permission:admin.promo.manage');

    Route::get('codes', [AdminPromoController::class, 'index']);
    Route::post('codes', [AdminPromoController::class, 'store'])->middleware('permission:admin.promo.manage');
    Route::get('codes/{promo}', [AdminPromoController::class, 'show'])->whereUuid('promo');
    Route::put('codes/{promo}', [AdminPromoController::class, 'update'])->whereUuid('promo')->middleware('permission:admin.promo.manage');
    Route::post('codes/{promo}/publish', [AdminPromoController::class, 'publish'])->whereUuid('promo')->middleware('permission:admin.promo.manage');
    Route::post('codes/{promo}/deactivate', [AdminPromoController::class, 'deactivate'])->whereUuid('promo')->middleware('permission:admin.promo.manage');

    Route::get('batches', [AdminPromoBatchController::class, 'index']);
    Route::post('batches', [AdminPromoBatchController::class, 'store'])->middleware('permission:admin.promo.manage');
    Route::get('batches/{batch}', [AdminPromoBatchController::class, 'show'])->whereUuid('batch');
    Route::get('batches/{batch}/export', [AdminPromoBatchController::class, 'export'])->whereUuid('batch')->middleware('permission:admin.promo.export');
    Route::post('batches/{batch}/publish', [AdminPromoBatchController::class, 'publish'])->whereUuid('batch')->middleware('permission:admin.promo.manage');
    Route::post('batches/{batch}/deactivate', [AdminPromoBatchController::class, 'deactivate'])->whereUuid('batch')->middleware('permission:admin.promo.manage');
});
