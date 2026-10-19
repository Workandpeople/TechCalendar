<?php

use App\Services\Simulation\SimulationSafety;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'demo');
    config([
        'simulation.enabled' => true,
        'simulation.allow_external_apis' => true,
        'crm.v2_enabled' => true,
        'app.debug' => false,
        'app.url' => 'https://demo.example.test',
        'database.default' => 'mysql',
        'database.connections.mysql.url' => null,
        'database.connections.mysql.database' => 'techcalendar_v2_demo_simulation',
        'database.connections.mysql.host' => '127.0.0.1',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    ]);
    DB::purge('mysql');
});

it('allows a dedicated demo without relaxing production or reset protections', function () {
    app(SimulationSafety::class)->boot();
    expect(config('broadcasting.default'))->toBe('reverb')
        ->and(config('capcee.enabled'))->toBeFalse()
        ->and(config('services.firebase'))->toBe([])
        ->and(config('services.coffrac'))->toBe([])
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.cookie'))->toBe('techcalendar_v2_demo_session');
    expect(fn () => app(SimulationSafety::class)->assertSeedTarget())->toThrow(RuntimeException::class);
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(SimulationSafety::class)->activate())->toThrow(RuntimeException::class, 'hors local/testing');
});

it('allows only additive portal fixtures on a safely configured demo', function () {
    config(['services.smsfactor.mode' => 'simulate']);
    Storage::fake('local');
    app(SimulationSafety::class)->assertPortalFixtureTarget();
    expect(fn () => app(SimulationSafety::class)->assertSeedTarget())->toThrow(RuntimeException::class);
    config(['database.connections.mysql.database' => 'techcalendar']);
    DB::purge('mysql');
    expect(fn () => app(SimulationSafety::class)->assertPortalFixtureTarget())->toThrow(RuntimeException::class);
});

it('rejects unsafe demo configuration before opening a connection', function (string $key, mixed $value) {
    config([$key => $value]);
    DB::purge('mysql');
    expect(fn () => app(SimulationSafety::class)->boot())->toThrow(RuntimeException::class);
})->with([
    'simulation off' => ['simulation.enabled', false],
    'v1 enabled' => ['crm.v2_enabled', false],
    'debug exposed' => ['app.debug', true],
    'plain HTTP' => ['app.url', 'http://demo.example.test'],
    'v1 database' => ['database.connections.mysql.database', 'techcalendar'],
    'remote database' => ['database.connections.mysql.host', 'production.example.test'],
    'external broadcaster' => ['broadcasting.default', 'pusher'],
    'external Reverb' => ['broadcasting.connections.reverb.options.host', 'production.example.test'],
]);

it('only permits approved HTTPS APIs and never production CRM calls', function (string $url, bool $allowed) {
    app(SimulationSafety::class)->activate();
    Http::fake(['*' => Http::response(['ok' => true])]);
    if ($allowed) {
        expect(Http::get($url)->successful())->toBeTrue();
    } else {
        expect(fn () => Http::get($url))->toThrow(RuntimeException::class, 'destination HTTP non autorisee');
        Http::assertNothingSent();
    }
})->with([
    ['https://api.mapbox.com/geocoding/v5/test', true],
    ['https://api.openai.com/v1/responses', true],
    ['https://cee-api.test.globalplus.fr/api/Demande', true],
    ['https://cee-api.globalplus.fr/api/Demande', false],
    ['https://cofrac.geniuscontrole.com/api/test', false],
    ['https://fcm.googleapis.com/v1/projects/test/messages:send', false],
    ['https://capcee.my.salesforce.com/services/data', false],
    ['https://api.mapbox.com.attacker.test/', false],
    ['https://api.mapbox.com:8443/', false],
    ['http://api.mapbox.com/', false],
    ['https://user:password@api.mapbox.com/', false],
]);

it('can disable all demo HTTP calls and suppresses dynamic email transports', function () {
    config(['simulation.allow_external_apis' => false]);
    app(SimulationSafety::class)->activate();
    Http::fake();
    expect(fn () => Http::get('https://api.mapbox.com/'))->toThrow(RuntimeException::class, 'interdits');
    config(['mail.mailers.unsafe' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1], 'mail.from.address' => 'demo@example.test']);
    expect(Mail::mailer('unsafe')->raw('Test', fn ($message) => $message->to('real@example.test')))->toBeNull();
});

it('blocks destructive artisan commands in demo', function (string $command) {
    app(SimulationSafety::class)->activate();
    expect(fn () => Event::dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput)))
        ->toThrow(RuntimeException::class, 'commande destructive interdite');
})->with(['migrate:fresh', 'migrate:reset', 'migrate:rollback', 'db:wipe', 'db:seed', 'simulation:reset']);

it('only allows the SMSFactor simulation endpoint in demo', function (string $method, string $url, bool $allowed) {
    config(['services.smsfactor.enabled' => true]);
    app(SimulationSafety::class)->activate();
    Http::fake(['*' => Http::response(['status' => 1, 'sent' => 1])]);
    if ($allowed) {
        expect(Http::{strtolower($method)}($url)->successful())->toBeTrue();
    } else {
        expect(fn () => Http::{strtolower($method)}($url))->toThrow(RuntimeException::class);
        Http::assertNothingSent();
    }
})->with([
    ['POST', 'https://api.smsfactor.com/send/simulate', true],
    ['POST', 'https://api.smsfactor.com/send', false],
    ['GET', 'https://api.smsfactor.com/send', false],
    ['GET', 'https://api.smsfactor.com/send/simulate', false],
    ['POST', 'https://api.smsfactor.com/send/simulate?token=secret', false],
    ['POST', 'http://api.smsfactor.com/send/simulate', false],
    ['POST', 'https://api.smsfactor.com:8443/send/simulate', false],
    ['POST', 'https://api.smsfactor.com.attacker.test/send/simulate', false],
]);
