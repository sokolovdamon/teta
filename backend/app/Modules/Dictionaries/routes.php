<?php

use App\Modules\Dictionaries\Http\Controllers\DictionaryController;
use Illuminate\Support\Facades\Route;

Route::get('dictionaries', [DictionaryController::class, 'all']);
Route::get('dictionaries/requests/{slug}', [DictionaryController::class, 'request']);

Route::middleware('auth:sanctum')->prefix('admin/dictionaries/{type}')->group(function () {
    Route::get('/', [DictionaryController::class, 'adminIndex'])->middleware('permission:admin.dictionaries.view');
    Route::post('/', [DictionaryController::class, 'adminStore'])->middleware('permission:admin.dictionaries.manage');
    Route::patch('/{id}', [DictionaryController::class, 'adminUpdate'])->middleware('permission:admin.dictionaries.manage');
    Route::delete('/{id}', [DictionaryController::class, 'adminDestroy'])->middleware('permission:admin.dictionaries.manage');
});
