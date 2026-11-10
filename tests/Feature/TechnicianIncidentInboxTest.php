<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierBilling;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\SidebarNotifications;
use App\Services\Tech\TechnicianIncidentInbox;
use App\Services\Tech\TechnicianOnSiteReports;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->admin = User::factory()->create(['role' => 0, 'admin' => true, 'must_change_password' => false]);
    $this->planner = User::factory()->create(['role' => 1, 'admin' => false, 'must_change_password' => false, 'can_manage_billing' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'must_change_password' => false]);
    $this->dossier = Dossier::create(['reference' => 'INCIDENT-001', 'first_name' => 'Camille', 'last_name' => 'Exemple',
        'address' => 'Rue fictive', 'postal_code' => '75001', 'city' => 'Paris']);
    $service = Service::create(['type' => 'COFRAC', 'name' => 'Fiche test', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $this->operation->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $this->appointment = Appointment::forceCreate([
        'technician_id' => $this->tech->id, 'created_by' => $this->planner->id, 'dossier_operation_id' => $this->operation->id,
        'service_id' => $service->id, 'starts_at' => '2026-11-09 10:00', 'ends_at' => '2026-11-09 11:00',
        'duration_minutes' => 60, 'customer_first_name' => 'Camille', 'customer_last_name' => 'Exemple',
        'customer_phone' => '', 'address' => 'Rue fictive', 'status' => 'problem',
    ]);
    $this->document = $this->dossier->documents()->create(['author_id' => $this->tech->id, 'document_type' => 'free',
        'dossier_operation_id' => $this->operation->id,
        'name' => 'Photo de présence', 'path' => 'private/incident.jpg', 'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size' => 100]);
    $this->makeIncident = fn (array $attributes = []) => TechnicianIncident::create([...[
        'request_id' => (string) Str::uuid(), 'dossier_id' => $this->dossier->id, 'appointment_id' => $this->appointment->id,
        'technician_id' => $this->tech->id, 'document_id' => $this->document->id, 'reason' => 'absent',
        'comment' => 'Appels sans réponse', 'reported_at' => '2026-11-09 10:10', 'appointment_starts_at' => $this->appointment->starts_at,
    ], ...$attributes]);
    $this->incident = ($this->makeIncident)();
    $this->actingAs($this->admin);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('shows a shared inbox with proofs and an independent accounting indicator', function () {
    $this->get(route('planner.incidents'))->assertOk()->assertSee('Signalements techniciens')
        ->assertSee('Camille Exemple')->assertSee('Appels sans réponse')->assertSee('Déplacement à vérifier')
        ->assertSee(route('v2.documents.download', ['document' => $this->document, 'preview' => 1]), false);
    $this->getJson(route('planner.incidents.counts'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('pending', 1)->assertJsonPath('billing', 1)->assertJsonPath('latest_id', $this->incident->id);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('1 déplacement(s) à vérifier / facturer');
    expect(app(SidebarNotifications::class)->forUser($this->admin)['incidents']['pending'])->toBe(1);
});

it('never exposes incident or accounting data to technicians external or password gated accounts', function ($kind) {
    $actor = match ($kind) {
        'tech' => $this->tech,
        'external' => User::factory()->create(['role' => 3, 'admin' => false, 'must_change_password' => false, 'portal_enabled' => true,
            'external_client_id' => ExternalDelegataire::create(['source' => 'native', 'external_id' => 'incident-client', 'kind' => 'client', 'name' => 'Société', 'is_active' => true])->id]),
        'password' => User::factory()->create(['role' => 0, 'admin' => true, 'must_change_password' => true]),
        'v1' => $this->admin,
    };
    if ($kind === 'v1') {
        config(['crm.v2_enabled' => false]);
    }
    $this->actingAs($actor)->getJson(route('planner.incidents'))->assertForbidden();
    $this->getJson(route('planner.incidents.counts'))->assertForbidden();
    $this->patchJson(route('planner.incidents.update', $this->incident), ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'X'])->assertForbidden();
    expect($this->incident->fresh()->planning_status)->toBe('pending');
})->with(['tech', 'external', 'password', 'v1']);

it('explains the restricted inbox to technicians and sends them back to their own workspace without leaking reports', function () {
    config(['app.debug' => false]);
    $this->actingAs($this->tech)->get(route('planner.incidents'))->assertForbidden()
        ->assertSee('réservée au planning et aux administrateurs')
        ->assertSee('ouvrez votre rendez-vous depuis votre planning technicien')
        ->assertDontSee('Appels sans réponse')->assertDontSee('Camille Exemple');
    $this->get(route('dashboard'))->assertRedirect(route('tech.planning'));
    $this->get(route('tech.planning'))->assertOk()->assertDontSee('href="'.route('planner.incidents').'"', false);
});

it('allows planning followup but hides and denies accounting decisions without permission', function () {
    $this->incident->update(['billing_note' => 'Confidentiel comptabilité']);
    $this->actingAs($this->planner)->get(route('planner.incidents'))->assertOk()->assertDontSee('Confidentiel comptabilité')->assertDontSee('Suivi du déplacement · Comptabilité');
    $this->getJson(route('planner.incidents.counts'))->assertOk()->assertJsonPath('billing', null);
    $this->getJson(route('planner.incidents', ['billing' => 'review']))->assertForbidden();
    $this->patchJson(route('planner.incidents.update', $this->incident), ['section' => 'billing', 'version' => 1, 'status' => 'invoiced', 'note' => 'Interdit'])->assertForbidden();
    $this->patch(route('planner.incidents.update', $this->incident), ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'Société contactée'])->assertRedirect();
    expect($this->incident->fresh()->planning_status)->toBe('resolved')->and($this->incident->fresh()->billing_status)->toBe('review');
});

it('keeps the accounting alert after planning resolution without changing dossier status billing or version', function () {
    $version = $this->dossier->fresh()->version;
    $this->patch(route('planner.incidents.update', $this->incident), ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'Client rappelé'])->assertRedirect();
    $this->getJson(route('planner.incidents.counts'))->assertJsonPath('pending', 0)->assertJsonPath('billing', 1);
    $this->get(route('planner.incidents'))->assertDontSee('Appels sans réponse');
    $this->get(route('planner.incidents', ['status' => 'all', 'billing' => 'review']))->assertSee('Appels sans réponse');
    $audit = $this->dossier->activities()->sole();
    expect($audit->kind)->toBe('on_site_reviewed')->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse()
        ->and($audit->changed_fields['incident_id'])->toBe($this->incident->id)
        ->and($this->dossier->fresh()->version)->toBe($version)->and($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and(DossierBilling::count())->toBe(0)->and($this->appointment->fresh()->status)->toBe('problem');
});

it('permits explicit billing decisions only for authorised accounts and never creates an invoice', function ($role) {
    $actor = $role === 'admin' ? $this->admin : User::factory()->create(['role' => $role === 'manager' ? 0 : 1,
        'admin' => false, 'must_change_password' => false, 'can_manage_billing' => $role === 'accounting']);
    $this->actingAs($actor);
    foreach (['billable', 'invoiced', 'waived', 'review'] as $index => $status) {
        $this->patch(route('planner.incidents.update', $this->incident), ['section' => 'billing', 'version' => $index + 1,
            'status' => $status, 'note' => 'Décision vérifiée · facture FAC-TEST'])->assertRedirect();
        expect($this->incident->fresh()->billing_status)->toBe($status)->and($this->incident->fresh()->planning_status)->toBe('pending')
            ->and($this->incident->fresh()->billing_reviewed_by)->toBe($actor->id);
    }
    expect(DossierBilling::count())->toBe(0);
})->with(['admin', 'manager', 'accounting']);

it('rejects stale concurrent changes and unrecognised states without losing the previous decision', function () {
    $url = route('planner.incidents.update', $this->incident);
    $this->patch($url, ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'Validé'])->assertRedirect();
    $this->patchJson($url, ['section' => 'billing', 'version' => 1, 'status' => 'invoiced', 'note' => 'Obsolète'])->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->patchJson($url, ['section' => 'billing', 'version' => 2, 'status' => 'unknown', 'note' => 'X'])->assertUnprocessable();
    $this->patchJson($url, ['section' => 'planning', 'version' => 2, 'status' => 'pending', 'note' => ''])->assertUnprocessable();
    expect($this->incident->fresh()->version)->toBe(2)->and($this->incident->fresh()->billing_status)->toBe('review');
});

it('rechecks a revoked billing grant against SQL even when passed a stale account object', function () {
    $actor = User::factory()->create(['role' => 1, 'admin' => false, 'must_change_password' => false, 'can_manage_billing' => true]);
    DB::table('users')->where('id', $actor->id)->update(['can_manage_billing' => false]);
    expect(fn () => app(TechnicianIncidentInbox::class)->update($actor, $this->incident,
        ['section' => 'billing', 'version' => 1, 'status' => 'invoiced', 'note' => 'Interdit']))
        ->toThrow(AuthorizationException::class);
    expect($this->incident->fresh()->billing_status)->toBe('review')->and($this->incident->fresh()->version)->toBe(1);
});

it('rolls back a decision if the mandatory private audit cannot be saved', function () {
    $event = 'eloquent.creating: '.DossierActivity::class;
    Event::listen($event, function () {
        throw new RuntimeException('Simulated audit failure');
    });
    try {
        expect(fn () => app(TechnicianIncidentInbox::class)->update($this->admin, $this->incident,
            ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'Ne pas perdre la preuve']))
            ->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect($this->incident->fresh()->planning_status)->toBe('pending')->and($this->incident->fresh()->version)->toBe(1);
});

it('supports bounded pagination dates reasons dossier and literal text filters', function () {
    for ($i = 0; $i < 21; $i++) {
        ($this->makeIncident)(['reason' => 'refused', 'reported_at' => '2026-11-08 09:00']);
    }
    $this->get(route('planner.incidents'))->assertOk()->assertViewHas('incidents', fn ($rows) => $rows->count() === 20 && $rows->total() === 22);
    foreach ([['q' => 'Camille Exemple'], ['q' => 'Exemple Camille'], ['q' => 'Rue fictive 75001 Paris'], ['dossier_id' => $this->dossier->id]] as $filters) {
        $this->get(route('planner.incidents', $filters))->assertViewHas('incidents', fn ($rows) => $rows->total() === 22);
    }
    $this->get(route('planner.incidents', ['from' => '2026-11-09', 'to' => '2026-11-09', 'reason' => 'absent']))->assertViewHas('incidents', fn ($rows) => $rows->total() === 1);
    $this->get(route('planner.incidents', ['q' => '%']))->assertViewHas('incidents', fn ($rows) => $rows->total() === 0);
    $this->get(route('planner.incidents', ['to' => '2026-11-08']))->assertViewHas('incidents', fn ($rows) => $rows->total() === 21);
    $this->getJson(route('planner.incidents', ['from' => 'invalid']))->assertUnprocessable();
});

it('loads a full page without per incident relationship queries', function () {
    for ($i = 0; $i < 21; $i++) {
        ($this->makeIncident)();
    }
    DB::enableQueryLog();
    $this->get(route('planner.incidents'))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThan(40);
});

it('persists real mobile reports in the same transaction as their proof and acknowledges retries', function () {
    Storage::fake('local');
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 15));
    $this->appointment->update(['status' => 'scheduled']);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $payload = ['request_id' => (string) Str::uuid(), 'snapshot' => app(TechnicianOnSiteReports::class)->snapshot($this->appointment),
        'reason' => 'refused', 'comment' => 'Le client refuse.', 'audience' => 'internal',
        'photo' => UploadedFile::fake()->image('preuve.png', 40, 40)];
    $url = route('tech.appointments.on-site-report', $this->appointment);
    $this->actingAs($this->tech)->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    expect(TechnicianIncident::where('request_id', $payload['request_id'])->count())->toBe(1)
        ->and($this->dossier->comments()->count())->toBe(1);
    $created = TechnicianIncident::where('request_id', $payload['request_id'])->sole();
    Storage::disk('local')->assertExists($created->document->path);
    expect($created->billing_status)->toBe('review')->and($created->planning_status)->toBe('pending');
});

it('escapes comments and followup notes and preserves access to soft deleted historical dossiers', function () {
    $this->incident->update(['comment' => '<script>bad()</script>', 'planning_note' => '</textarea><img src=x onerror=bad()>']);
    $this->dossier->delete();
    $this->tech->delete();
    $this->get(route('planner.incidents'))->assertOk()->assertSee('&lt;script&gt;bad()', false)
        ->assertSee('&lt;/textarea&gt;&lt;img', false)->assertDontSee('<script>bad()', false);
    $this->patch(route('planner.incidents.update', $this->incident), ['section' => 'billing', 'version' => 1, 'status' => 'waived', 'note' => 'Dossier annulé'])->assertRedirect();
});

it('backfills only coherent legacy proofs idempotently without changing appointments or emitting messages', function () {
    $this->incident->delete();
    $report = ['request_id' => (string) Str::uuid(), 'author_id' => $this->tech->id, 'document_id' => $this->document->id,
        'reason' => 'absent', 'reported_at' => '2026-11-09T10:10:00+01:00'];
    $this->appointment->update(['external_payload' => ['techcalendar_on_site' => $report]]);
    $before = $this->appointment->fresh()->getAttributes();
    $this->artisan('tech:backfill-incidents')->assertSuccessful();
    expect(TechnicianIncident::count())->toBe(0);
    $this->artisan('tech:backfill-incidents', ['--apply' => true])->assertSuccessful();
    $this->artisan('tech:backfill-incidents', ['--apply' => true])->assertSuccessful();
    expect(TechnicianIncident::count())->toBe(1)->and(TechnicianIncident::sole()->reported_at->format('H:i'))->toBe('10:10')
        ->and($this->appointment->fresh()->getAttributes())->toBe($before)->and($this->dossier->activities()->count())->toBe(0);
    TechnicianIncident::query()->delete();
    $this->document->update(['author_id' => $this->planner->id]);
    $this->artisan('tech:backfill-incidents', ['--apply' => true])->assertSuccessful();
    expect(TechnicianIncident::count())->toBe(0);
});
