<?php

namespace App\Modules\Psychologists;

use App\Modules\Catalog\Services\SearchIndex;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Support\ServiceProvider;

class PsychologistsServiceProvider extends ServiceProvider
{
    private const INDEXED = ['first_name', 'last_name', 'headline', 'about', 'education'];

    public function boot(): void
    {
        // SEARCH: keep the full-text vector of the published profile in sync with its text columns.
        // Relations (approaches, requests, specializations) are re-indexed by ProfileService after a sync.
        Psychologist::saved(function (Psychologist $p) {
            if ($p->wasRecentlyCreated || $p->wasChanged(self::INDEXED)) {
                app(SearchIndex::class)->refresh($p);
            }
        });
    }
}
