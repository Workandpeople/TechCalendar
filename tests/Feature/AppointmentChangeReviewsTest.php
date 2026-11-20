<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\AppointmentChangeReviews;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\DossierWorkflow;
use App\Services\SidebarNotifications;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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
    $this->operation = $operation;
    $this->appointment = Appointment::forceCreate(['service_id' => $service->id, 'technician_id' => $this->tech->id,
        'dossier_operation_id' => $operation->id, 'created_by' => $this->planner->id, 'external_source' => 'native',
        'customer_last_name' => 'DEMO', 'customer_first_name' => '', 'customer_phone' => '', 'address' => 'Adresse fictive',
        'starts_at' => '2026-11-24 10:00', 'ends_at' => '2026-11-24 11:00', 'duration_minutes' => 60, 'status' => 'scheduled']);
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

it('flags business status changes without touching the visit or sending additional notifications', function (string $status) {
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $before = $this->appointment->fresh()->getAttributes();
    $this->travel(1)->minutes();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => $status]);
    $appointment = $this->appointment->fresh();
    expect(Arr::except($appointment->getAttributes(), ['global_plus_review', 'global_plus_review_version', 'global_plus_review_pending']))->toBe(
        Arr::except($before, ['global_plus_review', 'global_plus_review_version', 'global_plus_review_pending']))
        ->and($appointment->global_plus_review_version)->toBe(1)
        ->and($appointment->global_plus_review_pending)->toBeTrue()
        ->and($appointment->global_plus_review['before']['operation_status'])->toBe('RDV ATTENTE VISITE')
        ->and($appointment->global_plus_review['after']['operation_status'])->toBe(DossierStatusCatalog::STATUSES[$status]['values'][0]);
    $this->getJson(route('planner.incidents.counts'))->assertOk()->assertJsonPath('appointment_changes.pending', 1);
    $this->get(route('planner.appointment-changes'))->assertOk()->assertSee('État de la prestation')
        ->assertSee('RDV en attente de visite')->assertSee(DossierStatusCatalog::STATUSES[$status]['label']);
})->with(['problem', 'cancelled']);

it('ignores cancellation when the previous business state was not awaiting visit', function () {
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => 'cancelled']);
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeFalse();
});

it('coalesces status and scheduling changes and rearms after a confirmed status', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    ($this->change)();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => 'problem']);
    $this->appointment->refresh()->update(['status' => Appointment::STATUS_PROBLEM]);
    $appointment = $this->appointment->fresh();
    expect($appointment->global_plus_review_version)->toBe(2)
        ->and($appointment->global_plus_review['before']['starts_at'])->toContain('10:00')
        ->and($appointment->global_plus_review['before']['operation_status'])->toBe('RDV ATTENTE VISITE')
        ->and($appointment->global_plus_review['before']['appointment_status'])->toBe('scheduled')
        ->and($appointment->global_plus_review['after']['starts_at'])->toContain('12:00')
        ->and($appointment->global_plus_review['after']['operation_status'])->toBe('Problème rendez- vous')
        ->and($appointment->global_plus_review['after']['appointment_status'])->toBe('scheduled')
        ->and(app(AppointmentChangeReviews::class)->counts($this->planner)['pending'])->toBe(1);
    $url = route('planner.appointment-changes.update', $appointment->id);
    $this->patch($url, ['version' => 1, 'confirmed' => 1])->assertSessionHasErrors('version');
    $this->patch($url, ['version' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier->fresh(), $this->operation, ['version' => 2, 'status' => 'cancelled']);
    $appointment->refresh();
    expect($appointment->global_plus_review_version)->toBe(2)->and($appointment->global_plus_review_pending)->toBeFalse();
});

it('ignores internal appointment statuses without the required business transition', function (string $status) {
    $this->appointment->update(['status' => $status]);
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
})->with(['problem', 'cancelled']);

it('only flags the affected prestation and its current visit rather than historical visits', function () {
    $historical = $this->appointment->replicate();
    $historical->forceFill(['status' => 'problem', 'starts_at' => '2027-01-01 10:00'])->save();
    $otherOperation = $this->dossier->operations()->create(['service_id' => $this->operation->service_id]);
    $other = $this->appointment->replicate();
    $other->forceFill(['dossier_operation_id' => $otherOperation->id])->save();
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => 'cancelled']);
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeTrue()
        ->and($historical->fresh()->global_plus_review_pending)->toBeFalse()
        ->and($other->fresh()->global_plus_review_pending)->toBeFalse();
});

it('ignores equivalent statuses repeated saves comments and prestations without appointments', function () {
    $this->operation->forceFill(['legacy_status' => 'Problème rendez- vous'])->save();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => 'problem']);
    $this->dossier->activities()->create(['author_id' => $this->planner->id, 'kind' => 'comment', 'body' => 'Note']);
    $other = $this->dossier->operations()->create(['service_id' => $this->operation->service_id]);
    $other->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier->fresh(), $other, ['version' => 1, 'status' => 'cancelled']);
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
});

it('rolls back the status activity and its review together', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    DB::beginTransaction();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => 'cancelled']);
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeTrue();
    DB::rollBack();
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0)
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and($this->dossier->activities()->count())->toBe(0);
});

it('does not flag initial placement but keeps the final status of a reviewed replacement', function () {
    $record = function (string $previous) {
        $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
        $this->dossier->activities()->create(['author_id' => $this->planner->id, 'kind' => 'appointment_linked', 'body' => 'Placement de test',
            'changed_fields' => ['operation_id' => $this->operation->id, 'appointment_id' => $this->appointment->id,
                'previous_status' => $previous, 'status' => 'RDV ATTENTE VISITE']]);
    };
    $record('PRISE DE RDV');
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
    $this->operation->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    ($this->change)();
    $record('PROBLÈME RENDEZ-VOUS');
    expect($this->appointment->fresh()->global_plus_review['before']['operation_status'])->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($this->appointment->fresh()->global_plus_review['after']['operation_status'])->toBe('PROBLÈME RENDEZ-VOUS');
});

it('captures post visit status transitions without exposing proof data in the review', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $this->dossier->activities()->create(['author_id' => $this->planner->id, 'kind' => 'post_visit', 'body' => 'Visite de test',
        'changed_fields' => ['operation_id' => $this->operation->id,
            'before' => ['legacy_status' => 'RDV ATTENTE VISITE'], 'after' => ['legacy_status' => 'RDV VISITE'],
            'visit_document_id' => 123, 'control_comment' => 'Private proof not for Global+']]);
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
    $this->get(route('planner.appointment-changes'))->assertOk()->assertSee('Aucun changement')->assertDontSee('Private proof not for Global+');

});

it('ignores status imports MAR observations stale activities and actors without planning or assigned report rights', function (string $case) {
    $actor = $this->planner;
    if ($case === 'mar') {
        $this->appointment->update(['external_source' => 'mymar']);
    } elseif ($case === 'no-author') {
        $actor = null;
    } elseif ($case === 'unassigned-tech' || $case === 'external') {
        $actor = User::factory()->create(['role' => $case === 'external' ? 3 : 2, 'admin' => false]);
    } elseif ($case === 'disabled') {
        config(['crm.v2_enabled' => false]);
    }
    $this->operation->forceFill(['legacy_status' => $case === 'stale' ? 'RAPPORT GENIUS' : 'DOSSIER ANNULE'])->save();
    $this->dossier->activities()->create(['author_id' => $actor?->id, 'kind' => 'status_changed', 'body' => 'Statut de test',
        'changed_fields' => ['operation_id' => $this->operation->id, 'appointment_id' => $this->appointment->id,
            'previous_status' => 'RDV ATTENTE VISITE', 'status' => 'DOSSIER ANNULE']]);
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
})->with(['mar', 'no-author', 'unassigned-tech', 'external', 'disabled', 'stale']);

it('renders legacy reviews without inventing historical status values and escapes unknown labels', function () {
    ($this->change)();
    $review = $this->appointment->fresh()->global_plus_review;
    foreach (['before', 'after'] as $side) {
        unset($review[$side]['operation_status'], $review[$side]['appointment_status']);
    }
    $this->appointment->forceFill(['global_plus_review' => $review])->saveQuietly();
    $this->get(route('planner.appointment-changes'))->assertOk()->assertDontSee('État de la prestation');
    $review['before']['operation_status'] = '<script>unsafe</script>';
    $this->appointment->forceFill(['global_plus_review' => $review])->saveQuietly();
    $this->get(route('planner.appointment-changes'))->assertOk()->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)
        ->assertDontSee('<script>unsafe</script>', false);
});

it('orders status only reviews by the time of the change not by the unchanged appointment timestamp', function () {
    $other = $this->appointment->replicate();
    $other->forceFill(['updated_at' => now()->addDay()])->save();
    $other->update(['starts_at' => '2026-11-24 15:00']);
    $other->update(['status' => 'cancelled']);
    $this->travel(2)->minutes();
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => 'cancelled']);
    $this->get(route('planner.appointment-changes'))->assertOk()
        ->assertSeeInOrder(['id="rdv-'.$this->appointment->id.'"', 'id="rdv-'.$other->id.'"'], false);
});

it('does not alert on duration or other business status changes', function (string $status) {
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment->update(['ends_at' => '2026-11-24 11:30', 'duration_minutes' => 90]);
    app(DossierWorkflow::class)->changeStatus($this->planner, $this->dossier, $this->operation, ['version' => 1, 'status' => $status]);
    expect($this->appointment->fresh()->global_plus_review_version)->toBe(0);
})->with(['pending', 'visited', 'report']);

it('confirms all filtered pages atomically and keeps individual confirmation available', function () {
    ($this->change)();
    for ($i = 0; $i < 24; $i++) {
        $a = $this->appointment->replicate();
        $a->save();
    }
    $this->get(route('planner.appointment-changes'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertViewHas('appointments', fn ($p) => $p->count() === 20 && $p->total() === 25)->assertSee('Marquer comme reporté');
    $bulk = app(AppointmentChangeReviews::class)->bulkPreview($this->planner, ['q' => 'LOT DEMO']);
    expect($bulk['count'])->toBe(25);
    $this->patch(route('planner.appointment-changes.bulk'), ['snapshot' => $bulk['snapshot']])->assertSessionHasErrors('confirmed');
    $this->patch(route('planner.appointment-changes.bulk'), ['snapshot' => $bulk['snapshot'], 'confirmed' => 1])->assertSessionHasNoErrors();
    expect(Appointment::where('global_plus_review_pending', true)->count())->toBe(0)
        ->and($this->dossier->activities()->where('kind', 'global_plus_change_acknowledged')->count())->toBe(25);
    $this->patch(route('planner.appointment-changes.bulk'), ['snapshot' => $bulk['snapshot'], 'confirmed' => 1])->assertSessionHasNoErrors();
    expect($this->dossier->activities()->where('kind', 'global_plus_change_acknowledged')->count())->toBe(25);
});

it('rejects an entire bulk when one visit changed and never includes later arrivals', function () {
    ($this->change)();
    $other = $this->appointment->replicate();
    $other->save();
    $bulk = app(AppointmentChangeReviews::class)->bulkPreview($this->planner, []);
    $new = $this->appointment->replicate();
    $new->save();
    $other->update(['starts_at' => '2026-11-24 16:00']);
    $this->patch(route('planner.appointment-changes.bulk'), ['snapshot' => $bulk['snapshot'], 'confirmed' => 1])->assertSessionHasErrors('version');
    expect(Appointment::where('global_plus_review_pending', true)->count())->toBe(3)
        ->and($this->dossier->activities()->where('kind', 'global_plus_change_acknowledged')->count())->toBe(0);
    $bulk = app(AppointmentChangeReviews::class)->bulkPreview($this->planner, []);
    $later = $this->appointment->replicate();
    $later->save();
    $this->patch(route('planner.appointment-changes.bulk'), ['snapshot' => $bulk['snapshot'], 'confirmed' => 1])->assertSessionHasNoErrors();
    expect($later->fresh()->global_plus_review_pending)->toBeTrue();
});

it('binds bulk confirmation to the actor expiry and authenticated selection', function (string $case) {
    ($this->change)();
    $bulk = app(AppointmentChangeReviews::class)->bulkPreview($this->planner, []);
    if ($case === 'actor') {
        $this->actingAs(User::factory()->create(['role' => 1, 'admin' => false]));
    }
    if ($case === 'expired') {
        $this->travel(16)->minutes();
    }
    if ($case === 'tampered') {
        $bulk['snapshot'] .= 'x';
    }
    $this->patch(route('planner.appointment-changes.bulk'), ['snapshot' => $bulk['snapshot'], 'confirmed' => 1])->assertSessionHasErrors('snapshot');
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeTrue();
})->with(['actor', 'expired', 'tampered']);

it('excludes old out of scope histories without clearing them or acknowledging them', function () {
    ($this->change)();
    $review = $this->appointment->fresh()->global_plus_review;
    unset($review['reasons']);
    $review['before']['starts_at'] = $review['after']['starts_at'];
    $review['before']['operation_status'] = 'RDV ATTENTE VISITE';
    $review['after']['operation_status'] = 'RDV VISITE';
    $this->appointment->forceFill(['global_plus_review' => $review])->saveQuietly();
    expect(app(AppointmentChangeReviews::class)->counts($this->planner)['pending'])->toBe(0);
    $this->get(route('planner.appointment-changes'))->assertSee('Aucun changement');
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertDontSee('À reporter sur Global+');
    $this->get(route('planner.appointment-changes', ['status' => 'excluded']))->assertSee('historique conservé')->assertDontSee('Marquer comme reporté');
    expect($this->appointment->fresh()->global_plus_review_pending)->toBeTrue()->and($this->appointment->fresh()->global_plus_review)->toBe($review);
    $this->patch(route('planner.appointment-changes.update', $this->appointment), ['version' => 1, 'confirmed' => 1])->assertUnprocessable();
});
