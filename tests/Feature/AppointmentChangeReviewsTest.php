<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\SidebarNotifications;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'last_name' => 'TECH DEMO']);
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'BAT EQ DEMO', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'CHANGE-DEMO', 'company_name' => 'Société DEMO', 'legacy_lot_name' => 'LOT DEMO',
        'address' => 'Adresse fictive', 'city' => 'Paris', 'postal_code' => '75001']);
    $operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $this->appointment = Appointment::forceCreate(['service_id' => $service->id, 'technician_id' => $this->tech->id,
        'dossier_operation_id' => $operation->id, 'created_by' => $this->planner->id, 'external_source' => 'native',
        'customer_last_name' => 'DEMO', 'customer_first_name' => '', 'customer_phone' => '', 'address' => 'Adresse fictive',
        'starts_at' => '2026-11-24 10:00', 'ends_at' => '2026-11-24 11:00', 'duration_minutes' => 60]);
    $this->actingAs($this->planner);
    $this->change = function (string $time = '12:00') {
        $this->appointment->refresh()->update(['starts_at' => '2026-11-24 '.$time, 'ends_at' => Carbon::parse('2026-11-24 '.$time)->addHour()]);
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('does not flag initial placement comments GPS corrections or no-op saves', function () {
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeFalse();
    $this->appointment->update(['comment' => 'Note interne', 'latitude' => 48.8, 'longitude' => 2.3]);
    $this->appointment->save();
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
    $this->get(route('planner.appointment-changes'))->assertOk()->assertSee('Aucun changement');
});

it('coalesces pending changes while keeping the first before and the latest after', function () {
    ($this->change)();
    ($this->change)('14:00');
    $review = $this->appointment->fresh();
    expect($review->global_plus_review_version)->toBe(2)->and($review->global_plus_review_pending)->toBeTrue()
        ->and($review->global_plus_review['before']['starts_at'])->toContain('10:00')
        ->and($review->global_plus_review['after']['starts_at'])->toContain('14:00');
    $this->get(route('planner.appointment-changes'))->assertOk()->assertSee('LOT DEMO')->assertSee('Société DEMO')->assertSee('V2');
    expect(app(SidebarNotifications::class)->forUser($this->planner)['appointment_changes'])->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('À reporter sur Global+');
    expect($review->toArray())->not->toHaveKeys(['global_plus_review', 'global_plus_review_pending', 'global_plus_review_version']);
});

it('requires an explicit current-version acknowledgement and rearms after a later technician change', function () {
    ($this->change)();
    $url = route('planner.appointment-changes.update', $this->appointment->id);
    $beforeAck = $this->appointment->fresh()->updated_at->toIso8601String();
    $this->travel(2)->seconds();
    $this->patch($url, ['version' => 1])->assertSessionHasErrors('confirmed');
    $this->patch($url, ['version' => 1, 'confirmed' => 1])->assertRedirect();
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeFalse()
        ->and($this->appointment->fresh()->updated_at->toIso8601String())->toBe($beforeAck)
        ->and($this->dossier->activities()->where('kind', 'global_plus_change_acknowledged')->count())->toBe(1);
    $this->patch($url, ['version' => 1, 'confirmed' => 1])->assertRedirect();
    expect($this->dossier->activities()->where('kind', 'global_plus_change_acknowledged')->count())->toBe(1);
    $other = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->appointment->refresh()->update(['technician_id' => $other->id]);
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(2)
        ->and($this->appointment->fresh()->global_plus_review_pending)->toBeTrue()
        ->and($this->appointment->fresh()->global_plus_review['before']['starts_at'])->toContain('12:00')
        ->and($this->appointment->fresh()->global_plus_review['after']['technician_id'])->toBe($other->id);
    $this->patch($url, ['version' => 1, 'confirmed' => 1])->assertSessionHasErrors('version');
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeTrue();
});

it('counts the same pending withdrawn visits that appear in the shared queue and filters lot names literally', function () {
    ($this->change)();
    $this->appointment->delete();
    $this->getJson(route('planner.incidents.counts'))->assertOk()->assertJsonPath('appointment_changes.pending', 1)
        ->assertJsonPath('appointment_changes.revision', 1);
    $this->get(route('planner.appointment-changes', ['q' => 'LOT DEMO']))->assertOk()->assertSee('Société DEMO');
    $this->get(route('planner.appointment-changes', ['q' => '%']))->assertOk()->assertSee('Aucun changement');
});

it('rolls review metadata back together with the appointment and retains unrelated external proof', function () {
    $this->appointment->update(['external_payload' => ['proof' => 'keep']]);
    DB::beginTransaction();
    ($this->change)();
    DB::rollBack();
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0)
        ->and($this->appointment->fresh()->starts_at->format('H:i'))->toBe('10:00');
    ($this->change)();
    expect($this->appointment->fresh()->external_payload)->toBe(['proof' => 'keep']);
});

it('keeps shared review acknowledgement private and visible in the completed view', function () {
    ($this->change)();
    $colleague = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($colleague)->patch(route('planner.appointment-changes.update', $this->appointment->id), ['version' => 1, 'confirmed' => 1])->assertRedirect();
    $audit = $this->dossier->activities()->where('kind', 'global_plus_change_acknowledged')->sole();
    expect($audit->author_id)->toBe($colleague->id)->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse();
    $this->get(route('planner.appointment-changes', ['status' => 'done']))->assertOk()->assertSee('Report confirmé');
    expect(app(SidebarNotifications::class)->forUser($this->planner)['appointment_changes'])->toBe(0);
});

it('does not create reviews from external imports or MAR observations', function () {
    $this->appointment->update(['external_source' => 'mymar']);
    ($this->change)();
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
    $this->appointment->update(['external_source' => 'native']);
    auth()->forgetGuards();
    ($this->change)('15:00');
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
});

it('denies technicians and external accounts access to reviews and acknowledgement', function (int $role) {
    ($this->change)();
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    $this->actingAs($user)->get(route('planner.appointment-changes'))->assertForbidden();
    $this->actingAs($user)->patch(route('planner.appointment-changes.update', $this->appointment->id), ['version' => 1, 'confirmed' => 1])->assertForbidden();
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeTrue();
})->with([2, 3, 4]);
