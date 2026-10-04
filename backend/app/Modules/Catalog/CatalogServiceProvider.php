<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Listeners\ForgetNearestSlot;
use App\Support\Events\Outbox;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The cached nearest slot of a card is dropped when bookings or the profile change.
        Outbox::listen('book.session.*', ForgetNearestSlot::class);
        Outbox::listen('psy.*', ForgetNearestSlot::class);
    }
}
