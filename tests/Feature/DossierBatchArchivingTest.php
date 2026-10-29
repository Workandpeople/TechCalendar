<?php

use App\Models\Appointment;
use App\Models\CapceeDelivery;
use App\Models\CapceeReport;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierBilling;
use App\Models\DossierSms;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierArchiving;
use App\Services\Crm\DossierRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->service = Service::create(['name' => 'Inspection', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->makeProblem = function (string $reference, string $status = 'PROBLÈME RENDEZ-VOUS'): Dossier {
        $dossier = Dossier::create(['reference' => $reference, 'company_name' => $reference, 'address' => '1 rue Test', 'city' => 'Paris']);
        $dossier->forceFill(['created_at' => now()->subYears(5)])->save();
        $dossier->operations()->create(['service_id' => $this->service->id])->forceFill(['legacy_status' => $status])->save();

        return $dossier;
    };
    $this->first = ($this->makeProblem)('OLD-SELECTED-1', 'Problème rendez- vous');
    $this->second = ($this->makeProblem)('OLD-SELECTED-2');
    $this->unselected = ($this->makeProblem)('OLD-NOT-SELECTED');
    $this->selection = [['id' => $this->first->id, 'version' => 1], ['id' => $this->second->id, 'version' => 1]];
    $this->payload = ['action' => 'delete', 'confirmed' => true, 'dossiers' => $this->selection];
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('processes only the explicit selection without cleaning up other old problem dossiers', function (string $action) {
    $response = $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => $action])
        ->assertOk()->assertJsonPath('count', 2);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    foreach ([$this->first, $this->second] as $dossier) {
        expect($dossier->refresh()->trashed())->toBe($action === 'delete')->and($dossier->version)->toBe(2)
            ->and($dossier->activities()->sole()->author_id)->toBe($this->actor->id)
            ->and($dossier->activities()->sole()->client_visible)->toBeFalse()
            ->and($dossier->activities()->sole()->delegataire_visible)->toBeFalse();
        if ($action === 'cancel') {
            expect($dossier->operations()->sole()->legacy_status)->toBe('DOSSIER ANNULE');
        }
    }
    expect($this->unselected->refresh()->trashed())->toBeFalse()->and($this->unselected->version)->toBe(1)
        ->and($this->unselected->operations()->sole()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($this->unselected->activities()->count())->toBe(0)->and(Dossier::withTrashed()->count())->toBe(3);
    $this->getJson(route('v2.dossiers.index', ['status' => 'problem']))->assertOk()->assertJsonPath('total', 1);
    $this->getJson(route('v2.dossiers.index', ['status' => $action === 'delete' ? 'unknown' : 'cancelled']))->assertOk()->assertJsonPath('total', 2);
})->with(['cancel', 'delete']);

it('preserves billing proof documents appointments history and unrelated operation states', function (string $action) {
    $problem = $this->first->operations()->sole();
    $anotherProblem = $this->first->operations()->create(['service_id' => $this->service->id]);
    $anotherProblem->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $unrelated = $this->first->operations()->create(['service_id' => $this->service->id]);
    $unrelatedStatus = $action === 'cancel' ? 'DOSSIERS FACTURER' : 'DOSSIER ANNULE';
    $unrelated->forceFill(['legacy_status' => $unrelatedStatus])->save();
    $billing = DossierBilling::forceCreate(['dossier_id' => $this->first->id, 'dossier_operation_id' => $problem->id,
        'marked_at' => now(), 'marked_by' => $this->actor->id, 'source' => 'native', 'invoice_reference' => 'FAC-123', 'evidence' => ['kept' => true]]);
    $document = $this->first->documents()->create(['name' => 'Facture', 'path' => 'preserved.pdf', 'mime_type' => 'application/pdf', 'size' => 5]);
    Storage::disk('local')->put('preserved.pdf', '%PDF-');
    $history = $this->first->activities()->create(['kind' => 'legacy_comment', 'body' => 'Ancien historique à garder']);
    $appointment = Appointment::forceCreate(['dossier_operation_id' => $problem->id, 'service_id' => $this->service->id,
        'technician_id' => $this->actor->id, 'created_by' => $this->actor->id, 'customer_first_name' => 'Client',
        'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => '1 rue Test', 'duration_minutes' => 60,
        'starts_at' => now()->subYears(4), 'ends_at' => now()->subYears(4)->addHour(), 'status' => Appointment::STATUS_PROBLEM]);
    $originalAppointment = $appointment->fresh()->getRawOriginal();
    $originalBilling = $billing->fresh()->getRawOriginal();
    $originalDocument = $document->fresh()->getRawOriginal();
    $rawPayload = '{"status":"PROBLEME RDV","billing":"FAC-123"}';
    DB::table('legacy_records')->insert(['source_table' => 'dossier', 'source_id' => 123, 'dossier_id' => $this->first->id, 'payload' => $rawPayload]);
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => $action])->assertOk();
    expect($appointment->fresh()->getRawOriginal())->toBe($originalAppointment)
        ->and($billing->fresh()->getRawOriginal())->toBe($originalBilling)
        ->and($document->fresh()->getRawOriginal())->toBe($originalDocument)
        ->and($history->fresh()->body)->toBe('Ancien historique à garder')
        ->and($unrelated->fresh()->legacy_status)->toBe($unrelatedStatus)
        ->and(DB::table('legacy_records')->where('dossier_id', $this->first->id)->value('payload'))->toBe($rawPayload)
        ->and($this->first->refresh()->version)->toBe($action === 'cancel' ? 3 : 2);
    Storage::disk('local')->assertExists('preserved.pdf');
    if ($action === 'delete') {
        $this->post(route('v2.dossiers.restore', $this->first), ['version' => 2])->assertRedirect();
        expect($this->first->refresh()->trashed())->toBeFalse()->and($problem->fresh()->legacy_status)->toBe('Problème rendez- vous')
            ->and($anotherProblem->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
            ->and($this->first->billings()->count())->toBe(1);
    } else {
        expect($problem->fresh()->legacy_status)->toBe('DOSSIER ANNULE')->and($anotherProblem->fresh()->legacy_status)->toBe('DOSSIER ANNULE');
    }
})->with(['cancel', 'delete']);

it('refuses mixed-state bulk deletion atomically and requires explicit individual deletion', function (?string $status) {
    $other = $this->second->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => $status])->save();
    $response = $this->postJson(route('v2.dossiers.batch'), $this->payload)->assertUnprocessable()->assertJsonValidationErrors('dossiers');
    expect($response->json('errors.dossiers.0'))->toContain($this->second->reference, 'suppression individuelle')
        ->and($this->first->refresh()->trashed())->toBeFalse()->and($this->first->version)->toBe(1)
        ->and($this->first->activities()->count())->toBe(0)->and($this->second->refresh()->trashed())->toBeFalse()
        ->and($this->second->activities()->count())->toBe(0)->and($other->fresh()->legacy_status)->toBe($status);
    $this->deleteJson(route('v2.dossiers.destroy', $this->second), ['version' => 1, 'confirmed' => true])->assertOk();
    expect($this->second->refresh()->trashed())->toBeTrue()->and($other->fresh()->legacy_status)->toBe($status);
})->with(['PRISE DE RDV', 'RDV ATTENTE VISITE', 'RDV VISITE', 'CONTROLE RT', 'NON CONFORMITES', 'RECONTROLE', 'RAPPORT GENIUS', 'DOSSIERS FACTURER', 'LEGACY-UNKNOWN', null]);

it('audits a supplied cancellation reason once per dossier and only for cancelled problem operations', function () {
    $extra = $this->first->operations()->create(['service_id' => $this->service->id]);
    $extra->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $finished = $this->first->operations()->create(['service_id' => $this->service->id]);
    $finished->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => 'cancel', 'reason' => '  Doublons validés par le client  '])->assertOk();
    foreach ([$this->first, $this->second] as $dossier) {
        $audit = $dossier->activities()->where('kind', 'dossier_cancelled')->sole();
        expect($audit->body)->toContain('Motif : Doublons validés par le client')
            ->and($audit->author_id)->toBe($this->actor->id)->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse()
            ->and($audit->changed_fields['reason'])->toBe('Doublons validés par le client')
            ->and($audit->changed_fields['action'])->toBe('batch_cancel')
            ->and($audit->changed_fields['operation_ids'])->not->toContain($finished->id);
    }
    expect($this->first->activities()->where('kind', 'status_changed')->count())->toBe(2)
        ->and($finished->fresh()->legacy_status)->toBe('RAPPORT GENIUS')->and($this->unselected->activities()->count())->toBe(0);
});

it('rolls back cancellation and status audits when the reason audit cannot be written', function () {
    DossierActivity::creating(function (DossierActivity $activity): void {
        if ($activity->kind === 'dossier_cancelled') {
            throw new RuntimeException('Audit unavailable');
        }
    });
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => 'cancel', 'reason' => 'Motif obligatoire dans cet audit'])->assertServerError();
    expect($this->first->refresh()->version)->toBe(1)->and($this->first->operations()->sole()->legacy_status)->toBe('Problème rendez- vous')
        ->and($this->first->activities()->count())->toBe(0)->and($this->second->activities()->count())->toBe(0);
});

it('rolls back earlier mutations if the last dossier has a stale version', function (string $action) {
    $this->second->forceFill(['version' => 2])->save();
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => $action])->assertConflict();
    expect($this->first->refresh()->trashed())->toBeFalse()->and($this->first->version)->toBe(1)
        ->and($this->first->operations()->sole()->legacy_status)->toBe('Problème rendez- vous')
        ->and($this->first->activities()->count())->toBe(0)->and($this->second->activities()->count())->toBe(0);
})->with(['cancel', 'delete']);

it('rolls back the whole batch if a selected dossier is no longer in problem', function (string $action) {
    $this->second->operations()->sole()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => $action])->assertUnprocessable()->assertJsonValidationErrors('dossiers');
    expect($this->first->refresh()->trashed())->toBeFalse()->and($this->first->version)->toBe(1)
        ->and($this->first->operations()->sole()->legacy_status)->toBe('Problème rendez- vous')
        ->and($this->first->activities()->count())->toBe(0);
})->with(['cancel', 'delete']);

it('rejects nonexistent or already deleted selected dossiers without partial changes', function (bool $deleted) {
    if ($deleted) {
        app(DossierArchiving::class)->delete($this->actor, $this->second, 1, null);
    } else {
        $this->payload['dossiers'][1]['id'] = 999999;
    }
    $this->postJson(route('v2.dossiers.batch'), $this->payload)->assertUnprocessable()->assertJsonValidationErrors('dossiers');
    expect($this->first->refresh()->trashed())->toBeFalse()->and($this->first->version)->toBe(1)
        ->and($this->first->activities()->count())->toBe(0);
})->with([false, true]);

it('blocks pending SMS on the last selected dossier and rolls back the batch', function (string $action, string $status) {
    DossierSms::create(['dossier_id' => $this->second->id, 'requested_by' => $this->actor->id,
        'submission_token' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'recipient' => '0600000000',
        'body' => 'En cours', 'mode' => 'simulation', 'status' => $status]);
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => $action])
        ->assertUnprocessable()->assertJsonValidationErrors('dossier');
    expect($this->first->refresh()->trashed())->toBeFalse()->and($this->first->version)->toBe(1)
        ->and($this->first->activities()->count())->toBe(0)->and($this->second->activities()->count())->toBe(0);
})->with(['cancel', 'delete'])->with(['queued', 'sending']);

it('blocks pending or uncertain CAPCEE deliveries without partially processing the batch', function (string $action, string $status) {
    $report = CapceeReport::create(['dossier_operation_id' => $this->second->operations()->sole()->id, 'environment' => 'uat2',
        'reference' => 'BATCH-REPORT', 'emmy_reference' => 'EMMY-123', 'object_type' => 'report', 'inspection_type' => 'initial']);
    CapceeDelivery::create(['capcee_report_id' => $report->id, 'requested_by' => $this->actor->id, 'submission_token' => (string) Str::uuid(),
        'revision' => 1, 'metadata' => [], 'path' => 'capcee.pdf', 'size' => 5, 'sha256' => str_repeat('a', 64), 'status' => $status]);
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'action' => $action])
        ->assertUnprocessable()->assertJsonValidationErrors('dossier');
    expect($this->first->refresh()->trashed())->toBeFalse()->and($this->first->version)->toBe(1)
        ->and($this->first->activities()->count())->toBe(0);
})->with(['cancel', 'delete'])->with(['queued', 'processing', 'review']);

it('requires explicit confirmation for single and batch requests', function ($confirmation) {
    $this->deleteJson(route('v2.dossiers.destroy', $this->first), ['version' => 1, 'confirmed' => $confirmation])
        ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'confirmed' => $confirmation])
        ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    expect($this->first->refresh()->trashed())->toBeFalse();
})->with([null, false, 'no']);

it('validates selection shape distinct IDs versions action and batch limit', function (string $case, string $field) {
    $data = $this->payload;
    match ($case) {
        'empty' => $data['dossiers'] = [],
        'filter-only' => $data = ['action' => 'delete', 'confirmed' => true, 'status' => 'problem'],
        'duplicate' => $data['dossiers'][1] = $data['dossiers'][0],
        'version' => $data['dossiers'][1]['version'] = 0,
        'missing-version' => $data['dossiers'][1] = ['id' => $this->second->id],
        'action' => $data['action'] = 'forceDelete',
        'extra-key' => $data['dossiers'][1]['all'] = true,
        'not-list' => $data['dossiers'] = ['selected' => $data['dossiers'][0]],
        'limit' => $data['dossiers'] = array_map(fn ($id) => ['id' => $id, 'version' => 1], range(1, 101)),
    };
    $this->postJson(route('v2.dossiers.batch'), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->first->refresh()->trashed())->toBeFalse()->and($this->first->activities()->count())->toBe(0);
})->with([
    ['empty', 'dossiers'], ['filter-only', 'dossiers'], ['duplicate', 'dossiers.1.id'], ['version', 'dossiers.1.version'],
    ['missing-version', 'dossiers.1.version'], ['action', 'action'], ['extra-key', 'dossiers.1'], ['not-list', 'dossiers'], ['limit', 'dossiers'],
]);

it('permits a full batch of one hundred explicit dossiers', function () {
    $selection = $this->selection;
    for ($index = 3; $index <= 100; $index++) {
        $dossier = ($this->makeProblem)('BATCH-'.$index);
        $selection[] = ['id' => $dossier->id, 'version' => 1];
    }
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'dossiers' => $selection])->assertOk()->assertJsonPath('count', 100);
    expect(Dossier::onlyTrashed()->count())->toBe(100)->and(Dossier::count())->toBe(1)
        ->and($this->unselected->refresh()->trashed())->toBeFalse();
});

it('authorizes both endpoints and the service only for managers and admins', function (int $role) {
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    $this->actingAs($user)->deleteJson(route('v2.dossiers.destroy', $this->first), ['version' => 1, 'confirmed' => true])->assertForbidden();
    $this->actingAs($user)->postJson(route('v2.dossiers.batch'), $this->payload)->assertForbidden();
    expect(fn () => app(DossierArchiving::class)->batch($user, $this->selection, 'delete'))->toThrow(AuthorizationException::class);
    if ($role === 1) {
        $this->getJson(route('v2.dossiers.index', ['status' => 'problem']))->assertOk()
            ->assertJsonPath('total', 3);
        $this->get(route('v2.dossiers.index', ['status' => 'problem']))->assertOk()
            ->assertDontSee('data-dossier-delete-url', false)->assertDontSee('data-dossier-batch-action', false)->assertDontSee('data-dossier-select', false);
    }
})->with([1, 2, 3]);

it('allows admins regardless of their internal role and returns JSON for single deletion', function () {
    $admin = User::factory()->create(['role' => 1, 'admin' => true]);
    $this->actingAs($admin)->deleteJson(route('v2.dossiers.destroy', $this->first), ['version' => 1, 'confirmed' => true])
        ->assertOk()->assertJsonPath('count', 1)->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson(route('v2.dossiers.batch'), [...$this->payload, 'dossiers' => [['id' => $this->second->id, 'version' => 1]]])->assertOk();
    expect($this->first->refresh()->deleted_by)->toBe($admin->id);
});

it('does not replay a completed deletion or cancellation on repeated requests', function (string $action) {
    $data = [...$this->payload, 'action' => $action];
    $this->postJson(route('v2.dossiers.batch'), $data)->assertOk();
    $response = $this->postJson(route('v2.dossiers.batch'), $data);
    $action === 'delete' ? $response->assertUnprocessable() : $response->assertConflict();
    expect($this->first->activities()->count())->toBe(1)->and($this->second->activities()->count())->toBe(1);
})->with(['cancel', 'delete']);

it('enforces the limit inside the service as well', function () {
    expect(fn () => app(DossierArchiving::class)->batch($this->actor, [], 'delete'))->toThrow(ValidationException::class);
    expect(fn () => app(DossierArchiving::class)->batch($this->actor, array_fill(0, 101, $this->selection[0]), 'delete'))->toThrow(ValidationException::class);
});

it('keeps the eye first selection second and deletion last with bulk controls only in the problem category', function (bool $allColumns) {
    $response = $this->getJson(route('v2.dossiers.index', ['status' => 'problem', 'per_page' => 10, 'all_columns' => (int) $allColumns]))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->json('results'), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//colgroup/col[1][@class="gc-dossier-col-action"]')->length)->toBe(1)
        ->and($xpath->query('//colgroup/col[2][@class="gc-dossier-col-selection"]')->length)->toBe(1)
        ->and($xpath->query('//colgroup/col[last()][@class="gc-dossier-col-delete"]')->length)->toBe(1)
        ->and($xpath->query('//thead/tr/th[1][@class="gc-dossier-action"]')->length)->toBe(1)
        ->and($xpath->query('//thead/tr/th[2]/input[@data-dossier-select-page]')->length)->toBe(1)
        ->and($xpath->query('//thead/tr/th[last()][@class="gc-dossier-delete"]')->length)->toBe(1)
        ->and($xpath->query('//tbody/tr/td[1]/a[@class="gc-dossier-open"]')->length)->toBe(3)
        ->and($xpath->query('//tbody/tr/td[2]/input[@data-dossier-select]')->length)->toBe(3)
        ->and($xpath->query('//tbody/tr/td[last()]//button[@data-dossier-delete-url]')->length)->toBe(3)
        ->and($xpath->query('//button[@data-dossier-batch-action and @disabled]')->length)->toBe(2);
    $other = $this->getJson(route('v2.dossiers.index', ['all_columns' => (int) $allColumns]))->assertOk();
    expect($other->json('results'))->toContain('data-dossier-delete-url')->not->toContain('data-dossier-select', 'data-dossier-batch-action');
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$other->json('results'), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//colgroup/col[1][@class="gc-dossier-col-action"]')->length)->toBe(1)
        ->and($xpath->query('//thead/tr/th[1][@class="gc-dossier-action"]')->length)->toBe(1)
        ->and($xpath->query('//tbody/tr/td[1]/a[@class="gc-dossier-open"]')->length)->toBe(3)
        ->and($xpath->query('//tbody/tr/td[last()]//button[@data-dossier-delete-url]')->length)->toBe(3);
})->with([false, true]);

it('keeps filtered sorted AJAX pagination valid after deleting the last page', function () {
    for ($index = 4; $index <= 11; $index++) {
        ($this->makeProblem)(sprintf('OLD-PAGE-%02d', $index));
    }
    $filters = ['status' => 'problem', 'q' => 'OLD-', 'per_page' => 10, 'sort' => 'client', 'direction' => 'asc', 'page' => 2];
    $this->getJson(route('v2.dossiers.index', $filters))->assertOk()->assertJsonPath('page', 2)->assertJsonPath('total', 11);
    $page = app(DossierRegistry::class)->search($this->actor, $filters);
    $last = $page->sole();
    $this->deleteJson(route('v2.dossiers.destroy', $last), ['version' => 1, 'confirmed' => true])->assertOk();
    $response = $this->getJson(route('v2.dossiers.index', $filters))->assertOk()->assertJsonPath('page', 1)->assertJsonPath('total', 10);
    expect(substr_count($response->json('results'), '<tr data-dossier-comments>'))->toBe(10)
        ->and($response->json('results'))->toContain('q=OLD-', 'status=problem', 'sort=client')
        ->not->toContain('Aucun dossier ne correspond');
});
