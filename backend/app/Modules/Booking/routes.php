<?php

use App\Modules\Booking\Http\Controllers\AdminSessionController;
use App\Modules\Booking\Http\Controllers\BookingController;
use App\Modules\Booking\Http\Controllers\ProSessionController;
use App\Modules\Booking\Http\Controllers\TimeRequestController;
use Illuminate\Support\Facades\Route;

// Public (the user is read from the token when present): quote and slot holds during the wizard (SEQ-01).
Route::prefix('booking')->group(function () {
    Route::get('quote', [BookingController::class, 'quote']);
    Route::post('holds', [BookingController::class, 'hold'])->middleware('throttle:30,1');
    Route::delete('holds/{hold}', [BookingController::class, 'releaseHold']);
});

Route::middleware('auth:sanctum')->prefix('booking')->group(function () {
    // Client (CL-03, CL-04, WIZ-06).
    Route::post('sessions', [BookingController::class, 'store'])->middleware('verified.email');
    Route::get('sessions', [BookingController::class, 'index']);
    Route::get('sessions/{session}', [BookingController::class, 'show']);
    Route::get('sessions/{session}/reschedule-slots', [BookingController::class, 'rescheduleSlots']);
    Route::post('sessions/{session}/reschedule', [BookingController::class, 'reschedule']);
    Route::post('sessions/{session}/cancel', [BookingController::class, 'cancel']);
    Route::post('sessions/{session}/choice', [BookingController::class, 'choose']);
    Route::post('sessions/{session}/partner/accept', [BookingController::class, 'acceptPartner']);
    Route::get('intents/{intent}', [BookingController::class, 'intent']);
    Route::get('my-psychologists', [BookingController::class, 'myPsychologists']);
    Route::post('change-psychologist', [BookingController::class, 'changePsychologist']);

    Route::get('time-requests', [TimeRequestController::class, 'index']);
    Route::post('time-requests', [TimeRequestController::class, 'store'])->middleware('verified.email');
    Route::post('time-requests/{timeRequest}/cancel', [TimeRequestController::class, 'cancel']);

    // Psychologist (PRO-04).
    Route::middleware('role:psychologist,supervisor')->prefix('pro')->group(function () {
        Route::get('sessions', [ProSessionController::class, 'index']);
        Route::get('sessions/{session}', [ProSessionController::class, 'show']);
        Route::post('sessions/{session}/outcome', [ProSessionController::class, 'outcome']);
        Route::post('sessions/{session}/cancel', [ProSessionController::class, 'cancel']);
        Route::get('sessions/{session}/reschedule-slots', [ProSessionController::class, 'rescheduleSlots']);
        Route::post('sessions/{session}/reschedule', [ProSessionController::class, 'reschedule']);
        Route::get('time-requests', [TimeRequestController::class, 'inbox']);
        Route::post('time-requests/{timeRequest}/offer', [TimeRequestController::class, 'offer']);
        Route::post('time-requests/{timeRequest}/close', [TimeRequestController::class, 'close']);
    });
});

// ADM-04.
Route::middleware('auth:sanctum')->prefix('admin/sessions')->group(function () {
    Route::get('/', [AdminSessionController::class, 'index'])->middleware('permission:admin.sessions.view');
    Route::get('/{session}', [AdminSessionController::class, 'show'])->middleware('permission:admin.sessions.view');
    Route::post('/{session}/outcome', [AdminSessionController::class, 'outcome'])->middleware('permission:admin.sessions.set_outcome');
    Route::post('/{session}/cancel', [AdminSessionController::class, 'cancel'])->middleware('permission:admin.sessions.cancel');
});
