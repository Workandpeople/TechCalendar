<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\AppointmentComments;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'crm.collected_documents_root' => '', 'crm.collected_documents_manifest' => '']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->staff = User::factory()->create(['first_name' => 'Rose', 'last_name' => 'Interne', 'role' => 0, 'admin' => false]);
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'COMMENT-AUTHOR',
        'name' => 'Societe cliente Alpha', 'kind' => 'client', 'is_active' => true]);
    $this->external = User::factory()->create(['first_name' => '', 'last_name' => '', 'role' => User::ROLE_EXTERNAL,
        'admin' => false, 'external_client_id' => $this->organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('client')]);
    $this->dossier = Dossier::create(['reference' => 'COMMENT-AUTHOR', 'company_name' => 'Beneficiaire du controle',
        'address' => 'Rue Test', 'city' => 'Paris', 'client_id' => $this->organisation->id, 'delegataire_id' => $this->organisation->id]);
    $this->service = Service::create(['name' => 'Visite test', 'type' => 'COFRAC', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->authorNames = function (string $html): array {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
        $xpath = new DOMXPath($dom);

        return array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//*[@id="commentaires"]//*[contains(@class,"gc-comment-meta")]/p')));
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('attributes a portal comment to its actual organisation, even with empty personal names', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $this->external->forceFill(['portal_permissions' => [...ClientAccess::defaults($kind), 'comments' => true, 'add_comments' => true]])->save();
    $this->actingAs($this->external)->post(route('client.dossiers.comments', $this->dossier), [
        'body' => 'Les travaux sont termines.', 'author_id' => $this->staff->id,
    ])->assertRedirect();
    $comment = $this->dossier->comments()->sole();
    expect($comment->author_id)->toBe($this->external->id);
    $response = $this->actingAs($this->staff)->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    expect(($this->authorNames)($response->getContent()))->toBe(['Societe cliente Alpha']);
    $this->get(route('v2.dossiers.index'))->assertOk()->assertSee('Societe cliente Alpha');
    $pending = app(DossierBooking::class)->find($this->staff, 'dossier-'.$this->operation->id, geocode: false);
    expect($pending['comments'][0]['author_name'])->toBe('Societe cliente Alpha');
})->with(['client', 'delegataire']);

it('also resolves old comments and deactivated accounts without rewriting author ids or the body', function () {
    $comment = $this->dossier->activities()->create(['author_id' => $this->external->id, 'kind' => 'comment', 'body' => 'Historique existant']);
    $this->external->delete();
    $before = DB::table('dossier_activities')->where('id', $comment->id)->first();
    $response = $this->actingAs($this->staff)->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    expect(($this->authorNames)($response->getContent()))->toBe(['Societe cliente Alpha'])
        ->and(DB::table('dossier_activities')->where('id', $comment->id)->first())->toEqual($before);
});

it('uses the organisation instead of a personal alias for external authors and retains internal names', function () {
    $this->external->update(['first_name' => 'Contact', 'last_name' => 'Personnel']);
    foreach ([$this->external, $this->staff] as $author) {
        $this->dossier->activities()->create(['author_id' => $author->id, 'kind' => 'comment', 'body' => 'Message']);
    }
    $comments = $this->dossier->comments()->withAuthorLabel()->orderBy('id')->get();
    expect($comments->map->authorLabel()->all())->toBe(['Societe cliente Alpha', 'Rose Interne']);
});

it('never guesses an author from the dossier assignment or exposes an email as a fallback', function () {
    $unknown = $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'Sans auteur']);
    $legacy = $this->dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'Import', 'changed_fields' => ['source_author_id' => 42]]);
    $system = $this->dossier->activities()->create(['kind' => 'updated', 'body' => 'Automatisation']);
    expect($unknown->authorLabel())->toBe('Auteur non renseigné')->and($legacy->authorLabel())->toBe('Auteur Cofrac #42')
        ->and($system->authorLabel())->toBe('Système');
    $this->external->forceFill(['external_client_id' => null, 'first_name' => '', 'last_name' => ''])->save();
    $missingOrganisation = $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'Compte sans nom', 'author_id' => $this->external->id]);
    expect($missingOrganisation->authorLabel())->toBe('Compte #'.$this->external->id);
});

it('escapes organisation names in the detail, preview and accessible delete label', function () {
    $this->organisation->update(['name' => '<script>unsafe-company</script>']);
    $this->dossier->activities()->create(['author_id' => $this->external->id, 'kind' => 'comment', 'body' => 'Message']);
    $this->actingAs($this->staff)->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('&lt;script&gt;unsafe-company&lt;/script&gt;', false)->assertDontSee('<script>unsafe-company</script>', false);
    $this->get(route('v2.dossiers.index'))->assertOk()->assertDontSee('<script>unsafe-company</script>', false);
});

it('uses the same company label for comments in the appointment calendar', function () {
    $this->dossier->activities()->create(['author_id' => $this->external->id, 'kind' => 'comment', 'body' => 'Message planning']);
    $appointment = Appointment::create(['service_id' => $this->service->id, 'technician_id' => $this->staff->id,
        'created_by' => $this->staff->id, 'customer_first_name' => 'Test',
        'customer_last_name' => 'Beneficiaire', 'customer_phone' => '0600000000', 'address' => 'Rue Test',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60, 'status' => 'scheduled']);
    $appointment->forceFill(['dossier_operation_id' => $this->operation->id])->save();
    $this->actingAs($this->staff);
    $comments = app(AppointmentComments::class)->forAppointments(collect([$appointment]));
    expect($comments[$appointment->id][0]['author_name'])->toBe('Societe cliente Alpha');
});

it('loads author organisations in bulk instead of one query per comment', function () {
    for ($i = 0; $i < 12; $i++) {
        $author = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'external_client_id' => $this->organisation->id]);
        $this->dossier->activities()->create(['author_id' => $author->id, 'kind' => 'comment', 'body' => 'Message '.$i]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $comments = $this->dossier->comments()->withAuthorLabel()->get();
    $queries = count(DB::getQueryLog());
    $labels = $comments->map->authorLabel()->unique()->values()->all();
    $after = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries)->toBe(3)->and($after)->toBe($queries)->and($labels)->toBe(['Societe cliente Alpha']);
    foreach ($comments as $comment) {
        expect($comment->author->getAttributes())->not->toHaveKeys(['email', 'password', 'remember_token']);
    }
});
