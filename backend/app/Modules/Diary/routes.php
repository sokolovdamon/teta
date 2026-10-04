<?php

use App\Modules\Diary\Http\Controllers\DiaryController;
use App\Modules\Diary\Http\Controllers\EmotionTagController;
use App\Modules\Diary\Http\Controllers\PsychologistDiaryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // CL-01, CL-06: the client's own diary.
    Route::middleware('role:client')->prefix('diary')->group(function () {
        Route::get('prompt', [DiaryController::class, 'prompt']);
        Route::post('skip', [DiaryController::class, 'skip']);
        Route::get('emotion-tags', [DiaryController::class, 'tags']);
        Route::get('entries', [DiaryController::class, 'index']);
        Route::post('entries', [DiaryController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('entries/{entry}', [DiaryController::class, 'destroy'])->whereUuid('entry');
        Route::get('dynamics', [DiaryController::class, 'dynamics']);
    });

    // PRO-06: dynamics of a client for their psychologist (hard restriction in the controller).
    Route::get('pro/clients/{client}/diary', [PsychologistDiaryController::class, 'dynamics'])
        ->middleware('role:psychologist,supervisor')->whereUuid('client');

    // ADM-13: the list of emotion tags (never the entries).
    Route::prefix('admin/diary/emotion-tags')->group(function () {
        Route::get('/', [EmotionTagController::class, 'index'])->middleware('permission:admin.dictionaries.view');
        Route::post('/', [EmotionTagController::class, 'store'])->middleware('permission:admin.dictionaries.manage');
        Route::patch('/{tag}', [EmotionTagController::class, 'update'])->middleware('permission:admin.dictionaries.manage')->whereUuid('tag');
        Route::delete('/{tag}', [EmotionTagController::class, 'destroy'])->middleware('permission:admin.dictionaries.manage')->whereUuid('tag');
    });
});
