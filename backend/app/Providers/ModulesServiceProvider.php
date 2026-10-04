<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Discovers platform modules in app/Modules/<Module>.
 *
 * A module may contain:
 *  - <Module>ServiceProvider.php — registered automatically;
 *  - routes.php — API routes mounted under /api/v1 with the "api" middleware group;
 *  - console.php — scheduled tasks and console commands.
 */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->modules() as $module) {
            $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            foreach ($this->modules() as $module) {
                $routes = app_path("Modules/{$module}/routes.php");
                if (is_file($routes)) {
                    Route::prefix('api/v1')->middleware('api')->group($routes);
                }
            }
        }

        if ($this->app->runningInConsole()) {
            foreach ($this->modules() as $module) {
                $console = app_path("Modules/{$module}/console.php");
                if (is_file($console)) {
                    require $console;
                }
            }
        }
    }

    /** @return list<string> */
    private function modules(): array
    {
        $dirs = glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);

        return $names;
    }
}
