<?php

namespace App\Modules\Support;

use App\Modules\Support\Contracts\LoggingSupportDesk;
use App\Modules\Support\Contracts\SupportDesk;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SupportDesk::class, LoggingSupportDesk::class);
    }
}
