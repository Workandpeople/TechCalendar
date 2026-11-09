<?php

use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarDocuments;
use App\Services\Mymar\MymarDocumentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->other = User::factory()->create(['role' => 2, 'admin' => false]);
    $mapping = Mockery::mock(MymarActionSettings::class);
    $mapping->shouldReceive('load')->andReturn(['bindings' => [['source_technician_id' => 41, 'cofrac_user_id' => $this->tech->id]]]);
    $settings = Mockery::mock(MymarDocumentSettings::class);
    $this->documents = Mockery::mock(MymarDocuments::class, [$settings, $mapping])->makePartial()->shouldAllowMockingProtectedMethods();
    app()->instance(MymarDocuments::class, $this->documents);
    $this->catalogue = ['documents' => [['id' => 5, 'name' => 'Facture travaux <canary>', 'kind' => 'invoice', 'bytes' => 20]]];
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('lists only source-authorized works documents and never exposes a source filesystem path', function () {
    $this->documents->shouldReceive('readCatalogue')->once()->with(555, 41)->andReturn($this->catalogue);
    $this->documents->shouldNotReceive('openSource');
    $this->actingAs($this->tech)->get(route('mymar.documents.index', 555))->assertOk()
        ->assertSee('Facture travaux &lt;canary&gt;', false)->assertDontSee('/storage/uploads/')
        ->assertSee(route('mymar.documents.download', [555, 5]), false)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('rechecks the catalogue on each download and streams a PDF through an authenticated local URL', function () {
    $this->documents->shouldReceive('readCatalogue')->once()->with(555, 41)->andReturn($this->catalogue);
    $pdf = "%PDF-1.4\nsynthetic\n%%EOF\n";
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $pdf);
    rewind($stream);
    $this->documents->shouldReceive('openSource')->once()->with(555, 41, 5)->andReturn($stream);
    $response = $this->actingAs($this->tech)->get(route('mymar.documents.download', [555, 5]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($response->streamedContent())->toBe($pdf);
});

it('shows the real source insertion time in French and Paris time including daylight saving', function (string $utc, string $display) {
    $this->catalogue['documents'][0]['inserted_at'] = $utc;
    $this->documents->shouldReceive('readCatalogue')->once()->with(555, 41)->andReturn($this->catalogue);
    $this->documents->shouldNotReceive('openSource');
    $this->actingAs($this->tech)->get(route('mymar.documents.index', 555))->assertOk()
        ->assertSee('Ajouté dans MYMAR le '.$display)->assertSee('datetime="'.$utc.'"', false);
})->with([
    ['2026-11-06T09:46:36Z', '06/11/2026 à 10:46'],
    ['2026-07-03T09:46:36Z', '03/07/2026 à 11:46'],
    ['2026-07-03T22:30:00Z', '04/07/2026 à 00:30'],
]);

it('keeps undated documents downloadable without inventing a source timestamp', function () {
    $this->catalogue['documents'][0]['inserted_at'] = null;
    $this->documents->shouldReceive('readCatalogue')->once()->with(555, 41)->andReturn($this->catalogue);
    $this->actingAs($this->tech)->get(route('mymar.documents.index', 555))->assertOk()
        ->assertSee('Date de dépôt MYMAR non renseignée')->assertDontSee('<time', false)
        ->assertSee(route('mymar.documents.download', [555, 5]), false);
});

it('refuses foreign technicians unmapped users external accounts and inactive users before source reads', function (string $case) {
    $this->documents->shouldNotReceive('readCatalogue');
    $user = match ($case) {
        'unmapped' => $this->other,
        'external' => User::factory()->create(['role' => 3, 'admin' => false]),
        'inactive' => User::factory()->create(['role' => 2, 'admin' => false, 'deleted_at' => now()]),
        default => $this->tech,
    };
    $url = route('mymar.documents.index', ['rdv' => 555, ...($case === 'foreign' ? ['technician_id' => $this->other->id] : [])]);
    $this->actingAs($user)->get($url)->assertStatus(in_array($case, ['external', 'inactive']) ? 403 : 404);
})->with(['foreign', 'unmapped', 'external', 'inactive']);

it('allows office users to inspect documents for a valid mapped technician only', function () {
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->documents->shouldReceive('readCatalogue')->once()->with(555, 41)->andReturn($this->catalogue);
    $this->actingAs($planner)->get(route('mymar.documents.index', ['rdv' => 555, 'technician_id' => $this->tech->id]))->assertOk();
    $this->get(route('mymar.documents.index', ['rdv' => 555, 'technician_id' => $this->other->id]))->assertNotFound();
});

it('does not open a document absent from the current authorized catalogue', function () {
    $this->documents->shouldReceive('readCatalogue')->with(555, 41)->andReturn(['documents' => []]);
    $this->documents->shouldNotReceive('openSource');
    $this->actingAs($this->tech)->get(route('mymar.documents.download', [555, 5]))->assertNotFound();
});

it('shows an honest unavailable state without disclosing configuration or source exceptions', function () {
    $this->documents->shouldReceive('readCatalogue')->andThrow(new RuntimeException('PRIVATE-CONFIG-CANARY'));
    $this->actingAs($this->tech)->get(route('mymar.documents.index', 555))->assertStatus(503)
        ->assertSee('Documents momentanément indisponibles')->assertDontSee('PRIVATE-CONFIG-CANARY');
});
