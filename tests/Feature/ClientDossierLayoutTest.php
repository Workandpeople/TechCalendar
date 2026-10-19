<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'LAYOUT',
        'kind' => 'client', 'name' => 'Organisation test', 'is_active' => true]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('client')]);
    $this->dossier = Dossier::create(['reference' => 'TECHNICAL-REFERENCE-HIDDEN', 'company_name' => 'Résidence des Tilleuls',
        'client_id' => $organisation->id, 'address' => '12 rue des Tilleuls', 'city' => 'Lyon']);
    $this->operation = $this->dossier->operations()->create(['legacy_service_name' => 'Isolation']);
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->url = route('client.dossiers.show', $this->dossier);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('puts uploads responses and messages in labelled dialogs without exposing internal content', function () {
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'INTERNAL-MESSAGE']);
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'My shared response', 'author_id' => $this->external->id, 'client_visible' => true]);
    $response = $this->actingAs($this->external)->get($this->url)->assertOk()
        ->assertSee('Où en est mon dossier ?')->assertSee('Des éléments sont attendus')
        ->assertSee('My shared response')->assertDontSee('INTERNAL-MESSAGE')->assertDontSee('TECHNICAL-REFERENCE-HIDDEN');
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    foreach (['client-document-upload', 'client-comment-create', 'correction-'.$this->operation->id] as $id) {
        expect($xpath->query('//dialog[@id="'.$id.'"]//form')->length)->toBe(1);
    }
    expect($xpath->query('//section[@id="client-documents"]//ul/li')->length)->toBe(0)
        ->and($xpath->query('//li[@data-own="true"]')->length)->toBe(1);
});

it('does not render forbidden sections counts or upload prompts', function () {
    $this->external->forceFill(['portal_permissions' => [...$this->external->portal_permissions,
        'documents' => false, 'comments' => false]])->save();
    $this->actingAs($this->external)->get($this->url)->assertOk()
        ->assertDontSee('id="client-documents"', false)->assertDontSee('href="#client-comments"', false)
        ->assertDontSee('data-document-preview', false)->assertDontSee('Ajouter un document')
        ->assertDontSee('Ajoutez les justificatifs demandés')->assertSee('Un espace adapté à vos accès');
});

it('reopens only the failed dialog and keeps entered values', function (string $form, string $field, string $dialog) {
    $this->actingAs($this->external)->from($this->url)->post(route('client.dossiers.'.($form === 'client-document' ? 'upload' : 'comments'), $this->dossier), [
        '_form' => $form, $field => $form === 'client-document' ? 'Correction conservée' : '',
    ])->assertRedirect($this->url)->assertSessionHasErrors();
    $response = $this->get($this->url)->assertOk()->assertSee('id="'.$dialog.'" class="gc-dossier-dialog" data-dossier-dialog  data-dialog-reopen', false);
    expect(substr_count($response->getContent(), 'data-dialog-reopen'))->toBe(1);
    if ($form === 'client-document') {
        $response->assertSee('value="Correction conservée"', false);
    }
})->with([['client-document', 'name', 'client-document-upload'], ['client-comment', 'body', 'client-comment-create']]);

it('highlights the nearest scheduled appointment not a past problem or another dossier', function () {
    $staff = User::factory()->create(['role' => 2]);
    $service = Service::create(['name' => 'Inspection', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $appointments = collect();
    foreach ([[-3, 'scheduled'], [1, 'problem'], [2, 'scheduled'], [5, 'scheduled']] as [$days, $status]) {
        $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $staff->id,
            'created_by' => $staff->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Client',
            'customer_phone' => '0600000000', 'address' => 'Rue test', 'starts_at' => now()->addDays($days),
            'ends_at' => now()->addDays($days)->addHour(), 'duration_minutes' => 60, 'status' => $status]);
        $appointment->forceFill(['dossier_operation_id' => $this->operation->id])->save();
        $appointments->push($appointment);
    }
    $this->actingAs($this->external)->get($this->url)->assertOk()
        ->assertViewHas('nextAppointment', fn ($appointment) => $appointment->id === $appointments[2]->id)
        ->assertViewHas('appointments', fn ($list) => $list->fragment() === 'client-appointments')
        ->assertViewHas('documents', fn ($list) => $list->fragment() === 'client-documents');
});
