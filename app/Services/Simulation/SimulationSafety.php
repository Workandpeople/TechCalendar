<?php

namespace App\Services\Simulation;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class SimulationSafety
{
    private const DEMO_HTTP_HOSTS = [
        'api.mapbox.com',
        'events.mapbox.com',
        'api.openai.com',
        'cee-api.test.globalplus.fr',
    ];

    public function activate(): void
    {
        $demo = app()->environment('demo');
        if ($demo) {
            $this->assertDemoConfiguration();
            config([
                'services.coffrac' => [],
                'services.firebase' => [],
                'capcee.enabled' => false,
                'session.secure' => true,
                'session.domain' => null,
                'session.cookie' => 'techcalendar_v2_demo_session',
            ]);
            Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
                if (in_array($event->command, ['migrate:fresh', 'migrate:reset', 'migrate:rollback', 'db:wipe', 'db:seed', 'simulation:reset'], true)) {
                    throw new RuntimeException('Demo : commande destructive interdite. Restaurer une nouvelle base dediee.');
                }
            });
        } elseif (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Une base de simulation ne peut pas fonctionner hors local/testing.');
        }
        config(['simulation.enabled' => true, 'mail.default' => 'log']);
        if (! config('simulation.allow_external_apis')) {
            config(['broadcasting.default' => 'log']);
            foreach (['coffrac', 'global_plus', 'firebase', 'openai', 'mapbox'] as $service) {
                config(['services.'.$service => []]);
            }
        }
        Http::globalRequestMiddleware(static function (RequestInterface $request) use ($demo) {
            if (! config('simulation.allow_external_apis')) {
                throw new RuntimeException('Simulation : appels HTTP sortants interdits.');
            }
            if ($demo) {
                $uri = $request->getUri();
                if ($uri->getScheme() !== 'https'
                    || ! in_array(strtolower($uri->getHost()), self::DEMO_HTTP_HOSTS, true)
                    || ! in_array($uri->getPort(), [null, 443], true)
                    || $uri->getUserInfo() !== '') {
                    // Never include the URL: query strings may contain API credentials.
                    throw new RuntimeException('Demo : destination HTTP non autorisee. Global+ doit utiliser son environnement de test.');
                }
            }

            return $request;
        });
        // Covers dynamically configured SMTP senders as well as the default mailer.
        Event::listen(MessageSending::class, static fn () => false);
    }

    public function boot(): void
    {
        // Resolve DB_URL as well; constructing the connection does not open its PDO.
        $database = (string) DB::connection()->getDatabaseName();
        if (app()->environment('demo') || config('simulation.enabled') || str_ends_with($database, '_simulation')) {
            $this->activate();
        }
    }

    public function assertDemoConfiguration(): void
    {
        $connection = DB::connection();
        if (! config('simulation.enabled') || ! config('crm.v2_enabled') || config('app.debug')
            || parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https'
            || ! parse_url((string) config('app.url'), PHP_URL_HOST)
            || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1', 'mysql'], true)
            || ! preg_match('/^[a-zA-Z0-9_]+_simulation$/', $connection->getDatabaseName())) {
            throw new RuntimeException('Demo : CRM_V2_ENABLED=true, SIMULATION_MODE=true, APP_DEBUG=false, URL HTTPS et base MySQL locale dediee *_simulation obligatoires.');
        }
        if (! in_array(config('broadcasting.default'), ['reverb', 'log', 'null'], true)) {
            throw new RuntimeException('Demo : utiliser Reverb dedie, log ou null pour la diffusion.');
        }
        if (config('broadcasting.default') === 'reverb'
            && ! in_array(config('broadcasting.connections.reverb.options.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Demo : le serveur Reverb doit etre local au CT.');
        }
    }

    public function assertSeedTarget(): void
    {
        if (! config('simulation.enabled') || ! app()->environment('local', 'testing')) {
            throw new RuntimeException('SIMULATION_MODE=true et APP_ENV=local sont obligatoires.');
        }
        $connection = DB::connection();
        if (app()->environment('testing') && $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:') {
            return;
        }
        if ($connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['mysql', 'localhost', '127.0.0.1', '::1'], true)
            || ! preg_match('/^[a-zA-Z0-9_]+_simulation$/', $connection->getDatabaseName())) {
            throw new RuntimeException('Cible refusee : utiliser une base MySQL locale dediee, suffixee _simulation.');
        }
    }
}
