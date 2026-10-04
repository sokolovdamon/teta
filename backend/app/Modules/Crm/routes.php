<?php

use App\Modules\Crm\Http\Controllers\ClientController;
use App\Modules\Crm\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

// PRO-05. Hard restrictions are checked in the controllers (ClientRelationship), not through RBAC.
Route::middleware(['auth:sanctum', 'role:psychologist,supervisor'])->prefix('pro/clients')->group(function () {
    Route::get('/', [ClientController::class, 'index']);
    Route::get('/{client}', [ClientController::class, 'show'])->whereUuid('client');
    Route::get('/{client}/sessions', [ClientController::class, 'sessions'])->whereUuid('client');
    Route::post('/{client}/finish', [ClientController::class, 'finish'])->whereUuid('client');

    Route::get('/{client}/notes', [NoteController::class, 'index'])->whereUuid('client');
    Route::post('/{client}/notes', [NoteController::class, 'store'])->whereUuid('client');
    Route::patch('/{client}/notes/{note}', [NoteController::class, 'update'])->whereUuid(['client', 'note']);
    Route::delete('/{client}/notes/{note}', [NoteController::class, 'destroy'])->whereUuid(['client', 'note']);
});
