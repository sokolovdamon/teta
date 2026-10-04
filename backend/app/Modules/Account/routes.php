<?php

use App\Modules\Account\Http\Controllers\AdminUserController;
use App\Modules\Account\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::patch('account/profile', [ProfileController::class, 'update']);
    Route::post('account/delete', [ProfileController::class, 'requestDeletion']);
    Route::post('account/delete/cancel', [ProfileController::class, 'cancelDeletion']);

    Route::prefix('admin/users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->middleware('permission:admin.users.view');
        Route::get('/export', [AdminUserController::class, 'export'])->middleware('permission:admin.users.export');
        Route::get('/{user}', [AdminUserController::class, 'show'])->middleware('permission:admin.users.view');
        Route::post('/{user}/block', [AdminUserController::class, 'block'])->middleware('permission:admin.users.block');
        Route::post('/{user}/unblock', [AdminUserController::class, 'unblock'])->middleware('permission:admin.users.block');
        Route::put('/{user}/roles', [AdminUserController::class, 'setRoles'])->middleware('permission:admin.users.assign_roles');
        Route::post('/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('permission:admin.users.reset_password');
    });
});
