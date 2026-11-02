<?php

use App\Models\Appointment;
use App\Models\User;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarCalendarFeed;
use App\Services\Mymar\MymarCalendarLabels;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\MymarPilotFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->pilot = MymarPilotFixture::pilot([11 => $this->tech->id]);
    $this->source = MymarPilotFixture::source($this->pilot);
    $this->labels = ['format' => 'cofrac.mymar-calendar-labels', 'version' => 1,
        'generated_at' => (new DateTimeImmutable)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        'window_start' => $this->source['window_start'], 'window_end' => $this->source['window_end'], 'technician_ids' => [11],
        'items' => [['rdv_id' => 555, 'dossier_id' => 77, 'technician_id' => 11,
            'customer_name' => 'Philippe Martin', 'address' => '10 rue des Tilleuls, 01560 Lescheroux', 'postal_code' => '01560', 'city' => 'Lescheroux']]];
    $settings = Mockery::mock(MymarPilotSettings::class);
    $settings->shouldReceive('load')->andReturn($this->pilot);
    $settings->shouldReceive('token')->andReturn(hash('sha256', 'synthetic-occupancy-token'));
    app()->instance(MymarPilotSettings::class, $settings);
    $reader = Mockery::mock(MymarBookingReaderSettings::class);
    $reader->shouldReceive('load')->andReturn(['read_enabled' => true]);
    $reader->shouldReceive('token')->andReturn(hash('sha256', 'synthetic-display-only-token'));
    app()->instance(MymarBookingReaderSettings::class, $reader);
});

it('syncs names and addresses in one private read and renders calendars from cache only', function () {
    Http::fake(['http://127.0.0.1:19846/*' => Http::response($this->source),
        'http://127.0.0.1:19847/*' => Http::response($this->labels)]);
    expect(app(MymarObservationSync::class)->run())->toMatchArray(['status' => 'observing', 'calendar_labels_ready' => true]);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'http://127.0.0.1:19847/v1/calendar-labels?'));
    Http::fake([]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $feed = app(MymarCalendarFeed::class)->window([$this->tech->id], now(), now()->addDays(7));
    $queries = DB::getQueryLog(); DB::disableQueryLog();
    expect($feed['events'][0]['extendedProps'])->toMatchArray(['customer_name' => 'Philippe Martin',
        'address' => '10 rue des Tilleuls, 01560 Lescheroux', 'postal_code' => '01560', 'city' => 'Lescheroux', 'read_only' => true])
        ->and($feed['events'][0]['editable'])->toBeFalse()->and(count($queries))->toBeLessThanOrEqual(2)
        ->and(Appointment::count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps all occupation and availability data when the optional display gateway fails', function (int $status) {
    Http::fake(['http://127.0.0.1:19846/*' => Http::response($this->source),
        'http://127.0.0.1:19847/*' => Http::response(['error' => 'booking_endpoint_not_found'], $status)]);
    expect(app(MymarObservationSync::class)->run())->toMatchArray(['status' => 'observing', 'calendar_labels_ready' => false]);
    $snapshot = Cache::get($this->pilot->cacheKey());
    $feed = app(MymarCalendarFeed::class)->window([$this->tech->id], now(), now()->addDays(7));
    expect($snapshot['periods'])->toHaveCount(1)->and($feed['events'])->toHaveCount(1)->and($feed['state'])->toBe('observing')
        ->and($feed['events'][0]['extendedProps']['customer_name'])->toBe('')
        ->and(Cache::get($this->pilot->cacheKey().':failure'))->toBeNull();
})->with([404, 503]);

it('does not attach a beneficiary to the wrong dossier or technician', function (string $field) {
    Http::fake(['http://127.0.0.1:19846/*' => Http::response($this->source),
        'http://127.0.0.1:19847/*' => Http::response($this->labels)]);
    app(MymarObservationSync::class)->run();
    $cached = Cache::get(MymarCalendarLabels::cacheKey($this->pilot));
    $cached['items'][0][$field] = 999;
    Cache::put(MymarCalendarLabels::cacheKey($this->pilot), $cached, 300);
    Http::fake([]);
    $feed = app(MymarCalendarFeed::class)->window([$this->tech->id], now(), now()->addDays(7));
    expect($feed['events'])->toHaveCount(1)->and($feed['events'][0]['extendedProps']['customer_name'])->toBe('');
    Http::assertNothingSent();
})->with(['dossier_id', 'technician_id', 'rdv_id']);

it('ignores stale malformed and oversized labels without invalidating the occupancy snapshot', function (string $case) {
    Http::fake(['http://127.0.0.1:19846/*' => Http::response($this->source),
        'http://127.0.0.1:19847/*' => Http::response($this->labels)]);
    app(MymarObservationSync::class)->run();
    $labels = $this->labels;
    match ($case) {
        'stale' => $labels['generated_at'] = (new DateTimeImmutable('-6 minutes'))->format(DATE_ATOM),
        'email' => $labels['items'][0]['email'] = 'must-not-be-exported@example.test',
        'long' => $labels['items'][0]['customer_name'] = str_repeat('a', 322),
        'window' => $labels['window_start'] = (new DateTimeImmutable('-30 days'))->format(DATE_ATOM),
    };
    Cache::put(MymarCalendarLabels::cacheKey($this->pilot), $labels, 300);
    Http::fake([]);
    $feed = app(MymarCalendarFeed::class)->window([$this->tech->id], now(), now()->addDays(7));
    expect($feed['events'])->toHaveCount(1)->and($feed['state'])->toBe('observing')
        ->and($feed['events'][0]['extendedProps']['customer_name'])->toBe('');
    Http::assertNothingSent();
})->with(['stale', 'email', 'long', 'window']);
