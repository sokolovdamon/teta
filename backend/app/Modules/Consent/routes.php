<?php

use App\Modules\Consent\Http\Controllers\LegalDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('legal', [LegalDocumentController::class, 'index']);
Route::get('legal/{slug}', [LegalDocumentController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('account/consents', [LegalDocumentController::class, 'myConsents']);
    Route::post('account/consents', [LegalDocumentController::class, 'toggle']);

    Route::prefix('admin/legal')->group(function () {
        Route::get('/', [LegalDocumentController::class, 'adminIndex'])->middleware('permission:admin.content.view');
        Route::post('/', [LegalDocumentController::class, 'adminStore'])->middleware('permission:admin.content.manage');
        Route::patch('/{document}', [LegalDocumentController::class, 'adminUpdate'])->middleware('permission:admin.content.manage');
        Route::post('/{document}/versions', [LegalDocumentController::class, 'adminAddVersion'])->middleware('permission:admin.content.manage');
        Route::post('/versions/{version}/publish', [LegalDocumentController::class, 'adminPublishVersion'])->middleware('permission:admin.content.manage');
    });
    Route::get('admin/consents', [LegalDocumentController::class, 'adminConsents'])->middleware('permission:admin.moderation.view|admin.users.view');
});
