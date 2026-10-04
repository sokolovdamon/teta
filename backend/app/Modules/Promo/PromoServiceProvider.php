<?php

namespace App\Modules\Promo;

use App\Modules\Promo\Contracts\NullPromoCodes;
use App\Modules\Promo\Contracts\PromoCodes;
use Illuminate\Support\ServiceProvider;

class PromoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(PromoCodes::class, NullPromoCodes::class);
    }
}
