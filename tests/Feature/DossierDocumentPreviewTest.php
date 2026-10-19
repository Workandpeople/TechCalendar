<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Lot;
use App\Models\LotAppointmentDocument;
use App\Models\Service;
use App\Models\User;
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
    $this->dossier = Dossier::create(['reference' => 'PREVIEW', 'address' => 'Rue de test', 'city' => 'Paris']);
    $service = Service::create(['name' => 'Preview', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $this->staff->id,
        'created_by' => $this->staff->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Preview',
        'customer_phone' => '0600000000', 'address' => 'Rue de test', 'starts_at' => now(),
        'ends_at' => now()->addHour(), 'duration_minutes' => 60, 'status' => 'scheduled']);
    $appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
    $this->lotRow = Lot::create(['name' => 'Preview lot'])->appointments()->create([
        'appointment_id' => $appointment->id, 'customer_name' => 'Preview',
    ]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function dossierPreviewFixture($test, string $source, string $content, string $mime = 'application/pdf'): array
{
    $disk = Storage::disk('local');
    // Deliberately use a PDF extension even for other content: sniffing must win.
    $path = match ($source) {
        'native' => 'crm/documents/preview.pdf',
        'lot' => 'lot-appointment-documents/'.$test->lotRow->id.'/preview.pdf',
        'archive' => 'collected/preview.pdf',
    };
    $disk->put($path, $content);
    $attributes = ['name' => 'Preview.pdf', 'path' => $path, 'size' => strlen($content)];
    if ($source === 'native') {
        $document = $test->dossier->documents()->create([...$attributes, 'mime_type' => $mime]);
        $url = route('v2.documents.download', $document);
    } elseif ($source === 'lot') {
        $document = LotAppointmentDocument::create([...$attributes, 'mime' => $mime, 'disk' => 'local',
            'original_name' => 'preview.pdf', 'lot_appointment_id' => $test->lotRow->id]);
        $url = route('v2.dossiers.lot-documents.download', [$test->dossier, $document]);
    } else {
        $disk->put('manifest.list0', "preview.pdf\0");
        config(['crm.collected_documents_root' => $disk->path('collected'), 'crm.collected_documents_manifest' => $disk->path('manifest.list0')]);
        DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 123,
            'dossier_id' => $test->dossier->id, 'payload' => json_encode(['name' => 'Preview.pdf', 'path' => 'preview.pdf', 'is_private' => true])]);
        $url = route('v2.dossiers.collected-documents.download', [$test->dossier, 123]);
    }

    return [$url, $path];
}

it('serves sniffed PDFs and raster images inline with viewer-compatible private headers', function (string $source, string $format) {
    $content = file_get_contents(__DIR__.'/../Fixtures/documents/preview.pdf');
    if ($format !== 'pdf') {
        $image = UploadedFile::fake()->image('preview.'.$format);
        $content = $image->get();
    }
    $mime = $format === 'pdf' ? 'application/pdf' : ($format === 'jpg' ? 'image/jpeg' : 'image/'.$format);
    [$url] = dossierPreviewFixture($this, $source, $content, $mime);
    $this->actingAs($this->staff);
    foreach (['GET', 'HEAD'] as $method) {
        $response = $this->call($method, $url.'?preview=1')->assertOk()
            ->assertHeader('Content-Type', $mime)->assertHeader('Content-Length', (string) strlen($content))
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->not->toContain('.pdf.pdf')
            ->and($response->headers->get('X-TC-Document-Preview'))->toBe($format === 'pdf' ? 'pdf' : null)
            ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'", "frame-ancestors 'self'", "object-src 'self'", "img-src 'self'")
            ->not->toContain('sandbox', 'unsafe-inline', 'unsafe-eval');
        if ($method === 'GET') {
            if ($source === 'archive') {
                expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($content);
            } else {
                $response->assertStreamedContent($content);
            }
        }
    }
    $this->get($url)->assertOk()->assertDownload('Preview.pdf')->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertHeaderMissing('X-TC-Document-Preview');
})->with(['native', 'lot', 'archive'])->with(['pdf', 'png', 'jpg', 'gif', 'webp']);

it('never renders active content inline despite a forged extension and stored MIME', function (string $source, string $content) {
    [$url] = dossierPreviewFixture($this, $source, $content);
    $this->actingAs($this->staff)->withHeaders(['X-TC-Document-Preview' => 'pdf', 'Content-Type' => 'application/pdf'])
        ->get($url.'?preview=1')->assertOk()->assertDownload('Preview.pdf')
        ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")->assertHeaderMissing('X-TC-Document-Preview');
})->with(['native', 'lot', 'archive'])->with([
    'html' => '<!DOCTYPE html><html><script>alert(document.cookie)</script></html>',
    'svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="100" height="100"/></svg>',
    'text' => 'Not a PDF or an image',
]);

it('keeps authentication authorization and missing-file checks on GET and HEAD previews', function (string $source) {
    [$url, $path] = dossierPreviewFixture($this, $source, "%PDF-1.4\n%%EOF\n");
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);
    foreach (['GET', 'HEAD'] as $method) {
        $this->app['auth']->forgetGuards();
        $this->call($method, $url.'?preview=1')->assertRedirect(route('login'))->assertHeaderMissing('X-TC-Document-Preview');
        $this->actingAs($technician)->call($method, $url.'?preview=1')->assertForbidden()->assertHeaderMissing('X-TC-Document-Preview');
    }
    Storage::disk('local')->delete($path);
    $this->actingAs($this->staff)->get($url.'?preview=1')->assertNotFound();
    $this->head($url.'?preview=1')->assertNotFound();
})->with(['native', 'lot', 'archive']);

it('renders compact labelled actions including lot MIME previews and safe fallback links', function () {
    [$url] = dossierPreviewFixture($this, 'lot', "%PDF-1.4\n%%EOF\n");
    dossierPreviewFixture($this, 'native', "%PDF-1.4\n%%EOF\n");
    $this->actingAs($this->staff)->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee($url.'?preview=1', false)->assertSee('gc-document-action', false)
        ->assertSee('title="Aperçu de Preview.pdf"', false)->assertSee('aria-label="Voir Preview.pdf"', false)
        ->assertSee('title="Télécharger Preview.pdf"', false)->assertSee('aria-label="Télécharger Preview.pdf"', false)
        ->assertSee('title="Supprimer Preview.pdf"', false)->assertSee('aria-label="Supprimer Preview.pdf"', false)
        ->assertSee('Confirmer la suppression')->assertSee('data-preview-status', false)
        ->assertSee('data-preview-content', false)->assertSee('data-preview-external', false);
});
