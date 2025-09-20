<?php

namespace Hexaora\VoyagerToFilament\Providers;

use Hexaora\VoyagerToFilament\Commands\VoyagerToFilamentConverter;
use Illuminate\Support\ServiceProvider;

class VoyagerToFilamentServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Register the command
            $this->commands([
                VoyagerToFilamentConverter::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        //
    }
}