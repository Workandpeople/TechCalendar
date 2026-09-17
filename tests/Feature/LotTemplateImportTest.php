<?php

use App\Models\Lot;
use App\Models\LotImportPreview;
use App\Models\User;
use App\Services\LotAddressNormalizer;
use App\Services\LotImportConfirmationService;
use App\Services\LotImportPreviewProcessor;
use App\Services\LotImportPreviewRowUpdateService;
use App\Services\LotSpreadsheetExtractor;
use App\Services\LotTemplateMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\LotTemplateFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.openai.api_key' => null, 'services.mapbox.token' => 'test-mapbox']);
    Http::preventStrayRequests();
});

it('uses the same template columns for every row without borrowing the installer identity', function () {
    $file = LotTemplateFile::upload([
        LotTemplateFile::row(),
        LotTemplateFile::row([0 => 'REF-2', 8 => 'Autre beneficiaire', 13 => 'Autre installateur']),
        LotTemplateFile::row([0 => 'REF-3', 1 => '', 2 => '', 8 => '']),
    ]);
    $rows = app(LotSpreadsheetExtractor::class)->extract($file);
    $result = app(LotTemplateMapper::class)->normalize($rows)['appointments'];
    expect($result)->toHaveCount(3)
        ->and($result[0]['company_name'])->toBe('Beneficiaire SAS')
        ->and($result[0]['installer_name'])->toBe('Installateur SAS')
        ->and($result[0]['installer_siren'])->toBe('348808007')
        ->and($result[0]['customer_first_name'])->toBe('Camille')
        ->and($result[0]['customer_last_name'])->toBe('MARTIN')
        ->and($result[0]['customer_phone'])->toBe('0608637781')
        ->and($result[0]['postal_code'])->toBe('60110')
        ->and($result[0]['beneficiary_postal_code'])->toBe('75002')
        ->and($result[1]['company_name'])->toBe('Autre beneficiaire')
        ->and($result[1]['installer_name'])->toBe('Autre installateur')
        ->and($result[2]['company_name'])->toBeNull()
        ->and($result[2]['customer_name'])->toBe('Client à qualifier')
        ->and($result[2]['warnings'])->not->toBeEmpty();
    Http::assertNothingSent();
});

it('rejects shifted template columns rather than guessing them', function () {
    $data = array_combine(LotTemplateMapper::HEADERS, LotTemplateFile::row());
    $keys = array_keys($data);
    [$keys[8], $keys[13]] = [$keys[13], $keys[8]];
    expect(fn () => app(LotTemplateMapper::class)->normalize(collect([
        ['row_number' => 2, 'data' => array_combine($keys, array_values($data))],
    ])))->toThrow(RuntimeException::class, 'Colonne I attendue');
    Http::assertNothingSent();
});

it('sends only unique addresses to AI and preserves every other field', function () {
    config(['services.openai.api_key' => 'fake-key']);
    $appointments = app(LotTemplateMapper::class)->normalize(app(LotSpreadsheetExtractor::class)->extract(
        LotTemplateFile::upload([LotTemplateFile::row([9 => '12 RUE DE LARGILIERE-000 AB 0152']), LotTemplateFile::row([9 => ''])]),
    ))['appointments'];
    Http::fake(['api.openai.com/*' => Http::response(['output_text' => json_encode([
        'addresses' => [['id' => 0, 'address' => '12 RUE DE LARGILIERE']],
    ])])]);
    $result = app(LotAddressNormalizer::class)->normalize($appointments);
    expect($result[0]['address'])->toBe('12 RUE DE LARGILIERE')
        ->and($result[0]['beneficiary_address'])->toBe('12 RUE DE LARGILIERE');
    foreach ($appointments as $i => $appointment) {
        foreach (array_diff(array_keys($appointment), ['address', 'address_line', 'beneficiary_address']) as $field) {
            expect($result[$i][$field])->toBe($appointment[$field]);
        }
    }
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => json_decode($request['input'][1]['content'], true) === [
        ['id' => 0, 'address' => '12 RUE DE LARGILIERE'],
    ]);
});

it('falls back safely when AI times out or returns an unsafe address', function ($failure) {
    config(['services.openai.api_key' => 'fake-key']);
    Http::fake(fn () => $failure === 'timeout'
        ? throw new ConnectionException('timeout')
        : Http::response(['output_text' => json_encode(['addresses' => match ($failure) {
            'duplicate' => [['id' => 0, 'address' => '12 RUE'], ['id' => 0, 'address' => '12 RUE']],
            'incomplete' => [],
            'different' => [['id' => 0, 'address' => '99 RUE AILLEURS']],
            'too-short' => [['id' => 0, 'address' => '12']],
        }])])
    );
    $result = app(LotAddressNormalizer::class)->normalize([['address' => '12 RUE DE LARGILIERE-000 AB 0152', 'company_name' => 'Client']]);
    expect($result)->toHaveCount(1)
        ->and($result[0]['address'])->toBe('12 RUE DE LARGILIERE')
        ->and($result[0]['company_name'])->toBe('Client');
})->with(['timeout', 'duplicate', 'incomplete', 'different', 'too-short']);

it('previews and confirms a template with deduplicated inspection geocoding and no AI dependency', function () {
    Storage::fake('local');
    $file = LotTemplateFile::upload([LotTemplateFile::row(), LotTemplateFile::row([0 => 'REF-2'])]);
    $path = $file->store('lot-import-previews', 'local');
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [[
        'id' => 'address.1', 'center' => [2.5, 49.5], 'place_name' => '12 RUE DE LARGILIERE 60110 ESCHES', 'relevance' => 1,
    ]]])]);
    $preview = LotImportPreview::create([
        'uuid' => (string) Str::uuid(), 'status' => LotImportPreview::STATUS_PENDING,
        'type' => Lot::TYPE_FULL_CONTROL, 'name' => 'Lot standard', 'delegataire' => 'Delegataire choisi',
        'original_filename' => 'lot-modele.csv', 'original_file_disk' => 'local', 'original_file_path' => $path,
        'created_by' => User::factory()->create()->id,
    ]);
    app(LotImportPreviewProcessor::class)->process($preview);
    $preview->refresh();
    expect($preview->status)->toBe(LotImportPreview::STATUS_COMPLETED)
        ->and($preview->normalized_rows)->toBe(2)
        ->and($preview->payload['appointments'][0]['warnings'])->toBe([]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains(rawurldecode($request->url()), '12 RUE DE LARGILIERE 60110 ESCHES.json'));
    $lot = app(LotImportConfirmationService::class)->confirm($preview, [2, 3]);
    $appointment = $lot->appointments()->orderBy('row_number')->first();
    expect($lot->appointments()->count())->toBe(2)
        ->and($lot->delegataire)->toBe('Delegataire choisi')
        ->and($appointment->internal_reference)->toBe('ALVEA-ACT-1616542/OP-2261616')
        ->and($appointment->customer_email)->toBe('beneficiaire@example.test')
        ->and($appointment->beneficiary_address)->toBe('10 Rue du Siege')
        ->and($appointment->beneficiary_city)->toBe('Paris')
        ->and($appointment->installer_siren)->toBe('348808007')
        ->and($appointment->ai_confidence)->toBeNull();
    $appointment->update(['source' => 'coffrac', 'external_reference' => '16013']);
    expect($appointment->refresh()->internalReference())->toBe('ALVEA-ACT-1616542/OP-2261616');
});

it('keeps all dossiers when Mapbox cannot resolve their address', function () {
    Storage::fake('local');
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => []])]);
    $preview = LotImportPreview::create([
        'uuid' => (string) Str::uuid(), 'status' => LotImportPreview::STATUS_PENDING,
        'type' => Lot::TYPE_FULL_CONTROL, 'name' => 'Lot adresse inconnue',
        'original_filename' => 'lot-modele.csv', 'original_file_disk' => 'local',
        'original_file_path' => LotTemplateFile::upload()->store('lot-import-previews', 'local'),
        'created_by' => User::factory()->create()->id,
    ]);
    app(LotImportPreviewProcessor::class)->process($preview);
    expect($preview->refresh()->payload['appointments'])->toHaveCount(1)
        ->and($preview->payload['appointments'][0]['address'])->toBe('12 RUE DE LARGILIERE')
        ->and($preview->payload['appointments'][0]['warnings'])->not->toBeEmpty();
});

it('does not erase invalid template fields warnings when only the address is edited', function () {
    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [['center' => [2.5, 49.5]]]])]);
    $mapped = app(LotTemplateMapper::class)->normalize(app(LotSpreadsheetExtractor::class)->extract(
        LotTemplateFile::upload([LotTemplateFile::row([7 => 'bad-email', 12 => '123'])]),
    ))['appointments'][0];
    $preview = LotImportPreview::create([
        'uuid' => (string) Str::uuid(), 'status' => LotImportPreview::STATUS_COMPLETED,
        'type' => Lot::TYPE_FULL_CONTROL,
        'original_filename' => 'lot.csv', 'original_file_disk' => 'local', 'original_file_path' => 'unused.csv',
        'created_by' => User::factory()->create()->id, 'payload' => ['appointments' => [$mapped]],
    ]);
    $attributes = ['address' => '12 RUE DE LARGILIERE', 'postal_code' => '60110', 'city' => 'Esches', 'company_name' => 'Beneficiaire SAS'];
    $service = app(LotImportPreviewRowUpdateService::class);
    $service->update($preview, 2, $attributes);
    expect($preview->refresh()->payload['appointments'][0]['warnings'])->toHaveCount(2);
    $service->update($preview, 2, [...$attributes, 'customer_email' => 'correct@example.test', 'installer_siren' => '348808007']);
    expect($preview->refresh()->payload['appointments'][0]['warnings'])->toBe([]);
});
