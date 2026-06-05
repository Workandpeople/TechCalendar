<?php

namespace App\Providers;

use App\Services\ApplicationSettings;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            app(ApplicationSettings::class)->applyToConfig();
        } catch (Throwable) {
            // Fallback .env: settings DB must never block application boot.
        }
    }
}
