<?php

use App\Modules\Payouts\Http\Controllers\AdminPayoutsController;
use App\Modules\Payouts\Http\Controllers\ProPayoutsController;
use App\Modules\Payouts\Http\Controllers\ProStatsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:psychologist,supervisor'])->prefix('pro')->group(function () {
    // PRO-08: statistics and income
    Route::get('stats', [ProStatsController::class, 'summary']);
    Route::get('stats/accruals', [ProStatsController::class, 'accruals']);
    Route::get('stats/export', [ProStatsController::class, 'export']);

    // PRO-09: withdrawal
    Route::get('payouts', [ProPayoutsController::class, 'overview']);
    Route::get('payouts/history', [ProPayoutsController::class, 'payouts']);
    Route::post('payouts/card', [ProPayoutsController::class, 'startCardBinding'])->middleware('throttle:10,1');
    Route::post('payouts/card/bindings/{binding}/confirm', [ProPayoutsController::class, 'confirmCardBinding'])->whereUuid('binding');
    Route::delete('payouts/card', [ProPayoutsController::class, 'removeCard']);
});

// ADM-08: payouts
Route::middleware(['auth:sanctum', 'permission:admin.payouts.view'])->prefix('admin/payouts')->group(function () {
    Route::get('registries', [AdminPayoutsController::class, 'registries']);
    Route::post('registries', [AdminPayoutsController::class, 'build'])->middleware('permission:admin.payouts.manage');
    Route::get('registries/{registry}', [AdminPayoutsController::class, 'showRegistry'])->whereUuid('registry');
    Route::post('registries/{registry}/approve', [AdminPayoutsController::class, 'approve'])->whereUuid('registry')->middleware('permission:admin.payouts.approve');
    Route::post('registries/{registry}/retry', [AdminPayoutsController::class, 'retry'])->whereUuid('registry')->middleware('permission:admin.payouts.manage');
    Route::post('lines/{payout}/exclude', [AdminPayoutsController::class, 'exclude'])->whereUuid('payout')->middleware('permission:admin.payouts.approve');
    Route::get('settings', [AdminPayoutsController::class, 'settings']);
    Route::get('blocked', [AdminPayoutsController::class, 'blocked']);
    Route::get('payees', [AdminPayoutsController::class, 'payees']);
    Route::get('payees/{user}', [AdminPayoutsController::class, 'payee'])->whereUuid('user');
    Route::post('payees/{user}/suspend', [AdminPayoutsController::class, 'suspend'])->whereUuid('user')->middleware('permission:admin.payouts.manage');
    Route::post('payees/{user}/resume', [AdminPayoutsController::class, 'resume'])->whereUuid('user')->middleware('permission:admin.payouts.manage');
});
