<?php

use App\Modules\Psychologists\Http\Controllers\AdminPsychologistController;
use App\Modules\Psychologists\Http\Controllers\ProProfileController;
use App\Modules\Psychologists\Http\Controllers\ProQualificationController;
use Illuminate\Support\Facades\Route;

// PRO-01, PRO-02: the psychologist's own profile and qualification.
Route::middleware(['auth:sanctum', 'role:psychologist,supervisor'])->prefix('pro')->where(['document' => '[0-9a-fA-F-]{36}'])->group(function () {
    Route::get('profile', [ProProfileController::class, 'show']);
    Route::patch('profile', [ProProfileController::class, 'update']);
    Route::post('profile/photo', [ProProfileController::class, 'uploadPhoto'])->middleware('throttle:20,1');
    Route::delete('profile/photo', [ProProfileController::class, 'removePhoto']);
    Route::post('profile/video', [ProProfileController::class, 'uploadVideo'])->middleware('throttle:10,1');
    Route::delete('profile/video', [ProProfileController::class, 'removeVideo']);
    Route::post('profile/pause', [ProProfileController::class, 'pause']);
    Route::post('profile/resume', [ProProfileController::class, 'resume']);

    Route::get('qualification', [ProQualificationController::class, 'show']);
    Route::post('qualification/documents', [ProQualificationController::class, 'storeDocument'])->middleware('throttle:30,1');
    Route::delete('qualification/documents/{document}', [ProQualificationController::class, 'destroyDocument']);
    Route::post('qualification/submit', [ProQualificationController::class, 'submit'])->middleware('verified.email');
});

// ADM-03
Route::middleware('auth:sanctum')->prefix('admin/psychologists')->where(['psychologist' => '[0-9a-fA-F-]{36}', 'document' => '[0-9a-fA-F-]{36}'])->group(function () {
    Route::get('/', [AdminPsychologistController::class, 'index'])->middleware('permission:admin.psychologists.view');
    Route::get('/{psychologist}', [AdminPsychologistController::class, 'show'])->middleware('permission:admin.psychologists.view');

    Route::middleware('permission:admin.psychologists.verify')->group(function () {
        Route::post('/{psychologist}/qualification/approve', [AdminPsychologistController::class, 'approveQualification']);
        Route::post('/{psychologist}/qualification/reject', [AdminPsychologistController::class, 'rejectQualification']);
        Route::post('/{psychologist}/documents/{document}/review', [AdminPsychologistController::class, 'reviewDocument']);
    });

    Route::middleware('permission:admin.psychologists.moderate_profile')->group(function () {
        Route::post('/{psychologist}/changes/approve', [AdminPsychologistController::class, 'approveChanges']);
        Route::post('/{psychologist}/changes/reject', [AdminPsychologistController::class, 'rejectChanges']);
        Route::post('/{psychologist}/video/approve', [AdminPsychologistController::class, 'approveVideo']);
        Route::post('/{psychologist}/video/reject', [AdminPsychologistController::class, 'rejectVideo']);
    });

    Route::middleware('permission:admin.psychologists.block')->group(function () {
        Route::post('/{psychologist}/block', [AdminPsychologistController::class, 'block']);
        Route::post('/{psychologist}/unblock', [AdminPsychologistController::class, 'unblock']);
    });
});
