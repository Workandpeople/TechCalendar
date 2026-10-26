<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointmentDocument;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\PortalCategoryAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->organisations = $this->accounts = [];
    foreach (['client', 'delegataire'] as $kind) {
        $org = ExternalDelegataire::create(['source' => 'native', 'kind' => $kind,
            'external_id' => $kind, 'name' => 'Organisation '.$kind, 'is_active' => true]);
        $this->organisations[$kind] = $org;
        $this->accounts[$kind] = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
            'external_client_id' => $org->id, 'portal_enabled' => true, 'must_change_password' => false,
            'portal_permissions' => ClientAccess::defaults('client')]);
    }
    $this->makeDossier = function (string $reference, array $statuses): Dossier {
        $dossier = Dossier::create(['reference' => $reference, 'company_name' => $reference, 'address' => 'Rue test', 'city' => 'Paris',
            'client_id' => $this->organisations['client']->id, 'delegataire_id' => $this->organisations['delegataire']->id]);
        foreach ($statuses as $status) {
            $dossier->operations()->create()->forceFill(['legacy_status' => $status])->save();
        }

        return $dossier;
    };
    $this->allowed = ($this->makeDossier)('VISIBLE', ['NON CONFORMITES', 'NON CONFORMITES']);
    $this->blocked = ($this->makeDossier)('BLOCKED', ['CONTROLE RT']);
    $this->mixed = ($this->makeDossier)('MIXED', ['NON CONFORMITES', 'CONTROLE RT']);
    $this->unknown = ($this->makeDossier)('NO-OPERATIONS', []);
    $this->newStatus = ($this->makeDossier)('UNMAPPED', ['NOUVEL ETAT']);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('preserves access by default and counts distinct assigned dossiers per category', function () {
    ($this->makeDossier)('INVOICED', ['DOSSIERS FACTURER']);
    Dossier::create(['reference' => 'FOREIGN-SECRET', 'address' => 'Rue test', 'city' => 'Paris']);
    $this->actingAs($this->accounts['client'])->get(route('client.dossiers'))->assertOk()
        ->assertSee('data-category="rt_control"', false)->assertDontSee('data-category="invoiced"', false)
        ->assertDontSee('FOREIGN-SECRET')->assertViewHas('categories', function ($categories) {
            return $categories['all']['count'] === 4 && $categories['non_compliant']['count'] === 2
                && $categories['rt_control']['count'] === 2 && ! isset($categories['unknown'])
                && $categories['report']['count'] === 1;
        });
});

it('keeps assigned reports accessible after billing without widening ownership categories or document audiences', function (string $kind, string $status) {
    $dossier = ($this->makeDossier)('REPORT-AFTER-BILLING', [$status]);
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, $kind, ['report']);
    $this->actingAs($this->accounts[$kind])->get(route('client.dossiers.show', $dossier))->assertOk();
    $operation = $dossier->operations()->sole();
    DB::table('dossier_billings')->insert(['dossier_id' => $dossier->id, 'dossier_operation_id' => $operation->id,
        'source' => 'coffrac', 'marked_at' => now(), 'invoice_reference' => 'INTERNAL-INVOICE-REFERENCE']);
    $private = $dossier->documents()->create(['name' => 'INTERNAL-REPORT', 'path' => 'crm/dossiers/private.pdf',
        'mime_type' => 'application/pdf', 'size' => 9]);
    $clientOnly = $dossier->documents()->create(['name' => 'CLIENT-REPORT', 'path' => 'crm/dossiers/client.pdf',
        'mime_type' => 'application/pdf', 'size' => 9, 'client_visible' => true]);
    $shared = $dossier->documents()->create(['name' => 'SHARED-REPORT', 'path' => 'crm/dossiers/shared.pdf',
        'mime_type' => 'application/pdf', 'size' => 9, 'client_visible' => true, 'delegataire_visible' => true]);
    foreach ([$private, $clientOnly, $shared] as $doc) {
        Storage::disk('local')->put($doc->path, '%PDF test');
    }
    $response = $this->get(route('client.dossiers.show', $dossier))->assertOk()
        ->assertSee('SHARED-REPORT')->assertDontSee('INTERNAL-REPORT')->assertDontSee('INTERNAL-INVOICE-REFERENCE');
    $this->get(route('client.documents.download', $private))->assertNotFound();
    $this->get(route('client.documents.download', $shared))->assertDownload();
    if ($kind === 'client') {
        $response->assertSee('CLIENT-REPORT');
        $this->get(route('client.documents.download', $clientOnly))->assertDownload();
    } else {
        $response->assertDontSee('CLIENT-REPORT');
        $this->get(route('client.documents.download', $clientOnly))->assertNotFound();
    }
    $this->get(route('client.dossiers', ['status' => 'report']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
    $this->get(route('client.dossiers.show', $this->blocked))->assertForbidden();
    $foreign = ($this->makeDossier)('FOREIGN-REPORT', [$status]);
    $foreign->forceFill(['client_id' => null, 'delegataire_id' => null])->save();
    $this->get(route('client.dossiers.show', $foreign))->assertForbidden();
    $this->organisations[$kind]->update(['portal_categories' => []]);
    $this->actingAs($this->accounts[$kind]->fresh())->get(route('client.dossiers.show', $dossier))->assertForbidden();
})->with(['client', 'delegataire'])->with(['RAPPORT GENIUS', 'DOSSIERS FACTURER']);

it('enforces defaults on lists counters direct links searches and filters for each kind', function (string $kind) {
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, $kind, ['non_compliant']);
    $this->actingAs($this->accounts[$kind])->get(route('client.dossiers'))->assertOk()->assertSee('VISIBLE')
        ->assertDontSee('BLOCKED')->assertDontSee('MIXED')->assertDontSee('UNMAPPED')->assertDontSee('NO-OPERATIONS')
        ->assertDontSee('data-category="rt_control"', false)->assertViewHas('categories', fn ($c) => $c['all']['count'] === 1 && $c['non_compliant']['count'] === 1);
    $this->get(route('client.dossiers', ['q' => 'BLOCKED']))->assertOk()->assertViewHas('dossiers', fn ($d) => $d->total() === 0);
    $this->get(route('client.dossiers', ['status' => 'rt_control']))->assertForbidden();
    $this->get(route('client.dossiers', ['status' => 'invoiced']))->assertForbidden();
    $this->get(route('client.dossiers.show', $this->allowed))->assertOk();
    foreach ([$this->blocked, $this->mixed, $this->unknown, $this->newStatus] as $dossier) {
        $this->get(route('client.dossiers.show', $dossier))->assertForbidden();
    }
    $this->actingAs($this->accounts[$kind === 'client' ? 'delegataire' : 'client'])
        ->get(route('client.dossiers.show', $this->blocked))->assertOk();
})->with(['client', 'delegataire']);

it('allows unknown dossiers only with the explicit unknown category', function () {
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, 'delegataire', ['unknown']);
    $this->actingAs($this->accounts['delegataire'])->get(route('client.dossiers', ['status' => 'unknown']))->assertOk()
        ->assertViewHas('dossiers', fn ($d) => $d->total() === 2)->assertSee('NO-OPERATIONS')->assertSee('UNMAPPED')->assertDontSee('VISIBLE');
});

it('keeps overrides independent and supports empty and inherited selections through management', function (string $kind) {
    $org = $this->organisations[$kind];
    $data = ['kind' => $kind, 'name' => $org->name, 'is_active' => true];
    $this->actingAs($this->staff)->put(route('manager.clients.categories.update', $kind), ['categories' => ['non_compliant']])->assertRedirect();
    $this->put(route('manager.clients.update', $org), [...$data, 'category_mode' => 'custom', 'categories' => ['rt_control']])->assertRedirect();
    expect($org->fresh()->portal_categories)->toBe(['rt_control']);
    $this->actingAs($this->accounts[$kind]->fresh())->get(route('client.dossiers.show', $this->blocked))->assertOk();
    $this->get(route('client.dossiers.show', $this->allowed))->assertForbidden();
    $this->actingAs($this->staff)->put(route('manager.clients.update', $org), [...$data, 'category_mode' => 'custom'])->assertRedirect();
    expect($org->fresh()->portal_categories)->toBe([]);
    $this->actingAs($this->accounts[$kind]->fresh())->get(route('client.dossiers'))->assertOk()->assertViewHas('dossiers', fn ($d) => $d->total() === 0);
    $this->actingAs($this->staff)->put(route('manager.clients.update', $org), [...$data, 'category_mode' => 'inherit', 'categories' => ['rt_control']])->assertRedirect();
    expect($org->fresh()->portal_categories)->toBeNull();
    $this->actingAs($this->accounts[$kind]->fresh())->get(route('client.dossiers.show', $this->allowed))->assertOk();
    $this->get(route('client.dossiers.show', $this->blocked))->assertForbidden();
})->with(['client', 'delegataire']);

it('revokes existing sessions and stale dossier objects after a default or workflow change', function () {
    $access = app(ClientAccess::class);
    $user = $this->accounts['delegataire'];
    $stale = $this->allowed->load('operations');
    expect($access->viewDossier($user, $stale))->toBeTrue();
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, 'delegataire', ['non_compliant']);
    $this->allowed->operations()->update(['legacy_status' => 'CONTROLE RT']);
    expect($access->viewDossier($user, $stale))->toBeFalse();
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, 'delegataire', []);
    $this->actingAs($user)->get(route('client.dossiers'))->assertOk()->assertViewHas('dossiers', fn ($d) => $d->total() === 0);
});

it('preserves custom settings on unrelated edits and inherits the new type when changed', function () {
    $org = $this->organisations['client'];
    $org->update(['portal_categories' => ['report']]);
    $this->actingAs($this->staff)->put(route('manager.clients.update', $org), ['kind' => 'client', 'name' => 'Renamed', 'is_active' => true])->assertRedirect();
    expect($org->fresh()->portal_categories)->toBe(['report']);
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, 'delegataire', ['pending']);
    $this->put(route('manager.clients.update', $org), ['kind' => 'delegataire', 'name' => 'Renamed', 'is_active' => true, 'category_mode' => 'inherit'])->assertRedirect();
    expect(app(PortalCategoryAccess::class)->effective($org->fresh()))->toBe(['pending']);
    $this->get(route('manager.clients.edit', $org))->assertOk()->assertSee('Catégories de dossiers accessibles');
});

it('fails closed for malformed stored defaults and overrides', function (string $kind) {
    DB::table('portal_category_defaults')->insert(['kind' => $kind, 'categories' => json_encode('invalid')]);
    expect(app(PortalCategoryAccess::class)->defaults($kind))->toBe([]);
    $org = $this->organisations[$kind];
    $org->forceFill(['portal_categories' => ['invoiced', 'all', 'invalid']])->save();
    expect(app(PortalCategoryAccess::class)->effective($org))->toBe([]);
})->with(['client', 'delegataire']);

it('blocks documents previews archives uploads comments and recontrol on forbidden dossiers', function (string $kind) {
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, $kind, ['rt_control']);
    $doc = $this->allowed->documents()->create(['name' => 'SECRET', 'path' => 'crm/dossiers/secret.pdf',
        'mime_type' => 'application/pdf', 'size' => 9, 'client_visible' => true, 'delegataire_visible' => true]);
    Storage::disk('local')->put($doc->path, '%PDF test');
    $this->actingAs($this->accounts[$kind]);
    foreach ([0, 1] as $preview) {
        $this->get(route('client.documents.download', ['document' => $doc, 'preview' => $preview]))->assertForbidden();
        $this->get(route('client.dossiers.archive', [$this->allowed, 1, 'preview' => $preview]))->assertForbidden();
        $this->get(route('client.dossiers.lot-documents.download', [$this->allowed, 1, 'preview' => $preview]))->assertForbidden();
    }
    $this->post(route('client.dossiers.comments', $this->allowed), ['body' => 'Denied'])->assertForbidden();
    $this->post(route('client.dossiers.upload', $this->allowed), ['name' => 'Denied', 'document' => UploadedFile::fake()->image('test.jpg')])->assertForbidden();
    $this->post(route('client.dossiers.recontrol', [$this->allowed, $this->allowed->operations()->first()]), ['version' => 1, 'body' => 'Denied'])->assertForbidden();
    expect($this->allowed->documents()->count())->toBe(1)->and($this->allowed->comments()->count())->toBe(0);
})->with(['client', 'delegataire']);

it('returns to the permitted list after recontrol moves a dossier into a forbidden category', function () {
    $dossier = ($this->makeDossier)('CORRECTION', ['NON CONFORMITES']);
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, 'delegataire', ['non_compliant']);
    $this->actingAs($this->accounts['delegataire'])->post(route('client.dossiers.recontrol', [$dossier, $dossier->operations()->first()]),
        ['version' => 1, 'body' => 'Correction effectuée'])->assertRedirect(route('client.dossiers'));
    $this->get(route('client.dossiers.show', $dossier))->assertForbidden();
});

it('does not leak hidden dossiers through lot rows counts or satisfaction including deleted appointments', function () {
    $org = $this->organisations['delegataire'];
    $lot = Lot::create(['name' => 'Lot test', 'delegataire_id' => $org->id]);
    $service = Service::create(['name' => 'Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    foreach ([$this->blocked, $this->allowed] as $dossier) {
        $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $this->staff->id,
            'created_by' => $this->staff->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Client',
            'customer_phone' => '0600000000', 'address' => 'Rue test', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'duration_minutes' => 60]);
        $appointment->forceFill(['dossier_operation_id' => $dossier->operations()->first()->id])->save();
        $row = $lot->appointments()->create(['customer_name' => $dossier->reference, 'appointment_id' => $appointment->id]);
        if ($dossier->id === $this->blocked->id) {
            $appointment->delete();
            $doc = LotAppointmentDocument::create(['lot_appointment_id' => $row->id, 'name' => 'Secret',
                'original_name' => 'report.pdf', 'path' => 'secret.pdf', 'disk' => 'local', 'size' => 9,
                'is_private' => false, 'client_visible' => true]);
        }
    }
    $lot->appointments()->create(['customer_name' => 'UNLINKED']);
    app(PortalCategoryAccess::class)->saveDefaults($this->staff, 'delegataire', ['non_compliant']);
    $this->actingAs($this->accounts['delegataire'])->get(route('client.lots'))->assertOk()
        ->assertViewHas('lots', fn ($lots) => $lots->first()->appointments_count === 1);
    $this->get(route('client.lots.show', $lot))->assertOk()->assertSee('VISIBLE')->assertDontSee('BLOCKED')->assertDontSee('UNLINKED')
        ->assertViewHas('stats', fn ($stats) => $stats === ['physical' => null, 'contact' => null]);
    $this->get(route('client.dossiers.lot-documents.download', [$this->blocked, $doc]))->assertForbidden();
});

it('authorizes category administration and validates unknown duplicated and invalid values', function (string $kind) {
    $url = route('manager.clients.categories.update', $kind);
    $this->actingAs($this->accounts['delegataire'])->get(route('manager.clients.categories'))->assertForbidden();
    $this->put($url, ['categories' => []])->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 1, 'admin' => false]))->put($url, ['categories' => []])->assertForbidden();
    $this->actingAs($this->staff)->get(route('manager.clients.categories'))->assertOk();
    foreach ([['all'], ['invoiced'], ['pending', 'pending'], 'pending', [null]] as $invalid) {
        $this->putJson($url, ['categories' => $invalid])->assertUnprocessable();
    }
    expect(DB::table('portal_category_defaults')->count())->toBe(0);
    $this->put($url, [])->assertRedirect();
    expect(app(PortalCategoryAccess::class)->defaults($kind))->toBe([]);
})->with(['client', 'delegataire']);

it('allows removing and restoring report access by type without overriding explicit organisation choices', function (string $kind) {
    $dossier = ($this->makeDossier)('ADMINISTRABLE-REPORT', ['RAPPORT GENIUS']);
    $categories = app(PortalCategoryAccess::class);
    $org = $this->organisations[$kind];
    $categories->saveDefaults($this->staff, $kind, ['non_compliant']);
    expect($categories->defaults($kind))->toBe(['non_compliant']);
    $this->actingAs($this->accounts[$kind])->get(route('client.dossiers.show', $dossier))->assertForbidden();
    $this->get(route('client.dossiers', ['status' => 'report']))->assertForbidden();
    $this->get(route('client.dossiers'))->assertOk()->assertDontSee('ADMINISTRABLE-REPORT')
        ->assertDontSee('data-category="report"', false);

    $categories->saveDefaults($this->staff, $kind, ['non_compliant', 'report']);
    $this->get(route('client.dossiers.show', $dossier))->assertOk();
    $org->update(['portal_categories' => ['non_compliant']]);
    $this->actingAs($this->accounts[$kind]->fresh())->get(route('client.dossiers.show', $dossier))->assertForbidden();
    $org->update(['portal_categories' => ['report']]);
    $categories->saveDefaults($this->staff, $kind, []);
    $this->actingAs($this->accounts[$kind]->fresh())->get(route('client.dossiers.show', $dossier))->assertOk();
    expect($org->fresh()->portal_categories)->toBe(['report']);
})->with(['client', 'delegataire']);

it('renders editable type and organisation categories for both external profiles', function (string $kind) {
    $this->actingAs($this->staff);
    $pages = [route('manager.clients.categories'), route('manager.clients.edit', $this->organisations[$kind])];
    foreach ($pages as $page) {
        $response = $this->get($page)->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $id = $page === $pages[0] ? $kind.'-report' : 'organisation-report';
        expect($xpath->query('//input[@id="'.$id.'" and @type="checkbox" and not(@disabled)]')->length)->toBe(1)
            ->and($xpath->query('//input[@type="hidden" and @name="categories[]"]')->length)->toBe(0);
        if ($page === $pages[0]) {
            expect($xpath->query('//form[.//input[@name="_category_kind" and @value="'.$kind.'"]]//button[@type="submit"]')->length)->toBe(1);
        } else {
            expect($xpath->query('//input[@name="category_mode" and @value="custom"]')->length)->toBe(1)
                ->and($xpath->query('//input[@name="category_mode" and @value="inherit"]')->length)->toBe(1);
        }
    }
})->with(['client', 'delegataire']);
