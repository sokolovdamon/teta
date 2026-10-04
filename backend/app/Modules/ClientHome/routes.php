<?php

use App\Modules\ClientHome\Http\Controllers\ClientHomeController;
use Illuminate\Support\Facades\Route;

// CL-02: the client's cabinet home.
Route::get('client/home', [ClientHomeController::class, 'show'])->middleware(['auth:sanctum', 'role:client']);
