<?php

use App\Modules\Payments\Http\Controllers\AdminComplaintController;
use App\Modules\Payments\Http\Controllers\AdminFinanceController;
use App\Modules\Payments\Http\Controllers\CardController;
use App\Modules\Payments\Http\Controllers\ClientPaymentController;
use App\Modules\Payments\Http\Controllers\ComplaintController;
use App\Modules\Payments\Http\Controllers\EmulatorController;
use App\Modules\Payments\Http\Controllers\GiftCertificateController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('payments')->group(function () {
    // Gateway webhooks (signature checked inside; no auth).
    Route::post('webhooks/{provider}', WebhookController::class);

    // Hosted checkout of the emulator (/pay/emulator/{id}).
    Route::get('emulator/test-cards', [EmulatorController::class, 'testCards']);
    Route::get('emulator/operations/{operation}', [EmulatorController::class, 'show']);
    Route::post('emulator/operations/{operation}/card', [EmulatorController::class, 'card'])->middleware('throttle:30,1');
    Route::post('emulator/operations/{operation}/3ds', [EmulatorController::class, 'threeDs'])->middleware('throttle:30,1');
    Route::post('emulator/operations/{operation}/cancel', [EmulatorController::class, 'cancel']);

    // /pay/return polling and SITE-20.
    Route::get('status/{payment}', [ClientPaymentController::class, 'status']);
    Route::get('gift/options', [GiftCertificateController::class, 'options']);
    Route::post('gift', [GiftCertificateController::class, 'purchase'])->middleware('throttle:10,1');
    Route::get('gift/{certificate}', [GiftCertificateController::class, 'status']);
});

Route::middleware('auth:sanctum')->prefix('payments')->group(function () {
    Route::get('summary', [ClientPaymentController::class, 'summary']);
    Route::get('history', [ClientPaymentController::class, 'history']);
    Route::get('balance/operations', [ClientPaymentController::class, 'balanceOperations']);
    Route::post('balance/withdraw', [ClientPaymentController::class, 'withdraw'])->middleware('verified.email');
    Route::post('charges/{task}/pay', [ClientPaymentController::class, 'payCharge']);

    Route::get('cards', [CardController::class, 'index']);
    Route::post('cards', [CardController::class, 'store'])->middleware('verified.email');
    Route::get('cards/bindings/{binding}', [CardController::class, 'binding']);
    Route::post('cards/{method}/default', [CardController::class, 'makeDefault']);
    Route::delete('cards/{method}', [CardController::class, 'destroy']);

    Route::get('complaints', [ComplaintController::class, 'index']);
    Route::post('complaints', [ComplaintController::class, 'store']);
    Route::post('complaints/{complaint}/answer', [ComplaintController::class, 'answer']);
    Route::post('complaints/{complaint}/withdraw', [ComplaintController::class, 'withdraw']);

    Route::post('certificates/activate', [GiftCertificateController::class, 'activate'])->middleware('throttle:10,1');
});

// ADM-07.
Route::middleware('auth:sanctum')->prefix('admin/finance')->group(function () {
    Route::get('summary', [AdminFinanceController::class, 'summary'])->middleware('permission:admin.finance.view');
    Route::get('payments', [AdminFinanceController::class, 'payments'])->middleware('permission:admin.finance.view');
    Route::get('payments/{payment}', [AdminFinanceController::class, 'payment'])->middleware('permission:admin.finance.view');
    Route::post('payments/{payment}/refund', [AdminFinanceController::class, 'refund'])->middleware('permission:admin.finance.refund');
    Route::get('refunds', [AdminFinanceController::class, 'refunds'])->middleware('permission:admin.finance.view');
    Route::get('balance-operations', [AdminFinanceController::class, 'balanceOperations'])->middleware('permission:admin.finance.view');
    Route::post('withdrawals/{operation}/resolve', [AdminFinanceController::class, 'resolveWithdrawal'])->middleware('permission:admin.finance.refund');
    Route::get('charge-tasks', [AdminFinanceController::class, 'chargeTasks'])->middleware('permission:admin.finance.view');

    Route::get('complaints', [AdminComplaintController::class, 'index'])->middleware('permission:admin.finance.complaints|admin.finance.view');
    Route::get('complaints/{complaint}', [AdminComplaintController::class, 'show'])->middleware('permission:admin.finance.complaints|admin.finance.view');
    Route::post('complaints/{complaint}/take', [AdminComplaintController::class, 'take'])->middleware('permission:admin.finance.complaints');
    Route::post('complaints/{complaint}/ask', [AdminComplaintController::class, 'ask'])->middleware('permission:admin.finance.complaints');
    Route::post('complaints/{complaint}/resume', [AdminComplaintController::class, 'resume'])->middleware('permission:admin.finance.complaints');
    Route::post('complaints/{complaint}/reject', [AdminComplaintController::class, 'reject'])->middleware('permission:admin.finance.complaints');
    Route::post('complaints/{complaint}/approve', [AdminComplaintController::class, 'approve'])->middleware('permission:admin.finance.complaints');
});
