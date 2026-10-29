<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\ClientPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'app.timezone' => 'Europe/Paris']);
    $this->travelTo(Carbon::parse('2026-10-29 15:00:00', 'Europe/Paris'));
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'APPOINTMENT-LIST',
        'kind' => 'client', 'name' => 'Organisation test', 'is_active' => true,
        'portal_categories' => ['pending', 'problem', 'awaiting_visit', 'visited', 'report', 'cancelled']]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client')]);
    $this->technician = User::factory()->create(['role' => 2, 'admin' => false, 'first_name' => 'PRIVATE TECHNICIAN']);
    $this->service = Service::create(['name' => 'Inspection test', 'type' => 'COFRAC', 'average_duration_minutes' => 60]);
    $this->newDossier = function (string $label = 'Beneficiaire test', string $status = 'RDV ATTENTE VISITE'): Dossier {
        $dossier = Dossier::create(['reference' => 'DOS-'.bin2hex(random_bytes(5)), 'company_name' => $label,
            'delegataire_id' => $this->organisation->id, 'address' => '1 rue de test', 'city' => 'Lyon']);
        $dossier->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => $status])->save();

        return $dossier;
    };
    $this->newAppointment = function (Dossier $dossier, string $start, array $extra = []): Appointment {
        $appointment = Appointment::make(['service_id' => $this->service->id, 'technician_id' => $this->technician->id,
            'created_by' => $this->technician->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Client',
            'customer_phone' => '0600000000', 'address' => 'PRIVATE APPOINTMENT ADDRESS',
            'starts_at' => $start, 'ends_at' => Carbon::parse($start)->addHour(), 'duration_minutes' => 60,
            'status' => Appointment::STATUS_SCHEDULED, 'comment' => 'PRIVATE APPOINTMENT COMMENT'])
            ->forceFill(['dossier_operation_id' => $dossier->operations()->value('id'), ...$extra]);
        $appointment->save();

        return $appointment;
    };
    $this->dossier = ($this->newDossier)();
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('shows date and time in both external spaces without opening the dossier', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $this->external->forceFill(['portal_permissions' => ClientAccess::defaults($kind)])->save();
    $appointment = ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()
        ->assertSee('Date RDV')->assertSee('03/11/2026')->assertSee('16:30')->assertSee('17:30')->assertSee('RDV prévu')
        ->assertSee('2026-11-03T16:30:00+01:00', false)
        ->assertDontSee('PRIVATE APPOINTMENT COMMENT')->assertDontSee('PRIVATE APPOINTMENT ADDRESS')->assertDontSee('PRIVATE TECHNICIAN')
        ->assertViewHas('dossiers', fn ($rows) => $rows->first()->appointments->sole()->id === $appointment->id);
})->with(['client', 'delegataire']);

it('also shows the appointment when the client uses the distinct client assignment', function () {
    $this->dossier->update(['client_id' => $this->organisation->id, 'delegataire_id' => null]);
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('03/11/2026')->assertSee('16:30');
});

it('selects the nearest current appointment instead of cancelled problem or obsolete slots', function () {
    ($this->newAppointment)($this->dossier, '2026-10-25 08:00:00');
    ($this->newAppointment)($this->dossier, '2026-10-30 08:00:00', ['status' => Appointment::STATUS_CANCELLED]);
    ($this->newAppointment)($this->dossier, '2026-10-30 09:00:00', ['status' => Appointment::STATUS_PROBLEM]);
    ($this->newAppointment)($this->dossier, '2026-10-30 10:00:00', ['deleted_at' => now()]);
    ($this->newAppointment)($this->dossier, '2026-10-30 11:00:00', ['deleted_at' => now(), 'external_source' => 'coffrac_archive']);
    $next = ($this->newAppointment)($this->dossier, '2026-11-02 14:00:00');
    ($this->newAppointment)($this->dossier, '2026-11-03 14:00:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('02/11/2026')
        ->assertDontSee('30/10/2026')->assertDontSee('25/10/2026')->assertDontSee('03/11/2026')
        ->assertViewHas('dossiers', fn ($rows) => $rows->first()->appointments->sole()->id === $next->id);
});

it('keeps an ongoing visit ahead of later appointments', function () {
    $ongoing = ($this->newAppointment)($this->dossier, '2026-10-29 14:45:00');
    ($this->newAppointment)($this->dossier, '2026-10-29 16:00:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('14:45')
        ->assertViewHas('dossiers', fn ($rows) => $rows->first()->appointments->sole()->id === $ongoing->id);
});

it('shows the latest past appointment for a completed dossier', function () {
    $this->dossier->operations()->first()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    ($this->newAppointment)($this->dossier, '2026-10-20 08:00:00');
    $latest = ($this->newAppointment)($this->dossier, '2026-10-24 09:30:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('24/10/2026')->assertSee('Dernier RDV enregistré')
        ->assertDontSee('RDV prévu')->assertViewHas('dossiers', fn ($rows) => $rows->first()->appointments->sole()->id === $latest->id);
});

it('labels retained legacy dates as historical rather than confirmed upcoming visits', function () {
    $this->dossier->operations()->first()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    ($this->newAppointment)($this->dossier, '2026-10-24 09:30:00', ['deleted_at' => now(), 'external_source' => 'coffrac_archive']);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('24/10/2026')->assertSee('Date historique')->assertDontSee('RDV prévu');
});

it('prefers a retained native visit over an obsolete historical copy', function () {
    $current = ($this->newAppointment)($this->dossier, '2026-10-20 08:00:00');
    ($this->newAppointment)($this->dossier, '2026-10-24 09:30:00', ['deleted_at' => now(), 'external_source' => 'coffrac_archive']);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('20/10/2026')
        ->assertViewHas('dossiers', fn ($rows) => $rows->first()->appointments->sole()->id === $current->id);
});

it('does not present a retained scheduled slot as confirmed after a manual return to an unplanned state', function (string $status) {
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->dossier->operations()->first()->forceFill(['legacy_status' => $status])->save();
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('03/11/2026')->assertSee('16:30')->assertDontSee('RDV prévu')
        ->assertSee($status === 'DOSSIER ANNULE' ? 'RDV annulé' : 'Ancien créneau');
})->with(['PRISE DE RDV', 'PROBLÈME RENDEZ-VOUS', 'DOSSIER ANNULE']);

it('shows a clear placeholder for a dossier without an appointment', function () {
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('Aucun RDV confirmé');
});

it('reflects changed times on a subsequent filtered or ajax load', function () {
    $appointment = ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('16:30');
    $appointment->update(['starts_at' => '2026-11-04 11:15:00', 'ends_at' => '2026-11-04 12:15:00']);
    $this->get(route('client.dossiers', ['status' => 'awaiting_visit']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
        ->assertSee('04/11/2026')->assertSee('11:15')->assertSee('12:15')->assertDontSee('16:30');
});

it('does not leak dates from other organisations or forbidden categories', function () {
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->dossier->update(['delegataire_id' => null]);
    $forbidden = ($this->newDossier)('Dossier interdit', 'CONTROLE RT');
    ($this->newAppointment)($forbidden, '2026-11-05 16:30:00');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertDontSee('03/11/2026')->assertDontSee('05/11/2026')->assertDontSee('Dossier interdit');
});

it('keeps a local Paris time without shifting it when PHP casts use UTC', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00', ['ends_at' => '2026-11-03 17:30:00']);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('16:30')->assertSee('17:30')
        ->assertSee('2026-11-03T16:30:00+01:00', false);
});

it('includes the end date when a visit crosses midnight', function () {
    ($this->newAppointment)($this->dossier, '2026-11-03 23:30:00', ['ends_at' => '2026-11-04 00:30:00']);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('03/11/2026')->assertSee('04/11/2026')->assertSee('00:30');
});

it('loads one appointment per dossier in a single bounded eager query without lazy loading', function () {
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->external->load('externalClient');
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(ClientPortal::class)->dossiers($this->external, []);
    $oneDossierQueries = count(DB::getQueryLog());
    for ($i = 0; $i < 26; $i++) {
        $dossier = ($this->newDossier)('Beneficiaire '.$i);
        ($this->newAppointment)($dossier, '2026-11-03 16:30:00');
        ($this->newAppointment)($dossier, '2026-11-05 08:30:00');
    }
    DB::flushQueryLog();
    $page = app(ClientPortal::class)->dossiers($this->external, [])['dossiers'];
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($page->total())->toBe(27)->and($page->count())->toBe(25)->and(count($queries))->toBe($oneDossierQueries);
    $appointmentQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from "appointments"')));
    expect($appointmentQueries)->toHaveCount(1)->and($appointmentQueries[0]['query'])->toContain('row_number() over', 'laravel_row');
    foreach ($page as $dossier) {
        expect($dossier->relationLoaded('appointments'))->toBeTrue()->and($dossier->appointments)->toHaveCount(1);
        foreach (['comment', 'external_payload', 'customer_phone', 'address'] as $field) {
            expect(array_key_exists($field, $dossier->appointments->first()->getAttributes()))->toBeFalse();
        }
    }
});

it('retains date visibility in every accessible category for both kinds of external accounts', function (string $kind, string $state, string $key) {
    $this->organisation->update(['kind' => $kind, 'portal_categories' => [$key]]);
    $this->dossier->operations()->first()->forceFill(['legacy_status' => $state])->save();
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00');
    $this->actingAs($this->external)->get(route('client.dossiers', ['status' => $key]))->assertOk()
        ->assertSee('Date RDV')->assertSee('03/11/2026')->assertSee('16:30');
})->with(['client', 'delegataire'])->with([
    ['PRISE DE RDV', 'pending'], ['PROBLÈME RENDEZ-VOUS', 'problem'], ['RDV ATTENTE VISITE', 'awaiting_visit'],
    ['RDV VISITE', 'visited'], ['CONTROLE RT', 'rt_control'], ['NON CONFORMITES', 'non_compliant'],
    ['RECONTROLE', 'recontrol'], ['RAPPORT GENIUS', 'report'], ['DOSSIER ANNULE', 'cancelled'],
]);

it('retains cancelled and problem appointment dates but never calls them confirmed', function (string $status) {
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00', ['status' => $status]);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('03/11/2026')->assertDontSee('RDV prévu');
})->with([Appointment::STATUS_CANCELLED, Appointment::STATUS_PROBLEM]);

it('labels deleted native appointments as historical without returning their private data', function () {
    ($this->newAppointment)($this->dossier, '2026-11-03 16:30:00', ['deleted_at' => now()]);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('03/11/2026')->assertSee('Date historique')
        ->assertDontSee('RDV prévu')->assertDontSee('PRIVATE APPOINTMENT COMMENT');
});
