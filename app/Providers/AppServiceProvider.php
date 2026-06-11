<?php

namespace App\Providers;

use App\Services\ApplicationSettings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
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
        Password::defaults(fn (): Password => Password::min(8)->mixedCase()->numbers());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        try {
            app(ApplicationSettings::class)->applyToConfig();
        } catch (Throwable) {
            // Fallback .env: settings DB must never block application boot.
        }
    }
}
