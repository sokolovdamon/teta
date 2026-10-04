<?php

use App\Modules\Catalog\Services\SearchIndex;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// SEARCH: rebuild full-text vectors (dictionary titles may change in ADM-13) and stored price categories (DEC-55).
Artisan::command('catalog:reindex', function (SearchIndex $index) {
    $count = $index->refreshAll();
    $this->info("Reindexed psychologists: {$count}");
})->purpose('Rebuild the psychologist catalog search index and price categories');

Schedule::command('catalog:reindex')->dailyAt('03:20')->timezone('Europe/Moscow')->withoutOverlapping();
