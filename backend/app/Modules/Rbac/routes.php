<?php

use App\Modules\Rbac\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('admin/roles')->group(function () {
    Route::get('/', [RoleController::class, 'matrix'])->middleware('permission:admin.roles.view');
    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:admin.roles.view');
    Route::post('/', [RoleController::class, 'store'])->middleware('permission:admin.roles.manage');
    Route::patch('/{role}', [RoleController::class, 'update'])->middleware('permission:admin.roles.manage');
    Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');
});
