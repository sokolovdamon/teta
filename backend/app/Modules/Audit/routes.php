<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'permission:admin.audit.view'])->prefix('admin/audit')->group(function () {
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('/export', [AuditLogController::class, 'export'])->middleware('permission:admin.audit.export');
});
