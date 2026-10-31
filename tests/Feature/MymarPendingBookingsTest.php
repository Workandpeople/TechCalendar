<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\User;
use App\Services\Mymar\MymarBookingReaderSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->configuration = ['format' => 'cofrac.mymar-booking-reader-client', 'version' => 1,
        'read_enabled' => true, 'writes_enabled' => false, 'port' => 19847, 'generation' => (string) Str::uuid()];
    $settings = Mockery::mock(MymarBookingReaderSettings::class);
    $settings->shouldReceive('load')->andReturnUsing(fn () => $this->configuration);
    $settings->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    app()->instance(MymarBookingReaderSettings::class, $settings);
    $this->sourcePage = fn (int $after = 0, int $count = 25, ?int $next = null) => [
        'format' => 'cofrac.mymar-pending-bookings', 'version' => 1, 'next_after' => $next,
        'items' => array_map(fn ($id) => ['dossier_id' => $id, 'status_id' => 14, 'phase' => 'after_works', 'expected_revision' => str_repeat('a', 64),
            'first_name' => 'Test', 'last_name' => 'PRIVATE_MAR_BENEFICIARY', 'address' => 'PRIVATE_MAR_ADDRESS', 'postal_code' => '01560',
            'city' => 'Lescheroux', 'phone' => '0600000000', 'email' => null, 'latitude' => null, 'longitude' => null], $count ? range($after + 1, $after + $count) : []),
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('reads pending pages only on their dedicated request and stores no native dossier or appointment', function () {
    Http::fake(fn ($request) => Http::response(($this->sourcePage)((int) ($request->data()['after'] ?? 0), 25, 25)));
    $this->actingAs($this->actor)->get(route('planner.book'))->assertOk()->assertSee('data-mymar-pending', false);
    Http::assertNothingSent();
    $this->getJson(route('planner.book.mar.index'))->assertOk()->assertJsonPath('read_only', true)
        ->assertJsonPath('items.0.last_name', 'PRIVATE_MAR_BENEFICIARY')->assertJsonPath('next_after', 25)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://127.0.0.1:19847/v1/pending-bookings?after=0&limit=25');
});

it('caches encrypted projections briefly and bounds manual refreshes', function () {
    Carbon::setTestNow('2026-10-31 13:00:00');
    Http::fake(fn () => Http::response(($this->sourcePage)()));
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.index'))->assertOk();
    $this->getJson(route('planner.book.mar.index', ['refresh' => 1]))->assertOk();
    Http::assertSentCount(1);
    $key = 'mymar:pending:v1:'.hash('sha256', $this->configuration['generation']).':0:25';
    expect(Cache::get($key))->toBeString()->not->toContain('PRIVATE_MAR_BENEFICIARY', 'PRIVATE_MAR_ADDRESS');
    Carbon::setTestNow(now()->addSeconds(6));
    $this->getJson(route('planner.book.mar.index', ['refresh' => 1]))->assertOk();
    Http::assertSentCount(2);
    Carbon::setTestNow(now()->addSeconds(31));
    $this->getJson(route('planner.book.mar.index'))->assertOk();
    Http::assertSentCount(3);
});

it('uses distinct page and generation keys and disables access before reading any cached data', function () {
    Http::fake(fn ($request) => Http::response(($this->sourcePage)((int) ($request->data()['after'] ?? 0))));
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.index'))->assertOk();
    $this->getJson(route('planner.book.mar.index', ['after' => 25]))->assertOk()->assertJsonPath('items.0.dossier_id', 26);
    $this->configuration['generation'] = (string) Str::uuid();
    $this->getJson(route('planner.book.mar.index'))->assertOk();
    Http::assertSentCount(3);
    $this->configuration = null;
    $this->getJson(route('planner.book.mar.index'))->assertStatus(503)->assertDontSee('PRIVATE_MAR_BENEFICIARY');
    Http::assertSentCount(3);
});

it('never presents a failed source or malformed page as an empty successful list', function (string $case) {
    Http::fake(fn () => $case === 'timeout' ? throw new RuntimeException('TOKEN_PRIVATE_CANARY')
        : Http::response($case === 'malformed' ? ['items' => []] : ['error' => 'booking_disabled'], $case === 'malformed' ? 200 : 503));
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.index'))->assertStatus(503)->assertHeader('Retry-After', '5')
        ->assertDontSee('TOKEN_PRIVATE_CANARY')->assertJsonMissing(['items' => []]);
})->with(['timeout', 'malformed', 'disabled']);

it('distinguishes an empty successful page and can probe one item without printing personal data', function () {
    Http::fake(fn () => Http::response(($this->sourcePage)(0, 0)));
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.index'))->assertOk()->assertJsonPath('items', []);
    $this->artisan('mymar:pending-probe')->expectsOutputToContain('"reader_ready":true')->assertSuccessful();
});

it('rejects malformed cursors before contacting MYMAR', function (array $query) {
    $this->actingAs($this->actor)->getJson(route('planner.book.mar.index', $query))->assertUnprocessable();
    Http::assertNothingSent();
})->with([[['after' => '-1']], [['after' => '1 OR 1']], [['after' => str_repeat('9', 19)]], [['after' => ['1']]], [['refresh' => 'yes']]]);

it('authorizes managers/planners and internal admins but rejects external, technical, deleted and V1 access', function (string $role) {
    $user = match ($role) {
        'manager' => User::factory()->create(['role' => 0, 'admin' => false]),
        'admin' => User::factory()->create(['role' => 0, 'admin' => true]),
        'external' => User::factory()->create(['role' => 3, 'admin' => false]),
        'tech' => User::factory()->create(['role' => 2, 'admin' => false]),
        default => $this->actor,
    };
    if ($role === 'deleted') {
        $user->delete();
    }
    if ($role === 'v1') {
        config(['crm.v2_enabled' => false]);
    }
    Http::fake(fn () => Http::response(($this->sourcePage)()));
    $response = $this->actingAs($user)->getJson(route('planner.book.mar.index'));
    if (in_array($role, ['manager', 'admin'], true)) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
        Http::assertNothingSent();
    }
})->with(['manager', 'admin', 'external', 'tech', 'deleted', 'v1']);

it('does not register any placement route and never waits on an occupied cache lock', function () {
    $this->actingAs($this->actor)->postJson(route('planner.book.mar.index'))->assertStatus(405);
    $key = 'mymar:pending:v1:'.hash('sha256', $this->configuration['generation']).':0:25';
    $lock = Cache::lock($key.':lock', 8);
    expect($lock->get())->toBeTrue();
    try {
        $this->getJson(route('planner.book.mar.index'))->assertStatus(503);
    } finally {
        $lock->release();
    }
    Http::assertNothingSent();
});
