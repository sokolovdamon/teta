<?php

use App\Modules\Files\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::post('files', [FileController::class, 'upload'])->middleware(['auth:sanctum', 'throttle:60,1']);
Route::get('files/{file}/download', [FileController::class, 'download'])->name('files.download')->middleware('signed');
