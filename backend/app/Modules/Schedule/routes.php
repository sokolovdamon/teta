<?php

use App\Modules\Schedule\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

// PRO-03: working schedule of the psychologist.
Route::middleware(['auth:sanctum', 'role:psychologist,supervisor'])
    ->prefix('pro/schedule')
    ->where(['interval' => '[0-9a-fA-F-]{36}', 'exception' => '[0-9a-fA-F-]{36}'])
    ->group(function () {
        Route::get('/', [ScheduleController::class, 'show']);
        Route::put('intervals', [ScheduleController::class, 'replaceIntervals']);
        Route::post('intervals', [ScheduleController::class, 'storeInterval']);
        Route::patch('intervals/{interval}', [ScheduleController::class, 'updateInterval']);
        Route::delete('intervals/{interval}', [ScheduleController::class, 'destroyInterval']);
        Route::post('exceptions', [ScheduleController::class, 'storeException']);
        Route::delete('exceptions/{exception}', [ScheduleController::class, 'destroyException']);
        Route::patch('settings', [ScheduleController::class, 'updateSettings']);
        Route::get('preview', [ScheduleController::class, 'preview']);
    });
