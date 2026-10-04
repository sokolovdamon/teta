<?php

namespace App\Modules\Booking;

use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Contracts\NoCorporateCoverage;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CorporateCoverage::class, NoCorporateCoverage::class);
    }
}
