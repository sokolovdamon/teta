<?php

use App\Modules\Catalog\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

// CATALOG, SEARCH: public catalog of psychologists (SITE-02, SITE-03).
Route::get('psychologists', [CatalogController::class, 'index']);
Route::get('psychologists/{slug}', [CatalogController::class, 'show']);
Route::get('psychologists/{slug}/slots', [CatalogController::class, 'slots']);
Route::post('psychologists/{slug}/views', [CatalogController::class, 'view'])->middleware('throttle:30,1');
