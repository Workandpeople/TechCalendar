<?php

use App\Http\Requests\Crm\ClientCorrectionRequest;
use App\Http\Requests\Crm\DossierDocumentRequest;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\DocumentUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('accepts up to exactly one GiB and refuses one extra byte in document form requests', function (string $type, int $bytes, bool $accepted) {
    $request = $type === 'correction' ? new ClientCorrectionRequest : DossierDocumentRequest::create('/', 'POST', ['document_type' => $type]);
    // Report a large size without allocating a GiB in the test runner.
    $file = UploadedFile::fake()->create('preuve.pdf', 1, 'application/pdf');
    $file->sizeToReport = $bytes;
    $validator = Validator::make(['document' => $file], ['document' => $request->rules()['document']], $request->messages());
    expect($validator->passes())->toBe($accepted);
    if (! $accepted) {
        expect($validator->errors()->first('document'))->toBe(DocumentUpload::TOO_LARGE);
    }
})->with(['free', 'visit', 'report', 'correction'])->with([
    'above old limit' => [10_241 * 1024, true],
    'exact limit' => [1_073_741_824, true],
    'one byte over' => [1_073_741_825, false],
]);

it('stores a large document for staff and clients but refuses delegataires without external calls', function (string $profile) {
    $dossier = Dossier::create(['reference' => 'LARGE-'.$profile, 'address' => 'Rue du test', 'city' => 'Paris']);
    $operation = $dossier->operations()->create(['legacy_service_name' => 'Test']);
    $operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    if ($profile === 'staff') {
        $actor = User::factory()->create(['role' => 0, 'admin' => false]);
        $url = route('v2.dossiers.documents.store', $dossier);
    } else {
        $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'LARGE-'.$profile,
            'name' => 'Organisation test', 'kind' => $profile, 'is_active' => true]);
        $actor = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
            'external_client_id' => $organisation->id, 'portal_enabled' => true,
            'portal_permissions' => ClientAccess::defaults($profile), 'must_change_password' => false]);
        $dossier->update([($profile === 'client' ? 'client_id' : 'delegataire_id') => $organisation->id]);
        $url = route('client.dossiers.upload', $dossier);
    }
    $file = UploadedFile::fake()->createWithContent('preuve.pdf', file_get_contents(__DIR__.'/../Fixtures/documents/preview.pdf'))
        ->size(DocumentUpload::MAX_KILOBYTES);
    $response = $this->actingAs($actor)->post($url, ['name' => 'Preuve', 'document' => $file, 'document_type' => 'free', 'audience' => 'client']);
    if ($profile === 'delegataire') {
        $response->assertForbidden();
        expect($dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

        return;
    }
    $response->assertRedirect()->assertSessionHasNoErrors();
    $document = $dossier->documents()->sole();
    expect((int) $document->size)->toBe(DocumentUpload::MAX_BYTES)
        ->and($document->author_id)->toBe($actor->id)
        ->and($operation->fresh()->legacy_status)->toBe('NON CONFORMITES');
    Storage::disk('local')->assertExists($document->path);
    $tooLarge = UploadedFile::fake()->create('large.pdf', DocumentUpload::MAX_KILOBYTES + 1, 'application/pdf');
    $this->postJson($url, ['name' => 'Refusé', 'document' => $tooLarge])->assertUnprocessable()
        ->assertJsonValidationErrors('document')->assertJsonPath('errors.document.0', DocumentUpload::TOO_LARGE);
    expect($dossier->documents()->count())->toBe(1);
})->with(['staff', 'client', 'delegataire']);

it('still refuses disallowed file types under the new size limit', function () {
    $request = DossierDocumentRequest::create('/', 'POST', ['document_type' => 'free']);
    $validator = Validator::make(['document' => UploadedFile::fake()->create('bad.php', 12, 'text/plain')],
        ['document' => $request->rules()['document']]);
    expect($validator->fails())->toBeTrue();
});

it('uses the same one GiB limit for native lot appointment attachments', function () {
    $actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $row = Lot::create(['name' => 'Lot test'])->appointments()->create(['customer_name' => 'Test']);
    $url = route('manager.lots.appointments.documents.store', $row);
    $file = UploadedFile::fake()->createWithContent('preuve.pdf', file_get_contents(__DIR__.'/../Fixtures/documents/preview.pdf'))
        ->size(DocumentUpload::MAX_KILOBYTES);
    $this->actingAs($actor)->postJson($url, ['name' => 'Preuve', 'document' => $file])->assertCreated();
    expect((int) $row->documents()->sole()->size)->toBe(DocumentUpload::MAX_BYTES);
    $this->postJson($url, ['document' => UploadedFile::fake()->create('big.pdf', DocumentUpload::MAX_KILOBYTES + 1, 'application/pdf')])
        ->assertUnprocessable()->assertJsonPath('errors.document.0', DocumentUpload::TOO_LARGE);
    expect($row->documents()->count())->toBe(1);
});

it('explains oversized request bodies instead of showing a generic server error', function () {
    $this->withServerVariables(['CONTENT_LENGTH' => (string) PHP_INT_MAX])->post('/login')
        ->assertStatus(413)->assertSee('1 Go maximum');
});
