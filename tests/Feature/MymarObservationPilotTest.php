<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\Crm\DossierLocation;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarCalendarFeed;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Mymar\MymarPilotConfiguration;
use App\Services\Mymar\MymarPilotSettings;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\MymarPilotFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->technician = User::factory()->create(['role' => 2, 'admin' => false, 'external_client_id' => null]);
    $this->pilot = MymarPilotFixture::pilot([11 => $this->technician->id]);
    $this->settings = Mockery::mock(MymarPilotSettings::class);
    $this->settings->shouldReceive('load')->andReturnUsing(fn () => $this->pilot);
    $this->settings->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    app()->instance(MymarPilotSettings::class, $this->settings);
});

it('syncs through HTTP into local cache without creating appointments or exposing source credentials', function () {
    Http::fake(['http://127.0.0.1:19846/*' => Http::response(MymarPilotFixture::source($this->pilot))]);
    $this->artisan('mymar:sync-observation')->assertSuccessful();
    $snapshot = Cache::get($this->pilot->cacheKey());
    expect($snapshot['periods'])->toHaveCount(1)->and(Appointment::count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://127.0.0.1:19846/v1/occupations?') && ! str_contains($r->url(), 'Bearer'));
    Http::fake([]);
    $this->artisan('mymar:observation-status')->expectsOutputToContain('"cache_ready":true')->assertSuccessful();
    $feed = app(MymarCalendarFeed::class)->window([$this->technician->id], now()->startOfDay(), now()->addDays(7));
    expect($feed['state'])->toBe('observing')->and($feed['blocking_enabled'])->toBeFalse()
        ->and($feed['events'][0]['editable'])->toBeFalse()->and($feed['events'][0]['id'])->toBe('mymar:555');
    Http::assertNothingSent();
});

it('publishes guard metadata from local configuration without remote reads or editable MAR events', function () {
    $now = new DateTimeImmutable;
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($this->pilot, $now), new MymarObservationPolicy, $now, $this->pilot->technicianMap);
    Cache::put($this->pilot->cacheKey(), MymarCachedObservation::project($report, $this->pilot, $now), 86400);
    $actions = Mockery::mock(MymarActionSettings::class);
    $actions->shouldReceive('load')->once()->andReturn(['guard_enabled' => true]);
    app()->instance(MymarActionSettings::class, $actions);
    $feed = app(MymarCalendarFeed::class)->window([$this->technician->id], now()->startOfDay(), now()->addDays(7));
    expect($feed['blocking_enabled'])->toBeTrue()->and($feed['events'][0]['editable'])->toBeFalse()
        ->and($feed['events'][0]['extendedProps']['availability_guard_enabled'])->toBeTrue();
    Http::assertNothingSent();
});

it('keeps the last complete snapshot when HTTP fails or source data is unusable', function (bool $invalid) {
    Http::fake(['*' => Http::response(MymarPilotFixture::source($this->pilot))]);
    expect(app(MymarObservationSync::class)->run()['status'])->toBe('observing');
    $last = Cache::get($this->pilot->cacheKey());
    $bad = MymarPilotFixture::source($this->pilot);
    $bad['appointments'][0]['raw_duration'] = null;
    Http::fake(['*' => $invalid ? Http::response($bad) : Http::response(['error' => 'source_unavailable'], 503)]);
    expect(app(MymarObservationSync::class)->run()['status'])->toBe('failed')
        ->and(Cache::get($this->pilot->cacheKey()))->toBe($last);
    $feed = app(MymarCalendarFeed::class)->window([$this->technician->id], now(), now()->addDays(7));
    expect($feed['state'])->toBe('degraded')->and($feed['events'])->toHaveCount(1)->and(Appointment::count())->toBe(0);
})->with([true, false]);

it('accepts an identical cache write even when the database reports zero affected rows', function () {
    Cache::extend('mymar_noop_write', fn () => new Repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            parent::put($key, $value, $seconds);

            return false;
        }
    }));
    config(['cache.default' => 'mymar_noop_write', 'cache.stores.mymar_noop_write' => ['driver' => 'mymar_noop_write']]);
    Http::fake(['*' => fn () => Http::response(MymarPilotFixture::source($this->pilot))]);
    expect(app(MymarObservationSync::class)->run())->toMatchArray(['status' => 'observing']);
    expect(app(MymarObservationSync::class)->run())->toMatchArray(['status' => 'observing']);
});

it('distinguishes a complete empty snapshot from missing stale invalid or out of coverage data', function () {
    $source = MymarPilotFixture::source($this->pilot);
    $source['appointments'] = [];
    Http::fake(['*' => Http::response($source)]);
    app(MymarObservationSync::class)->run();
    $feed = app(MymarCalendarFeed::class);
    expect($feed->window([$this->technician->id], now(), now()->addDays(7)))->toMatchArray(['state' => 'observing', 'events' => []]);
    expect($feed->window([$this->technician->id], now(), now()->addDays(40))['state'])->toBe('partial');
    $snapshot = Cache::get($this->pilot->cacheKey());
    $snapshot['generated_at'] = (new DateTimeImmutable('-6 minutes'))->format(DATE_ATOM);
    Cache::put($this->pilot->cacheKey(), $snapshot, 86400);
    expect($feed->window([$this->technician->id], now(), now()->addDays(7))['state'])->toBe('stale');
    $snapshot['fingerprint'] = 'tampered';
    Cache::put($this->pilot->cacheKey(), $snapshot, 86400);
    expect($feed->window([$this->technician->id], now(), now()->addDays(7))['state'])->toBe('unavailable');
    Cache::forget($this->pilot->cacheKey());
    expect($feed->window([$this->technician->id], now(), now()->addDays(7))['state'])->toBe('unavailable');
});

it('refuses a disabled target and never refreshes from a calendar request', function () {
    Http::fake(['*' => Http::response(MymarPilotFixture::source($this->pilot))]);
    app(MymarObservationSync::class)->run();
    $this->technician->delete();
    Http::fake([]);
    expect(app(MymarCalendarFeed::class)->window([$this->technician->id], now(), now()->addDays(7)))
        ->toMatchArray(['state' => 'mapping_changed', 'events' => []]);
    expect(app(MymarObservationSync::class)->run()['reason'])->toBe('mymar.target_mapping_changed');
    Http::assertNothingSent();
});

it('does nothing when the pilot or v2 is disabled and preserves a concurrent sync lock', function () {
    $lock = Cache::lock($this->pilot->cacheKey().':lock', 30);
    expect($lock->get())->toBeTrue();
    expect(app(MymarObservationSync::class)->run()['status'])->toBe('busy');
    expect(Cache::lock($this->pilot->cacheKey().':lock', 30)->get())->toBeFalse();
    $lock->release();
    $this->pilot = MymarPilotConfiguration::disabled();
    expect(app(MymarObservationSync::class)->run()['status'])->toBe('disabled');
    expect(app(MymarCalendarFeed::class)->window([], now(), now()->addDay())['state'])->toBe('disabled');
    Http::assertNothingSent();
});

it('shows cached MAR events only in authorized internal calendar feeds without touching the native list or availability', function () {
    $now = new DateTimeImmutable;
    $source = MymarPilotFixture::source($this->pilot, $now);
    $report = (new MymarObservation)->inspect($source, new MymarObservationPolicy, $now, $this->pilot->technicianMap);
    Cache::put($this->pilot->cacheKey(), MymarCachedObservation::project($report, $this->pilot, $now), 86400);
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($planner)->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->technician->id],
        'start' => now()->toIso8601String(), 'end' => now()->addDays(7)->toIso8601String()])
        ->assertOk()->assertJsonPath('mymar.state', 'observing')->assertJsonPath('events.0.extendedProps.is_mymar', true);
    expect(Appointment::count())->toBe(0);
    $checks = app(AppointmentSlotAvailability::class)->inspect($this->technician,
        Carbon::parse($report['candidates'][0]['starts_at']), Carbon::parse($report['candidates'][0]['ends_at']), []);
    expect($checks['conflicts'])->toBe([]);
    Http::assertNothingSent();
});

it('keeps bounded calendar query counts with 2000 cached occupations and does not load the API token', function () {
    $this->settings->shouldReceive('token')->never();
    $now = new DateTimeImmutable;
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($this->pilot, $now), new MymarObservationPolicy, $now, $this->pilot->technicianMap);
    $value = MymarCachedObservation::project($report, $this->pilot, $now);
    $period = $value['periods'][0];
    $value['periods'] = array_map(fn ($id) => array_replace($period, ['source_rdv_id' => $id]), range(1, 2000));
    Cache::put($this->pilot->cacheKey(), $value, 86400);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $feed = app(MymarCalendarFeed::class)->window([$this->technician->id], now(), now()->addDays(7));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($feed['events'])->toHaveCount(2000)->and(count($queries))->toBeLessThanOrEqual(2);
    Http::assertNothingSent();
});

it('includes only the cache projection in booking windows without making a source request', function () {
    config(['services.mapbox.token' => null]);
    $service = Service::create(['type' => 'COFRAC', 'name' => 'Prestation test', 'average_duration_minutes' => 60]);
    $this->technician->update(['department_code' => '75', 'address' => 'Paris', 'latitude' => 48.85, 'longitude' => 2.35]);
    Department::updateOrCreate(['code' => '75'], ['name' => 'Paris']);
    $this->technician->departments()->attach('75');
    $this->technician->services()->attach($service);
    $dossier = Dossier::create(['reference' => 'CACHE-BOOK', 'first_name' => 'Test', 'last_name' => 'Local',
        'address' => '1 rue du Test', 'city' => 'Paris', 'postal_code' => '75001']);
    $dossier->forceFill(['inspection_geocode' => DossierLocation::metadata($dossier, ['latitude' => 48.85, 'longitude' => 2.35])])->save();
    $operation = $dossier->operations()->create(['service_id' => $service->id]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $now = new DateTimeImmutable;
    $report = (new MymarObservation)->inspect(MymarPilotFixture::source($this->pilot, $now), new MymarObservationPolicy, $now, $this->pilot->technicianMap);
    Cache::put($this->pilot->cacheKey(), MymarCachedObservation::project($report, $this->pilot, $now), 86400);
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($planner)->postJson(route('planner.book.calendar-window'), [
        'crm_appointment_id' => 'dossier-'.$operation->id, 'technician_ids' => [$this->technician->id],
        'start' => now()->startOfDay()->toIso8601String(), 'end' => now()->addDays(7)->toIso8601String(),
    ])->assertOk()->assertJsonPath('mymar.state', 'observing')->assertJsonPath('events.0.id', 'mymar:555');
    Http::assertNothingSent();
    expect(Appointment::count())->toBe(0);
});

it('does not expose the internal MAR calendar to an active external account', function () {
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'MAR-TEST',
        'name' => 'Client test', 'kind' => 'client', 'is_active' => true]);
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $organisation->id,
        'portal_enabled' => true, 'must_change_password' => false]);
    $this->actingAs($external)->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->technician->id],
        'start' => now()->toIso8601String(), 'end' => now()->addDay()->toIso8601String()])->assertForbidden();
    Http::assertNothingSent();
});
