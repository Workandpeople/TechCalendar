<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotImportPreview;
use App\Models\Service;
use App\Models\User;
use App\Services\LotImportLocation;
use App\Services\LotTemplateMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\LotTemplateFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'services.mapbox.token' => null, 'cache.default' => 'array']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->actingAs($this->actor);
    $this->service = Service::create(['type' => Service::TYPE_AUDIT, 'name' => 'Visite lot fictif', 'average_duration_minutes' => 60]);
    $this->row = app(LotTemplateMapper::class)->normalize(collect([['row_number' => 2,
        'data' => array_combine(LotTemplateMapper::HEADERS, LotTemplateFile::row())]]))['appointments'][0];
    $this->row['warnings'] = ['Token Mapbox absent.'];
    $this->preview = LotImportPreview::create(['uuid' => (string) Str::uuid(), 'name' => 'Lot localisation fictif',
        'status' => LotImportPreview::STATUS_COMPLETED, 'progress' => 100, 'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $this->service->id, 'original_filename' => 'fixture.csv', 'original_file_disk' => 'local',
        'original_file_path' => 'unused.csv', 'created_by' => $this->actor->id,
        'payload' => ['appointments' => [$this->row, [...$this->row, 'row_number' => 3]]]]);
    $this->attributes = ['company_name' => 'Beneficiaire SAS', 'address' => '12 RUE DE LARGILIERE',
        'postal_code' => '60110', 'city' => 'ESCHES'];
    $this->updateUrl = route('manager.lots.imports.rows.update', [$this->preview, 2]);
    $this->confirmUrl = route('manager.lots.imports.confirm', $this->preview);
    $this->fakeCommune = function (array $extra = []) {
        Http::fake(['geo.api.gouv.fr/communes*' => Http::response([
            ['code' => '60218', 'nom' => 'Esches', 'codesPostaux' => ['60110'], 'centre' => ['type' => 'Point', 'coordinates' => [2.16, 49.22]]], ...$extra,
        ])]);
    };
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('preserves the street and only geocodes the commune after an explicit row choice', function () {
    ($this->fakeCommune)();
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'city'])
        ->assertOk()->assertJsonPath('appointments.0.address', '12 RUE DE LARGILIERE')
        ->assertJsonPath('appointments.0.postal_code', '60110')->assertJsonPath('appointments.0.city', 'ESCHES')
        ->assertJsonPath('appointments.0.latitude', 49.22)->assertJsonPath('appointments.0.longitude', 2.16)
        ->assertJsonPath('appointments.0.geocoding_mode', 'city')->assertJsonPath('appointments.0.blocking_warnings', [])
        ->assertJsonPath('appointments.0.warnings', [LotImportLocation::CITY_WARNING]);
    $rows = $this->preview->fresh()->payload['appointments'];
    expect($rows[0]['location_review']['reviewed_by'])->toBe($this->actor->id)
        ->and($rows[0]['customer_phone'])->toBe($this->row['customer_phone'])
        ->and($rows[0]['customer_first_name'])->toBe($this->row['customer_first_name'])
        ->and($rows[0]['mapbox_id'])->toBe('commune.60218_60110')->and($rows[1])->toBe([...$this->row, 'row_number' => 3]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://geo.api.gouv.fr/communes?') && $request['codePostal'] === '60110');
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertOk();
    $appointment = LotAppointment::sole();
    expect($appointment->address)->toBe('12 RUE DE LARGILIERE')->and($appointment->status)->toBe(LotAppointment::STATUS_NEEDS_REVIEW)
        ->and($appointment->ai_warnings)->toBe([LotImportLocation::CITY_WARNING])
        ->and(LotImportLocation::planningMetadata($appointment)['location_precision'])->toBe('city');
});

it('explicitly keeps an unlocated address without any HTTP call and retains the warning', function () {
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'unverified', 'latitude' => 48, 'longitude' => 2,
        'location_review' => ['reviewed_by' => 999]])->assertOk()
        ->assertJsonPath('appointments.0.latitude', null)->assertJsonPath('appointments.0.longitude', null)
        ->assertJsonPath('appointments.0.geocoding_mode', 'unverified')->assertJsonPath('appointments.0.blocking_warnings', [])
        ->assertJsonPath('appointments.0.location_review.reviewed_by', $this->actor->id);
    $this->postJson($this->confirmUrl, ['selected_keys' => ['preview-row-2-0']])->assertOk();
    $appointment = LotAppointment::sole();
    expect($appointment->latitude)->toBeNull()->and($appointment->longitude)->toBeNull()
        ->and($appointment->address)->toBe('12 RUE DE LARGILIERE')->and(LotImportLocation::canPlan($appointment))->toBeTrue();
    Http::assertNothingSent();
});

it('does not implicitly accept a failed precise geocode', function () {
    $this->patchJson($this->updateUrl, $this->attributes)->assertOk()
        ->assertJsonPath('appointments.0.geocoding_mode', 'address')->assertJsonPath('appointments.0.blocking_warnings', ['Token Mapbox absent.']);
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertUnprocessable();
    expect(Lot::count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps business validation errors blocking even after the location was explicitly accepted', function (string $mode) {
    ($this->fakeCommune)();
    $payload = $this->preview->payload;
    $payload['appointments'][0]['customer_email'] = 'invalid-email';
    $payload['appointments'][0]['warnings'][] = 'Adresse email du bénéficiaire invalide.';
    $this->preview->update(['payload' => $payload]);
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => $mode])->assertOk()
        ->assertJsonPath('appointments.0.blocking_warnings', ['Adresse email du bénéficiaire invalide.']);
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertUnprocessable();
    expect(Lot::count())->toBe(0);
})->with(['city', 'unverified']);

it('does not swallow unknown business warnings in older previews', function () {
    $payload = $this->preview->payload;
    unset($payload['appointments'][0]['import_format']);
    $payload['appointments'][0]['warnings'][] = 'Consigne métier à vérifier.';
    $this->preview->update(['payload' => $payload]);
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'unverified'])->assertOk()
        ->assertJsonPath('appointments.0.blocking_warnings', ['Consigne métier à vérifier.']);
    Http::assertNothingSent();
});

it('refuses incomplete or invalid fallback requests without modifying the preview', function (array $override) {
    $before = $this->preview->payload;
    $this->patchJson($this->updateUrl, array_replace($this->attributes, ['geocoding_mode' => 'unverified'], $override))->assertUnprocessable();
    expect($this->preview->fresh()->payload)->toBe($before);
    Http::assertNothingSent();
})->with([[['address' => '']], [['postal_code' => '6011']], [['postal_code' => 'incorrect']], [['city' => '']],
    [['geocoding_mode' => 'arbitrary']], [['geocoding_mode' => ['city']]]]);

it('refuses an unavailable ambiguous or contradictory commune instead of accepting an arbitrary point', function (string $case) {
    $rows = $case === 'ambiguous' ? [['code' => '60219', 'nom' => 'Esches', 'codesPostaux' => ['60110'],
        'centre' => ['type' => 'Point', 'coordinates' => [2.3, 49.4]]]] : [];
    if ($case === 'unavailable') {
        Http::fake(['geo.api.gouv.fr/*' => Http::response([], 503)]);
    } else {
        ($this->fakeCommune)($rows);
    }
    $before = $this->preview->payload;
    $this->patchJson($this->updateUrl, array_replace($this->attributes, ['geocoding_mode' => 'city'],
        $case === 'mismatched' ? ['city' => 'Autre ville'] : []))->assertUnprocessable()->assertJsonValidationErrors('geocoding_mode');
    expect($this->preview->fresh()->payload)->toBe($before);
})->with(['ambiguous', 'unavailable', 'mismatched']);

it('does not let an unrelated later address change reuse a prior fallback agreement', function (string $field) {
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'unverified'])->assertOk();
    $payload = $this->preview->fresh()->payload;
    $payload['appointments'][0][$field] = $field === 'postal_code' ? '75001' : 'Autre lieu';
    $this->preview->update(['payload' => $payload]);
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertUnprocessable();
    expect(Lot::count())->toBe(0);
})->with(['address', 'postal_code', 'city']);

it('resets an earlier fallback when a precise lookup is requested again', function () {
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'unverified'])->assertOk();
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'address'])->assertOk()
        ->assertJsonPath('appointments.0.location_review', null)->assertJsonPath('appointments.0.geocoding_mode', 'address');
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertUnprocessable();
});

it('restricts fallback changes to authorized internal managers', function (int $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'admin' => false]));
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'unverified'])->assertForbidden();
    expect($this->preview->fresh()->payload['appointments'][0])->toBe($this->row);
    Http::assertNothingSent();
})->with([1, 2, 3, 4]);

it('only enables manual physical planning for an explicitly reviewed unlocated lot address', function () {
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'unverified'])->assertOk();
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertOk();
    $row = LotAppointment::sole();
    Department::updateOrCreate(['code' => '60'], ['name' => 'Oise']);
    Department::updateOrCreate(['code' => '75'], ['name' => 'Paris']);
    $tech = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 49.3, 'longitude' => 2.2,
        'day_start_time' => '08:00', 'day_end_time' => '18:00', 'notification_mail_enabled' => false, 'notification_push_enabled' => false]);
    $tech->services()->attach($this->service);
    $tech->departments()->attach('60');
    $outside = User::factory()->create(['role' => 2, 'admin' => false]);
    $outside->services()->attach($this->service);
    $outside->departments()->attach('75');
    $this->postJson(route('planner.book.analyze'), ['lot_appointment_id' => $row->id])->assertOk()
        ->assertJsonPath('crm_appointment.postal_code', '60110')->assertJsonPath('crm_appointment.city', 'ESCHES')
        ->assertJsonPath('crm_appointment.latitude', null)->assertJsonPath('crm_appointment.longitude', null)
        ->assertJsonPath('crm_appointment.location_precision', 'unverified')->assertJsonPath('suggestions', [])
        ->assertJsonCount(1, 'technicians')->assertJsonPath('technicians.0.id', $tech->id);
    $this->get(route('planner.book'))->assertOk()->assertSee('data-booking-summary-field="postal_code"', false)
        ->assertSee('data-booking-summary-field="city"', false);
    $placement = ['lot_appointment_id' => $row->id, 'technician_id' => $tech->id,
        'starts_at' => now()->addWeekdays(2)->setTime(10, 0)->format('Y-m-d\TH:i:s'), 'duration_minutes' => 60];
    $this->postJson(route('planner.book.appointments.store'), $placement)->assertUnprocessable();
    expect(Appointment::count())->toBe(0);
    $this->postJson(route('planner.book.appointments.store'), $placement + ['force_placement' => true, 'force_confirmed' => true])->assertCreated();
    $placed = Appointment::sole();
    expect($placed->latitude)->toBeNull()->and($placed->longitude)->toBeNull()
        ->and($placed->external_payload['location_precision'])->toBe('unverified')
        ->and($placed->external_payload['postal_code'])->toBe('60110')->and($placed->external_payload['city'])->toBe('ESCHES');
    Http::assertNothingSent();
});

it('carries the approximate city precision through physical placement instead of claiming a precise street position', function () {
    ($this->fakeCommune)();
    $this->patchJson($this->updateUrl, $this->attributes + ['geocoding_mode' => 'city', 'customer_phone' => ''])->assertOk();
    $this->postJson($this->confirmUrl, ['selected_rows' => [2]])->assertOk();
    $row = LotAppointment::sole();
    Department::updateOrCreate(['code' => '60'], ['name' => 'Oise']);
    $tech = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 49.22, 'longitude' => 2.16,
        'day_start_time' => '08:00', 'day_end_time' => '18:00', 'notification_mail_enabled' => false, 'notification_push_enabled' => false]);
    $tech->services()->attach($this->service);
    $tech->departments()->attach('60');
    $this->postJson(route('planner.book.analyze'), ['lot_appointment_id' => $row->id])->assertOk()
        ->assertJsonPath('crm_appointment.location_precision', 'city')->assertJsonPath('crm_appointment.location_warning', LotImportLocation::CITY_WARNING)
        ->assertJsonPath('crm_appointment.postal_code', '60110')->assertJsonPath('crm_appointment.city', 'ESCHES')
        ->assertJsonPath('crm_appointment.address', '12 RUE DE LARGILIERE');
    $this->postJson(route('planner.book.appointments.store'), ['lot_appointment_id' => $row->id, 'technician_id' => $tech->id,
        'starts_at' => now()->addWeekdays(2)->setTime(10, 0)->format('Y-m-d\TH:i:s'), 'duration_minutes' => 60])->assertCreated();
    $appointment = Appointment::sole();
    expect((float) $appointment->latitude)->toBe(49.22)->and((float) $appointment->longitude)->toBe(2.16)
        ->and($appointment->external_payload['location_precision'])->toBe('city')->and($appointment->customer_phone)->toBe('');
    Http::assertSentCount(1);
});
