<?php

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\TrackingSearchPage;
use App\Services\Mymar\MymarCachedObservation;
use App\Services\Mymar\MymarCalendarFeed;
use App\Services\Mymar\MymarCalendarLabels;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarPilotSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\MymarPilotFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->otherTech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->pilot = MymarPilotFixture::pilot([11 => $this->tech->id]);
    $source = MymarPilotFixture::source($this->pilot);
    $report = (new MymarObservation)->inspect($source, new MymarObservationPolicy, new DateTimeImmutable, $this->pilot->technicianMap);
    Cache::put($this->pilot->cacheKey(), MymarCachedObservation::project($report, $this->pilot, new DateTimeImmutable), 300);
    Cache::put(MymarCalendarLabels::cacheKey($this->pilot), [
        'format' => 'cofrac.mymar-calendar-labels', 'version' => 1, 'generated_at' => $source['generated_at'],
        'window_start' => $source['window_start'], 'window_end' => $source['window_end'], 'technician_ids' => [11],
        'items' => [['rdv_id' => 555, 'dossier_id' => 77, 'technician_id' => 11, 'customer_name' => 'MARTHE SARR',
            'address' => '10 rue des Tilleuls', 'postal_code' => '70800', 'city' => 'Magnoncourt']],
    ], 300);
    $settings = Mockery::mock(MymarPilotSettings::class);
    $settings->shouldReceive('load')->andReturn($this->pilot);
    $settings->shouldNotReceive('token');
    app()->instance(MymarPilotSettings::class, $settings);
    $this->cofracService = Service::create(['type' => 'COFFRAC', 'name' => 'Controle', 'average_duration_minutes' => 60]);
    $this->marService = Service::create(['type' => 'MAR', 'name' => 'VISITE APRES TRAVAUX', 'average_duration_minutes' => 60]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('searches placed MAR appointments by beneficiary regardless of word order and opens their MAR detail', function (string $query) {
    $this->actingAs($this->planner)->postJson(route('planner.tracking.search'), ['q' => $query])->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('appointments.0.id', 'mymar:555')
        ->assertJsonPath('appointments.0.extendedProps.customer_name', 'MARTHE SARR')
        ->assertJsonPath('appointments.0.extendedProps.is_mymar', true)
        ->assertJsonPath('mymar.message', fn ($message) => str_contains($message, 'période synchronisée'));
    $this->get(route('planner.tracking'))->assertOk()->assertSee('Cofrac et MAR')->assertSee('MAR uniquement')
        ->assertSee('CofracMymarCalendar.open(appointmentEvent, button)', false);
})->with(['sarr marthe', 'MARTHE', '70800 magnOncourt', 'mymar:555']);

it('honours source technician service and date filters for MAR results', function (array $filter, int $count) {
    $filter = match ($filter['kind']) {
        'native' => ['source' => 'cofrac'],
        'mar' => ['source' => 'mar'],
        'other_tech' => ['technician_ids' => [$this->otherTech->id]],
        'own_tech' => ['technician_ids' => [$this->tech->id]],
        'native_service' => ['service_id' => $this->cofracService->id],
        'mar_service' => ['service_id' => $this->marService->id],
        'date' => ['date_to' => now('Europe/Paris')->subMonth()->toDateString()],
        default => ['appointment_status' => 'problem'],
    };
    $this->actingAs($this->planner)->postJson(route('planner.tracking.search'), $filter)->assertOk()->assertJsonPath('total', $count);
})->with([[['kind' => 'native'], 0], [['kind' => 'mar'], 1], [['kind' => 'other_tech'], 0], [['kind' => 'own_tech'], 1],
    [['kind' => 'native_service'], 0], [['kind' => 'mar_service'], 1], [['kind' => 'date'], 0], [['kind' => 'problem'], 0]]);

it('returns a coverage warning instead of claiming a complete empty MAR search when cache is unavailable', function () {
    Cache::forget($this->pilot->cacheKey());
    $this->actingAs($this->planner)->postJson(route('planner.tracking.search'), ['source' => 'mar'])->assertOk()
        ->assertJsonPath('total', 0)->assertJsonPath('mymar.state', 'unavailable')
        ->assertJsonPath('mymar.message', fn ($message) => str_contains($message, 'indisponible'));
});

it('filters native and MAR visits using the same past current and future boundaries', function (string $period, int $expected) {
    $now = now('Europe/Paris')->startOfSecond();
    $this->travelTo($now);
    $events = [];
    foreach ([-120, -30, 90] as $index => $offset) {
        $start = $now->copy()->addMinutes($offset);
        Appointment::create(['technician_id' => $this->tech->id, 'service_id' => $this->cofracService->id, 'created_by' => $this->planner->id,
            'customer_first_name' => 'Client', 'customer_last_name' => 'Timeline', 'customer_phone' => '0600000000', 'latitude' => 48.85, 'longitude' => 2.35, 'address' => 'Test',
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'duration_minutes' => 60]);
        $events[] = ['id' => 'mymar:'.($index + 1), 'start' => $start->toIso8601String(), 'end' => $start->copy()->addHour()->toIso8601String(),
            'extendedProps' => ['is_mymar' => true, 'source_rdv_id' => $index + 1, 'visit_phase' => 'after_works',
                'customer_name' => 'Client Timeline', 'address' => 'Test', 'postal_code' => '75001', 'city' => 'Paris', 'technician_name' => 'Test', 'service_label' => 'MAR']];
    }
    $feed = Mockery::mock(MymarCalendarFeed::class);
    $feed->shouldReceive('window')->andReturn(['events' => $events, 'state' => 'observing']);
    app()->instance(MymarCalendarFeed::class, $feed);
    $this->actingAs($this->planner)->postJson(route('planner.tracking.search'), ['period' => $period])->assertOk()->assertJsonPath('total', $expected);
})->with([['past', 2], ['ongoing', 2], ['upcoming', 2], ['all', 6]]);

it('keeps mixed source pagination stable in every supported sort order', function (string $sort) {
    $start = now('Europe/Paris')->startOfDay();
    $expected = [];
    $mar = [];
    foreach (range(0, 11) as $index) {
        $time = $start->copy()->addHours($index % 4);
        $duration = [60, 30, 120][$index % 3];
        $native = Appointment::create(['technician_id' => $this->tech->id, 'service_id' => $this->cofracService->id, 'created_by' => $this->planner->id,
            'customer_first_name' => 'Test', 'customer_last_name' => 'Sort', 'customer_phone' => '0600000000', 'latitude' => 48.85, 'longitude' => 2.35, 'address' => 'Test',
            'starts_at' => $time, 'ends_at' => $time->copy()->addMinutes($duration), 'duration_minutes' => $duration]);
        $expected[] = ['id' => $native->id, 'time' => $time->timestamp, 'duration' => $duration, 'native' => 1];
        if ($index % 3 === 0) {
            $mar[] = ['id' => 'mymar:'.$index, 'start' => $time->toIso8601String(), 'extendedProps' => ['source_rdv_id' => $index, 'duration_minutes' => $duration]];
            $expected[] = ['id' => $index, 'time' => $time->timestamp, 'duration' => $duration, 'native' => 0];
        }
    }
    $key = str_starts_with($sort, 'duration_') ? 'duration' : 'time';
    $direction = str_ends_with($sort, '_asc') ? 1 : -1;
    usort($expected, fn ($a, $b) => $direction * ([$a[$key], $a['time']] <=> [$b[$key], $b['time']]) ?: [$b['native'], $b['id']] <=> [$a['native'], $a['id']]);
    $found = [];
    foreach (range(1, 6) as $page) {
        $result = app(TrackingSearchPage::class)->paginate(Appointment::query(), $mar, $page, 3, $sort);
        foreach ($result['rows'] as $row) {
            $found[] = [$row['native'], $row['id']];
        }
    }
    expect($found)->toBe(array_map(fn ($row) => [$row['native'], $row['id']], $expected));
})->with(['starts_asc', 'starts_desc', 'duration_asc', 'duration_desc']);

it('does not expose MAR search to external accounts or ordinary technicians', function (int $role) {
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    $this->actingAs($user)->postJson(route('planner.tracking.search'), ['source' => 'mar'])->assertForbidden();
})->with([2, 3]);

it('paginates a combined native and MAR timeline without duplicates or loading the native history', function () {
    $start = now()->startOfDay();
    $nativeIds = [];
    foreach (range(0, 39) as $index) {
        $appointment = Appointment::create(['technician_id' => $this->tech->id, 'service_id' => $this->cofracService->id,
            'created_by' => $this->planner->id, 'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '0600000000', 'address' => 'Test',
            'latitude' => 48.8, 'longitude' => 2.3, 'starts_at' => $start->copy()->addHours($index), 'duration_minutes' => 60,
            'ends_at' => $start->copy()->addHours($index + 1)]);
        $nativeIds[] = ['id' => $appointment->id, 'time' => $appointment->starts_at->timestamp, 'native' => 1];
    }
    $mar = [];
    foreach ([1, 20, 39] as $index) {
        $mar[] = ['id' => 'mymar:'.$index, 'start' => $start->copy()->addHours($index)->toIso8601String(), 'extendedProps' => ['source_rdv_id' => $index]];
        $nativeIds[] = ['id' => $index, 'time' => $start->copy()->addHours($index)->timestamp, 'native' => 0];
    }
    usort($nativeIds, fn ($a, $b) => [$b['time'], $b['native'], $b['id']] <=> [$a['time'], $a['native'], $a['id']]);
    $all = [];
    foreach (range(1, 9) as $page) {
        $result = app(TrackingSearchPage::class)->paginate(Appointment::query(), $mar, $page, 5);
        expect($result['total'])->toBe(43);
        foreach ($result['rows'] as $row) {
            $all[] = ['id' => $row['id'], 'time' => $row['time'], 'native' => $row['native']];
        }
    }
    expect($all)->toBe($nativeIds);
});

it('hydrates a mixed page through the search endpoint and keeps native-only results available', function () {
    $native = Appointment::create(['technician_id' => $this->tech->id, 'service_id' => $this->cofracService->id,
        'created_by' => $this->planner->id, 'customer_first_name' => 'Marthe', 'customer_last_name' => 'Sarr',
        'customer_phone' => '0600000000', 'address' => 'Test', 'latitude' => 48.8, 'longitude' => 2.3,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60]);
    $response = $this->actingAs($this->planner)->postJson(route('planner.tracking.search'), ['q' => 'sarr', 'limit' => 1])
        ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)->assertJsonCount(1, 'appointments');
    $second = $this->postJson(route('planner.tracking.search'), ['q' => 'sarr', 'limit' => 1, 'page' => 2])
        ->assertOk()->assertJsonCount(1, 'appointments');
    expect([(string) $response->json('appointments.0.id'), (string) $second->json('appointments.0.id')])
        ->toEqualCanonicalizing([(string) $native->id, 'mymar:555']);

    Cache::forget($this->pilot->cacheKey());
    $this->postJson(route('planner.tracking.search'), ['q' => 'sarr'])->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('appointments.0.id', $native->id)->assertJsonPath('mymar.state', 'unavailable');
});
