<?php

namespace App\Providers;

use App\Services\ApplicationSettings;
use App\Services\FrenchMailNotifications;
use App\Services\GlobalMailConfiguration;
use App\Services\Simulation\SimulationSafety;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mime\Address;
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
        FrenchMailNotifications::register();

        Event::listen(MessageSending::class, static function (MessageSending $event): void {
            if (config('crm.v2_enabled') && ($address = config('mail.from.address'))) {
                $event->message->from(new Address($address, config('mail.from.name') ?: 'Genius Contrôle'));
                $event->message->replyTo($address);
            }
        });

        Queue::before(static fn () => app(GlobalMailConfiguration::class)->refresh());

        if (! config('mail.delivery_enabled')) {
            Event::listen(MessageSending::class, static fn () => false);
        }

        if ($this->app->environment('production', 'demo')) {
            URL::forceScheme('https');
        }

        try {
            app(ApplicationSettings::class)->applyToConfig();
        } catch (Throwable) {
            // Fallback .env: settings DB must never block application boot.
        }

        app(SimulationSafety::class)->boot();
    }
}
