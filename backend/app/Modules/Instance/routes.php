<?php

use App\Modules\Instance\Http\Controllers\InstanceController;
use Illuminate\Support\Facades\Route;

Route::get('instance', [InstanceController::class, 'public']);
Route::post('instance/heartbeat', [InstanceController::class, 'heartbeat'])->middleware('throttle:60,1');

Route::middleware('auth:sanctum')->prefix('admin/instance')->group(function () {
    Route::get('/', [InstanceController::class, 'show'])->middleware('permission:admin.instance.view|admin.settings.view');
    Route::patch('/', [InstanceController::class, 'update'])->middleware('permission:admin.instance.manage');
    Route::get('/partners', [InstanceController::class, 'partners'])->middleware('permission:admin.instance.view');
    Route::post('/partners', [InstanceController::class, 'storePartner'])->middleware('permission:admin.instance.manage');
    Route::patch('/partners/{partner}', [InstanceController::class, 'updatePartner'])->middleware('permission:admin.instance.manage');
    Route::post('/partners/{partner}/transition', [InstanceController::class, 'transitionPartner'])->middleware('permission:admin.instance.manage');
});
