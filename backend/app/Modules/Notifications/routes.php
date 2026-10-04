<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\NotificationTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::get('account/notification-preferences', [NotificationController::class, 'preferences']);
    Route::patch('account/notification-preferences', [NotificationController::class, 'updatePreferences']);

    Route::prefix('admin/notification-templates')->group(function () {
        Route::get('/', [NotificationTemplateController::class, 'index'])->middleware('permission:admin.notifications.view');
        Route::get('/{template}', [NotificationTemplateController::class, 'show'])->middleware('permission:admin.notifications.view');
        Route::patch('/{template}', [NotificationTemplateController::class, 'update'])->middleware('permission:admin.notifications.manage');
        Route::post('/{template}/preview', [NotificationTemplateController::class, 'preview'])->middleware('permission:admin.notifications.view');
        Route::post('/{template}/test', [NotificationTemplateController::class, 'testSend'])->middleware('permission:admin.notifications.manage');
    });
});
