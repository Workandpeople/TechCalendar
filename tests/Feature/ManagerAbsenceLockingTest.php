<?php

use App\Models\TechnicianAbsence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->payload = ['starts_on' => '2026-07-15', 'ends_on' => '2026-07-16', 'reason' => 'Formation'];
    $this->actingAs($this->manager);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('checks the technician and conflicts inside the insertion transaction without changing legacy redirects', function () {
    $queries = [];
    $initialLevel = DB::transactionLevel();
    DB::listen(function ($query) use (&$queries) {
        $queries[] = [$query->sql, $query->connection->transactionLevel()];
    });
    $this->post(route('manager.users.absences.store', ['user' => $this->technician->id, 'q' => 'Camille']), $this->payload)
        ->assertRedirect(route('manager.users', ['q' => 'Camille']))->assertSessionHas('status', 'Absence ajoutée.');

    $absenceQueryIndex = collect($queries)->search(fn ($query) => str_contains($query[0], 'exists') && str_contains($query[0], 'technician_absences'));
    expect($absenceQueryIndex)->not->toBeFalse();
    expect($queries[$absenceQueryIndex][1])->toBeGreaterThan($initialLevel);
    expect(collect(array_slice($queries, 0, $absenceQueryIndex))->contains(fn ($query) => str_contains($query[0], 'from "users"')
        && str_contains($query[0], '"role"') && $query[1] > $initialLevel))->toBeTrue();
    expect(TechnicianAbsence::sole()->created_by)->toBe($this->manager->id);
});

it('rechecks the current manager authorization even when the authenticated instance is stale', function (string $change) {
    match ($change) {
        'role' => User::whereKey($this->manager->id)->update(['role' => 1]),
        'disabled' => User::whereKey($this->manager->id)->update(['deleted_at' => now()]),
    };
    $this->post(route('manager.users.absences.store', $this->technician), $this->payload)->assertForbidden();
    expect(TechnicianAbsence::count())->toBe(0);
})->with(['role', 'disabled']);

it('keeps the historical manager and administrator permissions in v1 and v2', function (bool $native, int $role, bool $admin) {
    config(['crm.v2_enabled' => $native]);
    $this->actingAs(User::factory()->create(['role' => $role, 'admin' => $admin]))
        ->post(route('manager.users.absences.store', $this->technician), $this->payload)->assertRedirect(route('manager.users'));
    expect(TechnicianAbsence::count())->toBe(1);
})->with([false, true])->with([[0, false], [1, true]]);

it('still rejects planners and inactive technician targets', function (string $case) {
    if ($case === 'planner') {
        $this->actingAs(User::factory()->create(['role' => 1, 'admin' => false]));
    } else {
        $this->technician->delete();
    }
    $response = $this->post(route('manager.users.absences.store', $this->technician), $this->payload);
    $case === 'planner' ? $response->assertForbidden() : $response->assertNotFound();
    expect(TechnicianAbsence::count())->toBe(0);
})->with(['planner', 'inactive']);

it('runs legacy creation in a rollback capable transaction', function () {
    Event::listen('eloquent.created: '.TechnicianAbsence::class, fn () => throw new RuntimeException('Test rollback'));
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->post(route('manager.users.absences.store', $this->technician), $this->payload))
            ->toThrow(RuntimeException::class, 'Test rollback');
    } finally {
        Event::forget('eloquent.created: '.TechnicianAbsence::class);
    }
    expect(TechnicianAbsence::count())->toBe(0);
});

it('stores Paris calendar days even when PHP and application timezones are UTC', function () {
    $previousTimezone = date_default_timezone_get();
    try {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        $this->post(route('manager.users.absences.store', $this->technician), $this->payload)->assertRedirect(route('manager.users'));
        expect(TechnicianAbsence::sole()->getRawOriginal('starts_at'))->toBe('2026-07-15 00:00:00')
            ->and(TechnicianAbsence::sole()->getRawOriginal('ends_at'))->toBe('2026-07-16 23:59:59');
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});
