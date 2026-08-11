<?php

use App\Jobs\ProcessLotImportPreviewJob;
use App\Models\Appointment;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotImportPreview;
use App\Models\Service;
use App\Models\User;
use App\Services\LotAppointmentAiNormalizer;
use App\Services\LotBusinessIdentityResolver;
use App\Services\LotExcelImportService;
use App\Services\LotImportPreviewProcessor;
use App\Services\LotSpreadsheetExtractor;
use App\Services\ImportedAddressCleaner;
use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('renders manager lots from database', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $placedStartsAt = now()->copy()->addDay()->setTime(9, 0);
    $lot = Lot::query()->create([
        'name' => 'Lot Audit Juin',
        'type' => Lot::TYPE_FULL_CONTACT_CONTROL,
        'source' => 'Export AuditPro',
        'comment' => 'Commentaires internes du lot.',
        'original_filename' => 'audit-juin.xlsx',
        'total_rows' => 1,
        'imported_rows' => 1,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'source' => 'Export AuditPro',
        'customer_name' => 'Camille Martin',
        'customer_phone' => '06 12 34 80 69',
        'address' => '20 Rue Bellecordiere',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'service_type' => Service::TYPE_AUDIT,
        'service_name' => 'Audit qualité site client',
        'status' => LotAppointment::STATUS_PENDING,
    ]);
    $placedAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $manager->id,
        'customer_first_name' => 'Lucie',
        'customer_last_name' => 'Placee',
        'customer_phone' => '06 12 34 80 70',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'starts_at' => $placedStartsAt,
        'duration_minutes' => 120,
        'ends_at' => $placedStartsAt->copy()->addMinutes(120),
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'appointment_id' => $placedAppointment->id,
        'source' => 'Export AuditPro',
        'customer_name' => 'Lucie Placee',
        'customer_phone' => '06 12 34 80 70',
        'address' => '10 Rue de la Barre',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_PLACED,
        'raw_payload' => [
            'postal_code' => '69100',
            'city' => 'Villeurbanne',
        ],
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots'))
        ->assertOk()
        ->assertSee('Gestion des lots')
        ->assertSee('Importer un lot')
        ->assertSee('Lot Audit Juin')
        ->assertSee('100% contrôle contact')
        ->assertSee('audit-juin.xlsx')
        ->assertSee('Voir le détail')
        ->assertSee(route('manager.lots.show', $lot), false)
        ->assertSee('Lots en cours')
        ->assertSee('Lots à facturer')
        ->assertSee('Lots archivés')
        ->assertSee('Modifier le lot')
        ->assertSee('Commentaires du lot')
        ->assertSee('Commentaires internes du lot.')
        ->assertSee('Modifier')
        ->assertSee('Supprimer le lot')
        ->assertDontSee('RDV total')
        ->assertDontSee('Camille Martin');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('Lot Audit Juin')
        ->assertSee('Dossiers du fichier')
        ->assertSee('Camille Martin')
        ->assertSee('20 Rue Bellecordiere')
        ->assertSee('69002 Lyon')
        ->assertSee('Lucie Placee')
        ->assertSee('69100 Villeurbanne')
        ->assertSee('RDV physique placé')
        ->assertSee('Voir dans la gestion des RDV')
        ->assertSee('Portes : 0')
        ->assertSee('appointment_id='.$placedAppointment->id, false)
        ->assertSee('Détail physique')
        ->assertSee('id="lot-detail-edit-open"', false)
        ->assertSee('Modifier le lot Lot Audit Juin')
        ->assertSee('Date de réception du lot')
        ->assertSee('name="appointment_global_plus"', false)
        ->assertSee('lot-appointment-global-plus-checkbox', false)
        ->assertDontSee('id="lot-physical-global-plus"', false)
        ->assertDontSee('id="lot-contact-global-plus"', false)
        ->assertDontSee('data-lot-appointment-global-plus-badge', false);
});

it('updates a lot from the manager lot action modal', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot initial',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client ouvert',
        'address' => '1 Rue de Test',
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($manager)
        ->from(route('manager.lots'))
        ->patch(route('manager.lots.update', $lot), [
            'name' => 'Lot corrigé',
            'delegataire' => 'Oblige Test',
            'received_at' => '2026-08-03',
            'comment' => 'Consignes spécifiques pour ce lot.',
            'type' => Lot::TYPE_SAMPLE_CONTROL,
            'service_id' => $service->id,
            'status' => Lot::STATUS_IN_PROGRESS,
            'sampling_percentage' => 35,
        ])
        ->assertRedirect(route('manager.lots'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Lot "Lot corrigé" mis à jour.');

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'name' => 'Lot corrigé',
        'delegataire' => 'Oblige Test',
        'comment' => 'Consignes spécifiques pour ce lot.',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_IN_PROGRESS,
        'sampling_percentage' => 35,
    ]);
    expect($lot->refresh()->received_at?->format('Y-m-d'))->toBe('2026-08-03');
    $this->assertDatabaseHas('lot_appointments', [
        'id' => $lotAppointment->id,
        'service_id' => $service->id,
        'service_type' => Service::TYPE_AUDIT,
        'service_name' => 'Audit qualité site client',
        'duration_minutes' => 120,
    ]);
});

it('archives an invoice-ready lot from the manager lot edit modal', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot à facturer',
        'type' => Lot::TYPE_FULL_CONTACT_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_TO_INVOICE,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'customer_name' => 'Client terminé',
        'customer_phone' => '0600000011',
        'address' => '1 Rue Test',
        'status' => LotAppointment::STATUS_CONTACT_PROCESSED,
        'processing_mode' => LotAppointment::PROCESSING_MODE_CONTACT,
        'contact_satisfaction' => true,
        'contact_comment' => 'Client satisfait',
        'contact_processed_at' => now(),
        'contact_processed_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->from(route('manager.lots'))
        ->patch(route('manager.lots.update', $lot), [
            'name' => 'Lot à facturer',
            'delegataire' => 'Oblige Test',
            'type' => Lot::TYPE_FULL_CONTACT_CONTROL,
            'service_id' => $service->id,
            'archive_lot' => '1',
        ])
        ->assertRedirect(route('manager.lots'))
        ->assertSessionHasNoErrors();

    expect($lot->refresh()->status)->toBe(Lot::STATUS_ARCHIVED);
});

it('deletes an unplaced lot and its original file', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $path = 'lot-imports/delete-me.xlsx';
    Storage::disk('local')->put($path, 'spreadsheet-content');

    $lot = Lot::query()->create([
        'name' => 'Lot à supprimer',
        'type' => Lot::TYPE_FULL_CONTROL,
        'original_filename' => 'delete-me.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => $path,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client non placé',
        'address' => '1 Rue de Test',
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($manager)
        ->from(route('manager.lots'))
        ->delete(route('manager.lots.destroy', $lot))
        ->assertRedirect(route('manager.lots'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Lot "Lot à supprimer" supprimé.');

    $this->assertDatabaseMissing('lots', ['id' => $lot->id]);
    $this->assertDatabaseMissing('lot_appointments', ['id' => $lotAppointment->id]);
    Storage::disk('local')->assertMissing($path);
});

it('blocks lot deletion when at least one appointment is already placed', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot avec RDV placé',
        'type' => Lot::TYPE_FULL_CONTROL,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client placé',
        'address' => '1 Rue de Test',
        'status' => LotAppointment::STATUS_PLACED,
    ]);

    $this->actingAs($manager)
        ->from(route('manager.lots'))
        ->delete(route('manager.lots.destroy', $lot))
        ->assertRedirect(route('manager.lots'))
        ->assertSessionHasErrors('lot');

    $this->assertDatabaseHas('lots', [
        'id' => $lot->id,
        'name' => 'Lot avec RDV placé',
    ]);
});

it('allows an admin to locally delete a started lot and linked appointments', function () {
    $admin = User::factory()->create([
        'role' => 0,
        'admin' => true,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $startsAt = now()->copy()->addDay()->setTime(10, 0);
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $admin->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Lot',
        'customer_phone' => '0612345678',
        'address' => '10 Rue de Test',
        'latitude' => 45.75,
        'longitude' => 4.83,
        'starts_at' => $startsAt,
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addMinutes(120),
        'external_source' => 'coffrac',
        'external_reference' => 'remote-42',
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot démarré',
        'type' => Lot::TYPE_FULL_CONTROL,
        'created_by' => $admin->id,
        'imported_at' => now(),
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'appointment_id' => $appointment->id,
        'customer_name' => 'Client Lot',
        'address' => '10 Rue de Test',
        'status' => LotAppointment::STATUS_PLACED,
    ]);

    $this->actingAs($admin)
        ->from(route('manager.lots'))
        ->delete(route('manager.lots.destroy', $lot))
        ->assertRedirect(route('manager.lots'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Lot "Lot démarré" supprimé.');

    $this->assertDatabaseMissing('lots', ['id' => $lot->id]);
    $this->assertDatabaseMissing('lot_appointments', ['id' => $lotAppointment->id]);
    $this->assertSoftDeleted('appointments', ['id' => $appointment->id]);
});

it('updates a persisted lot appointment and refreshes geocoding data', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot à corriger',
        'type' => Lot::TYPE_FULL_CONTROL,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'row_number' => 4,
        'customer_name' => 'Client ancien',
        'customer_phone' => '0600000000',
        'address' => 'Ancienne adresse',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.75,
        'longitude' => 4.83,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $geocodeCalls = 0;
    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->twice()
        ->with('1 LES PETITES GRANGES 01000 Bourg-en-Bresse')
        ->andReturnUsing(function () use (&$geocodeCalls): array {
            $geocodeCalls++;

            return $geocodeCalls === 1 ? [
                'latitude' => 46.204391,
                'longitude' => 5.22512,
                'formatted_address' => '1 Les Petites Granges, 01000 Bourg-en-Bresse, France',
                'mapbox_id' => 'address.456',
                'mapbox_confidence' => 0.91,
                'warnings' => [],
            ] : [
                'latitude' => 46.205,
                'longitude' => 5.226,
                'formatted_address' => '1 Les Petites Granges, 01000 Bourg-en-Bresse, France',
                'mapbox_id' => 'address.789',
                'mapbox_confidence' => 0.93,
                'warnings' => [],
            ];
        });
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.update', $lotAppointment), [
            'external_reference' => 'EXT-42',
            'customer_name' => 'Camille Martin',
            'customer_first_name' => 'Camille',
            'customer_last_name' => 'Martin',
            'customer_phone' => '0612345678',
            'address' => '1 LES PETITES GRANGES - 000 0E 0369 - 000 0E 0370',
            'postal_code' => '01000',
            'city' => 'Bourg-en-Bresse',
            'comment' => 'Correction depuis la gestion des lots.',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'RDV du lot mis à jour.')
        ->assertJsonPath('appointment.customer_name', 'Camille Martin')
        ->assertJsonPath('appointment.address', '1 LES PETITES GRANGES')
        ->assertJsonPath('appointment.postal_code', '01000')
        ->assertJsonPath('appointment.city', 'Bourg-en-Bresse')
        ->assertJsonPath('appointment.department_code', '01')
        ->assertJsonPath('appointment.latitude', 46.204391)
        ->assertJsonPath('appointment.longitude', 5.22512);

    $this->assertDatabaseHas('lot_appointments', [
        'id' => $lotAppointment->id,
        'external_reference' => 'EXT-42',
        'customer_name' => 'Camille Martin',
        'address' => '1 LES PETITES GRANGES',
        'postal_code' => '01000',
        'city' => 'Bourg-en-Bresse',
        'department_code' => '01',
        'latitude' => 46.204391,
        'longitude' => 5.22512,
    ]);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.update', $lotAppointment), [
            'external_reference' => 'EXT-42',
            'customer_name' => 'Camille Martin',
            'customer_first_name' => 'Camille',
            'customer_last_name' => 'Martin',
            'customer_phone' => '0612345678',
            'address' => '1 LES PETITES GRANGES',
            'postal_code' => '01000',
            'city' => 'Bourg-en-Bresse',
            'comment' => 'Correction depuis la gestion des lots.',
            'force_geocode' => true,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.latitude', 46.205)
        ->assertJsonPath('appointment.longitude', 5.226);
});

it('filters manager lots by lot status', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    Lot::query()->create([
        'name' => 'Lot en cours',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'status' => Lot::STATUS_IN_PROGRESS,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    Lot::query()->create([
        'name' => 'Lot complet',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'status' => Lot::STATUS_COMPLETED,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots', ['status' => Lot::STATUS_IN_PROGRESS]))
        ->assertOk()
        ->assertSee('Lot en cours')
        ->assertDontSee('Lot complet');
});

it('filters manager lots by lot type', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);

    Lot::query()->create([
        'name' => 'Lot contact',
        'type' => Lot::TYPE_FULL_CONTACT_CONTROL,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);
    Lot::query()->create([
        'name' => 'Lot échantillon',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots', ['type' => Lot::TYPE_FULL_CONTACT_CONTROL]))
        ->assertOk()
        ->assertSee('Lot contact')
        ->assertDontSee('Lot échantillon');
});

it('paginates manager lots by nine cards', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);

    foreach (range(1, 10) as $index) {
        Lot::query()->create([
            'name' => sprintf('Lot pagination %02d', $index),
            'type' => Lot::TYPE_FULL_CONTROL,
            'status' => Lot::STATUS_NOT_STARTED,
            'created_by' => $manager->id,
            'created_at' => now()->copy()->addMinutes($index),
            'updated_at' => now()->copy()->addMinutes($index),
        ]);
    }

    $this->actingAs($manager)
        ->get(route('manager.lots'))
        ->assertOk()
        ->assertSee('Lot pagination 10')
        ->assertSee('Lot pagination 02')
        ->assertDontSee('Lot pagination 01')
        ->assertSee('page=2', false);

    $this->actingAs($manager)
        ->get(route('manager.lots', ['page' => 2]))
        ->assertOk()
        ->assertSee('Lot pagination 01')
        ->assertDontSee('Lot pagination 02');
});

it('paginates lot detail appointments', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot detail pagine',
        'type' => Lot::TYPE_FULL_CONTROL,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    foreach (range(1, 26) as $index) {
        LotAppointment::query()->create([
            'lot_id' => $lot->id,
            'row_number' => $index,
            'customer_name' => sprintf('Client dossier %02d', $index),
            'address' => sprintf('%d Rue du Test', $index),
            'status' => LotAppointment::STATUS_PENDING,
        ]);
    }

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('Client dossier 01')
        ->assertSee('Client dossier 25')
        ->assertDontSee('Client dossier 26')
        ->assertSee('appointments_page=2', false);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointments_page' => 2]))
        ->assertOk()
        ->assertSee('Client dossier 26')
        ->assertDontSee('Client dossier 01');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'per_page' => 10]))
        ->assertOk()
        ->assertSee('Client dossier 01')
        ->assertSee('Client dossier 10')
        ->assertDontSee('Client dossier 11')
        ->assertSee('per_page=10', false);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'per_page' => 10, 'appointments_page' => 2]))
        ->assertOk()
        ->assertSee('Client dossier 11')
        ->assertSee('Client dossier 20')
        ->assertDontSee('Client dossier 21');
});

it('filters lot detail appointments dynamically', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot filtres detail',
        'type' => Lot::TYPE_HYBRID_LOCATION_CONTACT,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'row_number' => 1,
        'customer_name' => 'Alpha Industrie',
        'company_name' => 'Alpha Industrie',
        'address' => '10 Rue Alpha',
        'city' => 'Lyon',
        'status' => LotAppointment::STATUS_PLACED,
        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'row_number' => 2,
        'customer_name' => 'Beta Contact',
        'address' => '20 Rue Beta',
        'city' => 'Villeurbanne',
        'status' => LotAppointment::STATUS_CONTACT_PROCESSED,
        'processing_mode' => LotAppointment::PROCESSING_MODE_CONTACT,
        'contact_satisfaction' => true,
        'added_to_global_plus' => true,
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'row_number' => 3,
        'customer_name' => 'Gamma Hors Stats',
        'address' => '30 Rue Gamma',
        'city' => 'Bron',
        'status' => LotAppointment::STATUS_PENDING,
        'excluded_from_lot_stats' => true,
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'row_number' => 4,
        'customer_name' => 'Delta Non Satisfait',
        'address' => '40 Rue Delta',
        'city' => 'Oullins',
        'status' => LotAppointment::STATUS_PLACED,
        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
        'physical_satisfaction' => false,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_q' => 'Alpha']))
        ->assertOk()
        ->assertSee('Alpha Industrie')
        ->assertDontSee('Beta Contact')
        ->assertDontSee('Gamma Hors Stats');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_status' => LotAppointment::STATUS_CONTACT_PROCESSED]))
        ->assertOk()
        ->assertSee('Beta Contact')
        ->assertDontSee('Alpha Industrie')
        ->assertDontSee('Gamma Hors Stats');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_processing' => 'excluded']))
        ->assertOk()
        ->assertSee('Gamma Hors Stats')
        ->assertDontSee('Alpha Industrie')
        ->assertDontSee('Delta Non Satisfait')
        ->assertDontSee('Beta Contact');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_satisfaction' => 'satisfied']))
        ->assertOk()
        ->assertSee('Beta Contact')
        ->assertDontSee('Alpha Industrie')
        ->assertDontSee('Gamma Hors Stats')
        ->assertDontSee('Delta Non Satisfait');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_satisfaction' => 'unsatisfied']))
        ->assertOk()
        ->assertSee('Delta Non Satisfait')
        ->assertSee('bg-rose-50/80', false)
        ->assertDontSee('Alpha Industrie')
        ->assertDontSee('Gamma Hors Stats')
        ->assertDontSee('Beta Contact');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('lot-appointment-global-plus-checkbox', false)
        ->assertSee('bg-emerald-50/70', false)
        ->assertSee('bg-rose-50/80', false);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_global_plus' => '1']))
        ->assertOk()
        ->assertSee('Beta Contact')
        ->assertDontSee('Alpha Industrie')
        ->assertDontSee('Gamma Hors Stats')
        ->assertDontSee('Delta Non Satisfait');

    $this->actingAs($manager)
        ->get(route('manager.lots.show', [$lot, 'appointment_global_plus' => '0']))
        ->assertOk()
        ->assertSee('Alpha Industrie')
        ->assertSee('Gamma Hors Stats')
        ->assertSee('Delta Non Satisfait')
        ->assertDontSee('Beta Contact');
});

it('renders lot auto completion based on sampling target', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot échantillonné',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'sampling_percentage' => 50,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    foreach (range(1, 4) as $index) {
        LotAppointment::query()->create([
            'lot_id' => $lot->id,
            'row_number' => $index,
            'customer_name' => 'Client '.$index,
            'address' => 'Adresse '.$index,
            'status' => $index === 1 ? LotAppointment::STATUS_PLACED : LotAppointment::STATUS_PENDING,
        ]);
    }

    $this->actingAs($manager)
        ->get(route('manager.lots'))
        ->assertOk()
        ->assertSee('Physiques')
        ->assertSee('1 / 2')
        ->assertSee('pris / cible')
        ->assertSee('Satisfaction générale')
        ->assertDontSee('Insatisfaction générale')
        ->assertSee('0,00%');
});

it('renders lot detail satisfaction split in circular charts', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot satisfaction',
        'type' => Lot::TYPE_SAMPLE_CONTACT_CONTROL,
        'sampling_percentage' => 50,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    foreach (range(1, 4) as $index) {
        LotAppointment::query()->create([
            'lot_id' => $lot->id,
            'row_number' => $index,
            'customer_name' => 'Client satisfaction '.$index,
            'address' => 'Adresse satisfaction '.$index,
            'status' => $index <= 2 ? LotAppointment::STATUS_CONTACT_PROCESSED : LotAppointment::STATUS_PENDING,
            'comment' => $index === 1 ? 'Commentaire général du dossier' : null,
            'contact_comment' => $index === 1 ? 'Commentaire de qualification contact' : null,
            'contact_satisfaction' => match ($index) {
                1 => true,
                2 => false,
                default => null,
            },
        ]);
    }

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('Satisfaction générale')
        ->assertSee('25,00%')
        ->assertDontSee('Taux de satisfaction total')
        ->assertSee('Insatisfaction contacts')
        ->assertSee('1 / 2 dossier(s) traité(s)')
        ->assertSee('1 / 2 satisfaisant(s)')
        ->assertSee('Cible satisfaction : 50,00%')
        ->assertSee('2 réponse(s) de satisfaction sur 4 dossier(s) du lot.')
        ->assertSee('1 satisfaisant(s)')
        ->assertSee('1 non satisfaisant(s)')
        ->assertSee('data-lot-appointment-row', false)
        ->assertSee('Commentaires du dossier')
        ->assertSee('Commentaire g\\u00e9n\\u00e9ral du dossier', false)
        ->assertSee('Commentaire de qualification contact', false)
        ->assertSee('--satisfied:25', false)
        ->assertSee('--answered:50', false);
});

it('keeps hybrid lot physical and contact appointment targets separated', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot hybride séparé',
        'type' => Lot::TYPE_HYBRID_LOCATION_CONTACT,
        'physical_sampling_percentage' => 20,
        'contact_sampling_percentage' => 30,
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    foreach (range(1, 10) as $index) {
        LotAppointment::query()->create([
            'lot_id' => $lot->id,
            'row_number' => $index,
            'customer_name' => 'Client hybride '.$index,
            'address' => 'Adresse hybride '.$index,
            'status' => match ($index) {
                1 => LotAppointment::STATUS_PLACED,
                2 => LotAppointment::STATUS_CONTACT_PROCESSED,
                default => LotAppointment::STATUS_PENDING,
            },
            'processing_mode' => match ($index) {
                1 => LotAppointment::PROCESSING_MODE_PHYSICAL,
                2 => LotAppointment::PROCESSING_MODE_CONTACT,
                default => null,
            },
            // Ces valeurs croisées protègent contre le mélange des canaux dans les widgets.
            'contact_satisfaction' => $index === 1 ? true : ($index === 2 ? false : null),
            'physical_satisfaction' => $index === 1 ? true : ($index === 2 ? true : null),
        ]);
    }

    $response = $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk();

    expect($response->getContent())
        ->toMatch('/RDV physiques.*?1\\s*\\/\\s*2/s')
        ->and($response->getContent())
        ->toMatch('/Contacts traités.*?1\\s*\\/\\s*3/s')
        ->and($response->getContent())
        ->not->toMatch('/Contacts traités.*?2\\s*\\/\\s*3/s')
        ->and($response->getContent())
        ->toContain('Satisfaction RDV physiques')
        ->and($response->getContent())
        ->toContain('Satisfaction contacts')
        ->and($response->getContent())
        ->toContain('Insatisfaction RDV physiques')
        ->and($response->getContent())
        ->toContain('Insatisfaction contacts')
        ->and($response->getContent())
        ->not->toContain('Satisfaction générale');

    $this->actingAs($manager)
        ->get(route('manager.lots'))
        ->assertOk()
        ->assertSee('Satisfaction RDV physiques')
        ->assertSee('Satisfaction contacts')
        ->assertSee('Insatisfaction RDV physiques')
        ->assertSee('Insatisfaction contacts')
        ->assertSee('100,00%')
        ->assertSee('Cible satisfaction : 20,00%')
        ->assertSee('Cible satisfaction : 30,00%');
});

it('updates manual appointment targets without changing satisfaction targets', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot objectifs RDV',
        'type' => Lot::TYPE_HYBRID_LOCATION_CONTACT,
        'physical_sampling_percentage' => 50,
        'contact_sampling_percentage' => 25,
        'received_at' => '2026-08-04',
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    foreach (range(1, 4) as $index) {
        LotAppointment::query()->create([
            'lot_id' => $lot->id,
            'row_number' => $index,
            'customer_name' => 'Client objectif '.$index,
            'address' => 'Adresse objectif '.$index,
            'status' => match ($index) {
                1 => LotAppointment::STATUS_PLACED,
                2 => LotAppointment::STATUS_CONTACT_PROCESSED,
                default => LotAppointment::STATUS_PENDING,
            },
            'processing_mode' => match ($index) {
                1 => LotAppointment::PROCESSING_MODE_PHYSICAL,
                2 => LotAppointment::PROCESSING_MODE_CONTACT,
                default => null,
            },
            'contact_satisfaction' => $index === 2 ? true : null,
        ]);
    }

    $this->actingAs($manager)
        ->patch(route('manager.lots.appointment-targets.update', $lot), [
            'physical_appointment_target_count' => 3,
            'contact_appointment_target_count' => 2,
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Objectifs de RDV du lot "Lot objectifs RDV" mis à jour.');

    $lot->refresh();

    expect($lot->physical_appointment_target_count)->toBe(3)
        ->and($lot->contact_appointment_target_count)->toBe(2);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('2 / 4')
        ->assertSee('traités / total')
        ->assertSee('04/08/2026')
        ->assertSee('1 / 3')
        ->assertSee('1 / 2')
        ->assertSee('objectif manuel')
        ->assertSee('1 réponse(s) de satisfaction')
        ->assertSee('sur 4 dossier(s) du lot.')
        ->assertSee('Cible satisfaction : 50,00% · objectif 2 dossier(s)')
        ->assertSee('Cible satisfaction : 25,00% · objectif 1 dossier(s)');
});

it('excludes and reintegrates a lot appointment from lot statistics', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $startsAt = now()->copy()->addDay()->setTime(10, 0);
    $placedAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $manager->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Place',
        'customer_phone' => '0600000004',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'starts_at' => $startsAt,
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addMinutes(120),
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot ajustable',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_IN_PROGRESS,
        'created_by' => $manager->id,
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'appointment_id' => $placedAppointment->id,
        'customer_name' => 'Client placé',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_PLACED,
        'physical_satisfaction' => true,
        'physical_satisfaction_synced_at' => now(),
    ]);
    $pendingAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client à sortir',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.stats-exclusion.update', $pendingAppointment), [
            'excluded_from_lot_stats' => true,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.excluded_from_lot_stats', true)
        ->assertJsonPath('reload_required', true);

    $pendingAppointment->refresh();
    $lot->refresh();

    expect($pendingAppointment->excluded_from_lot_stats)->toBeTrue()
        ->and($pendingAppointment->excluded_from_lot_stats_by)->toBe($manager->id)
        ->and($lot->status)->toBe(Lot::STATUS_TO_INVOICE);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.stats-exclusion.update', $pendingAppointment), [
            'excluded_from_lot_stats' => false,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.excluded_from_lot_stats', false);

    $pendingAppointment->refresh();
    $lot->refresh();

    expect($pendingAppointment->excluded_from_lot_stats)->toBeFalse()
        ->and($pendingAppointment->excluded_from_lot_stats_at)->toBeNull()
        ->and($pendingAppointment->excluded_from_lot_stats_by)->toBeNull()
        ->and($lot->status)->toBe(Lot::STATUS_IN_PROGRESS);
});

it('toggles global plus on a lot appointment through ajax', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot Global Plus',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $manager->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Global',
        'customer_phone' => '0600000022',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_PENDING,
        'added_to_global_plus' => false,
    ]);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.global-plus.update', $lotAppointment), [
            'added_to_global_plus' => true,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.added_to_global_plus', true)
        ->assertJsonPath('message', 'Dossier ajouté au Global +.');

    expect($lotAppointment->refresh()->added_to_global_plus)->toBeTrue();

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.global-plus.update', $lotAppointment), [
            'added_to_global_plus' => false,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.added_to_global_plus', false)
        ->assertJsonPath('message', 'Dossier retiré du Global +.');

    expect($lotAppointment->refresh()->added_to_global_plus)->toBeFalse();
});

it('resets a processed contact lot appointment to not placed', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot contact à reprendre',
        'type' => Lot::TYPE_SAMPLE_CONTACT_CONTROL,
        'status' => Lot::STATUS_TO_INVOICE,
        'created_by' => $manager->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client à reprendre',
        'customer_phone' => '0600000099',
        'address' => '20 Place Bellecour',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_CONTACT_PROCESSED,
        'processing_mode' => LotAppointment::PROCESSING_MODE_CONTACT,
        'contact_satisfaction' => false,
        'contact_comment' => 'Client non satisfaisant',
        'contact_processed_at' => now(),
        'contact_processed_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.reset-processing', $lotAppointment))
        ->assertOk()
        ->assertJsonPath('appointment.status', LotAppointment::STATUS_NOT_PLACED)
        ->assertJsonPath('appointment.status_label', 'Ne pas placer')
        ->assertJsonPath('appointment.processing_mode', null)
        ->assertJsonPath('appointment.contact_satisfaction', null)
        ->assertJsonPath('appointment.contact_comment', null)
        ->assertJsonPath('appointment.can_reset_processing', false)
        ->assertJsonPath('reload_required', true);

    $lotAppointment->refresh();
    $lot->refresh();

    expect($lotAppointment->status)->toBe(LotAppointment::STATUS_NOT_PLACED)
        ->and($lotAppointment->processing_mode)->toBeNull()
        ->and($lotAppointment->contact_satisfaction)->toBeNull()
        ->and($lotAppointment->contact_comment)->toBeNull()
        ->and($lotAppointment->contact_processed_at)->toBeNull()
        ->and($lotAppointment->contact_processed_by)->toBeNull()
        ->and($lot->status)->toBe(Lot::STATUS_IN_PROGRESS);
});

it('resets a placed physical lot appointment and removes the local calendar appointment', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $startsAt = now()->copy()->addDay()->setTime(10, 0);
    $calendarAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $manager->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Physique',
        'customer_phone' => '0600000088',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'starts_at' => $startsAt,
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addMinutes(120),
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot physique à reprendre',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_TO_INVOICE,
        'created_by' => $manager->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'appointment_id' => $calendarAppointment->id,
        'customer_name' => 'Client Physique',
        'customer_phone' => '0600000088',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_PLACED,
        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
        'physical_satisfaction' => false,
        'physical_satisfaction_synced_at' => now(),
        'unsuccessful_visits_count' => 2,
    ]);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.reset-processing', $lotAppointment))
        ->assertOk()
        ->assertJsonPath('appointment.status', LotAppointment::STATUS_NOT_PLACED)
        ->assertJsonPath('appointment.appointment_id', null)
        ->assertJsonPath('appointment.is_placed', false)
        ->assertJsonPath('appointment.physical_satisfaction', null)
        ->assertJsonPath('appointment.unsuccessful_visits_count', 2)
        ->assertJsonPath('appointment.can_update_visits', true)
        ->assertJsonPath('appointment.can_reset_processing', false)
        ->assertJsonPath('reload_required', true);

    $lotAppointment->refresh();
    $lot->refresh();

    expect($lotAppointment->status)->toBe(LotAppointment::STATUS_NOT_PLACED)
        ->and($lotAppointment->appointment_id)->toBeNull()
        ->and($lotAppointment->processing_mode)->toBeNull()
        ->and($lotAppointment->physical_satisfaction)->toBeNull()
        ->and($lotAppointment->physical_satisfaction_synced_at)->toBeNull()
        ->and($lotAppointment->unsuccessful_visits_count)->toBe(2)
        ->and($lot->status)->toBe(Lot::STATUS_IN_PROGRESS);

    $this->assertSoftDeleted('appointments', [
        'id' => $calendarAppointment->id,
    ]);
});

it('updates unsuccessful visits on a physical lot appointment without local calendar appointment', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot physique sans RDV local',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_IN_PROGRESS,
        'created_by' => $manager->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client terrain',
        'customer_phone' => '0600000077',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_PENDING,
        'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
        'unsuccessful_visits_count' => 1,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('data-lot-appointment-portes="'.$lotAppointment->id.'"', false)
        ->assertSee('"can_update_visits":true', false);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.visits.update', $lotAppointment), [
            'unsuccessful_visits_count' => 3,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.can_update_visits', true)
        ->assertJsonPath('appointment.unsuccessful_visits_count', 3);

    expect($lotAppointment->refresh()->unsuccessful_visits_count)->toBe(3);
});

it('updates unsuccessful visits on a not placed lot appointment', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot physique non placé',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_IN_PROGRESS,
        'created_by' => $manager->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client non placé',
        'customer_phone' => '0600000066',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'department_code' => '69',
        'status' => LotAppointment::STATUS_NOT_PLACED,
        'unsuccessful_visits_count' => 0,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots.show', $lot))
        ->assertOk()
        ->assertSee('data-lot-appointment-portes="'.$lotAppointment->id.'"', false)
        ->assertSee('"can_update_visits":true', false);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.appointments.visits.update', $lotAppointment), [
            'unsuccessful_visits_count' => 4,
        ])
        ->assertOk()
        ->assertJsonPath('appointment.status', LotAppointment::STATUS_NOT_PLACED)
        ->assertJsonPath('appointment.can_update_visits', true)
        ->assertJsonPath('appointment.can_reset_processing', false)
        ->assertJsonPath('appointment.unsuccessful_visits_count', 4);

    expect($lotAppointment->refresh()->unsuccessful_visits_count)->toBe(4);
});

it('renders lot type select in the import form', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    ExternalDelegataire::query()->create([
        'source' => 'coffrac',
        'external_id' => 'sync-7',
        'name' => 'Délégataire synchronisé',
        'email' => 'delegataire-sync@example.test',
        'is_active' => true,
    ]);
    Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots'))
        ->assertOk()
        ->assertSee('Type de lot')
        ->assertSee('Prestation')
        ->assertSee('Audit qualité site client')
        ->assertSee('Délégataire')
        ->assertSee('Délégataire synchronisé')
        ->assertSee('name="delegataire_id"', false)
        ->assertSee('Statut du lot')
        ->assertSee("% d'échantillonnage", false)
        ->assertSee('Échantillonnage contrôle contact')
        ->assertSee('100% contrôle')
        ->assertSee('Lots en cours')
        ->assertSee('Lots à facturer')
        ->assertSee('Lots archivés')
        ->assertDontSee('RDV placés')
        ->assertDontSee('RDV total')
        ->assertDontSee('Statut service');
});

it('starts a manager lot import preview through the upload endpoint', function () {
    Storage::fake('local');
    Queue::fake();

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $delegataire = ExternalDelegataire::query()->create([
        'source' => 'coffrac',
        'external_id' => '7',
        'name' => 'Délégataire A',
        'is_active' => true,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($manager)
        ->post(route('manager.lots.imports.store'), [
            'name' => 'Lot importe',
            'delegataire_id' => $delegataire->id,
            'received_at' => '2026-08-04',
            'comment' => 'Commentaire conservé pendant la preview.',
            'type' => Lot::TYPE_FULL_CONTROL,
            'service_id' => $service->id,
            'file' => UploadedFile::fake()->create('lot.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ], ['Accept' => 'application/json'])
        ->assertAccepted()
        ->assertJsonPath('status', LotImportPreview::STATUS_PENDING)
        ->assertJsonPath('stage', 'Import en attente dans la file.')
        ->assertJsonStructure(['uuid', 'status_url', 'confirm_url', 'stage']);

    Queue::assertPushed(ProcessLotImportPreviewJob::class);
    $this->assertDatabaseHas('lot_import_previews', [
        'name' => 'Lot importe',
        'delegataire' => 'Délégataire A',
        'comment' => 'Commentaire conservé pendant la preview.',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'stage' => 'Import en attente dans la file.',
        'created_by' => $manager->id,
    ]);
    expect(LotImportPreview::query()->where('name', 'Lot importe')->first()?->received_at?->format('Y-m-d'))->toBe('2026-08-04');
});

it('retries a failed manager lot import preview from the stored original file', function () {
    Storage::fake('local');
    Queue::fake();

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $path = 'lot-import-previews/retry.xlsx';
    Storage::disk('local')->put($path, 'spreadsheet-content');
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_FAILED,
        'progress' => 100,
        'stage' => 'Erreur pendant: Normalisation OpenAI en cours.',
        'name' => 'Lot à relancer',
        'type' => Lot::TYPE_FULL_CONTROL,
        'original_filename' => 'retry.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => $path,
        'payload' => ['appointments' => []],
        'error_message' => 'OpenAI timeout',
        'created_by' => $manager->id,
        'completed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.lots.imports.retry', $preview))
        ->assertAccepted()
        ->assertJsonPath('status', LotImportPreview::STATUS_PENDING)
        ->assertJsonPath('progress', 0)
        ->assertJsonPath('stage', 'Import relancé et en attente dans la file.')
        ->assertJsonPath('error_message', null)
        ->assertJsonStructure(['retry_url', 'status_url', 'confirm_url']);

    $preview->refresh();

    expect($preview->status)->toBe(LotImportPreview::STATUS_PENDING)
        ->and($preview->payload)->toBeNull()
        ->and($preview->error_message)->toBeNull()
        ->and($preview->completed_at)->toBeNull();

    Queue::assertPushed(ProcessLotImportPreviewJob::class);
});

it('exposes an active lot import preview on the lots page', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_PROCESSING,
        'progress' => 30,
        'stage' => 'Normalisation OpenAI en cours.',
        'name' => 'Lot actif',
        'type' => Lot::TYPE_FULL_CONTROL,
        'original_filename' => 'active.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/active.xlsx',
        'created_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots'))
        ->assertOk()
        ->assertSee($preview->uuid)
        ->assertSee('Normalisation OpenAI en cours.')
        ->assertSee('resumeLotImportIfNeeded');
});

it('serializes completed import preview appointments as a list', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_COMPLETED,
        'progress' => 100,
        'stage' => 'Preview prêt: vérifie les lignes avant création du lot.',
        'name' => 'Lot preview',
        'type' => Lot::TYPE_FULL_CONTROL,
        'delegataire' => 'Délégataire Preview',
        'original_filename' => 'preview.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/preview.xlsx',
        'total_rows' => 2,
        'normalized_rows' => 2,
        'rejected_rows' => 0,
        'created_by' => $manager->id,
        'payload' => [
            'summary' => 'Preview OK',
            'rejected_rows' => [],
            'appointments' => [
                2 => [
                    'row_number' => 2,
                    'customer_name' => 'Camille Martin',
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordiere, 69002 Lyon',
                ],
                5 => [
                    'row_number' => 5,
                    'customer_name' => 'Julien Bernard',
                    'customer_phone' => '0611223344',
                    'address' => '12 Cours de l Intendance, 33000 Bordeaux',
                ],
            ],
        ],
    ]);

    $this->actingAs($manager)
        ->getJson(route('manager.lots.imports.show', $preview))
        ->assertOk()
        ->assertJsonPath('normalized_rows', 2)
        ->assertJsonCount(2, 'appointments')
        ->assertJsonPath('appointments.0.customer_name', 'Camille Martin')
        ->assertJsonPath('appointments.1.customer_name', 'Julien Bernard');
});

it('cleans cadastral références from imported addresses', function () {
    expect(app(ImportedAddressCleaner::class)->clean('1 LES PETITES GRANGES - 000 0E 0369 - 000 0E 0370 - 000 0Z 0172'))
        ->toBe('1 LES PETITES GRANGES');
});

it('updates one import preview row and geocodes only that row', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_COMPLETED,
        'progress' => 100,
        'stage' => 'Preview prêt: vérifie les lignes avant création du lot.',
        'name' => 'Lot preview',
        'delegataire' => 'Délégataire Preview',
        'type' => Lot::TYPE_FULL_CONTROL,
        'original_filename' => 'preview.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/preview.xlsx',
        'total_rows' => 2,
        'normalized_rows' => 2,
        'rejected_rows' => 0,
        'created_by' => $manager->id,
        'payload' => [
            'summary' => 'Preview OK',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'customer_name' => 'Client à corriger',
                    'customer_phone' => null,
                    'address' => 'Ancienne adresse',
                    'postal_code' => null,
                    'city' => null,
                    'department_code' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'warnings' => [],
                ],
                [
                    'row_number' => 3,
                    'customer_name' => 'Julien Bernard',
                    'customer_phone' => '0611223344',
                    'address' => '12 Cours de l Intendance',
                    'postal_code' => '33000',
                    'city' => 'Bordeaux',
                    'department_code' => '33',
                    'latitude' => 44.842,
                    'longitude' => -0.575,
                    'warnings' => [],
                ],
            ],
        ],
    ]);

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->once()
        ->with('1 LES PETITES GRANGES 01000 Bourg-en-Bresse')
        ->andReturn([
            'latitude' => 46.204391,
            'longitude' => 5.22512,
            'formatted_address' => '1 Les Petites Granges, 01000 Bourg-en-Bresse, France',
            'mapbox_id' => 'address.123',
            'mapbox_confidence' => 0.91,
            'warnings' => [],
        ]);
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    $this->actingAs($manager)
        ->patchJson(route('manager.lots.imports.rows.update', [$preview, 2]), [
            'customer_name' => 'Camille Martin',
            'customer_phone' => '0612345678',
            'address' => '1 LES PETITES GRANGES - 000 0E 0369 - 000 0E 0370 - 000 0Z 0172',
            'postal_code' => '01000',
            'city' => 'Bourg-en-Bresse',
            'comment' => 'Corrige depuis la preview.',
        ])
        ->assertOk()
        ->assertJsonPath('appointments.0.customer_name', 'Camille Martin')
        ->assertJsonPath('appointments.0.address', '1 LES PETITES GRANGES')
        ->assertJsonPath('appointments.0.latitude', 46.204391)
        ->assertJsonPath('appointments.0.longitude', 5.22512)
        ->assertJsonPath('appointments.1.customer_name', 'Julien Bernard');

    $preview->refresh();
    $appointments = collect($preview->payload['appointments']);

    expect($appointments[0]['address'])->toBe('1 LES PETITES GRANGES')
        ->and($appointments[0]['department_code'])->toBe('01')
        ->and($appointments[0]['edited_manually'])->toBeTrue()
        ->and($appointments[1]['latitude'])->toBe(44.842);
});

it('marks an import preview as failed without rethrowing job exceptions', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_PROCESSING,
        'progress' => 30,
        'stage' => 'Normalisation OpenAI en cours.',
        'name' => 'Lot timeout',
        'type' => Lot::TYPE_FULL_CONTROL,
        'original_filename' => 'timeout.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/timeout.xlsx',
        'created_by' => $manager->id,
    ]);

    $processor = \Mockery::mock(LotImportPreviewProcessor::class);
    $processor->shouldReceive('process')
        ->once()
        ->with(\Mockery::on(fn (LotImportPreview $handledPreview): bool => $handledPreview->is($preview)))
        ->andThrow(new \RuntimeException('OpenAI timeout'));

    (new ProcessLotImportPreviewJob($preview->id))->handle($processor);

    $preview->refresh();

    expect($preview->status)->toBe(LotImportPreview::STATUS_FAILED)
        ->and($preview->progress)->toBe(100)
        ->and($preview->stage)->toBe('Erreur pendant: Normalisation OpenAI en cours.')
        ->and($preview->error_message)->toBe('OpenAI timeout');
});

it('returns a user friendly error when OpenAI times out', function () {
    config([
        'services.openai.api_key' => 'test-key',
        'services.openai.timeout' => 12,
        'services.openai.connect_timeout' => 3,
    ]);

    Http::fake(fn () => throw new ConnectionException('timeout'));

    $normalizer = new LotAppointmentAiNormalizer();

    expect(fn () => $normalizer->normalize(collect([
        [
            'row_number' => 2,
            'data' => ['client' => 'Camille Martin'],
        ],
    ])))->toThrow(\RuntimeException::class, 'OpenAI ne répond pas dans le délai imparti (12 s).');
});

it('normalizes lot imports with multiple OpenAI chunks', function () {
    config([
        'services.openai.api_key' => 'test-key',
        'services.openai.model' => 'gpt-test',
        'services.openai.import_chunk_size' => 2,
    ]);

    $responses = [
        [
            'lot_name' => 'Chunk 1',
            'summary' => 'Deux lignes normalisées.',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'external_reference' => null,
                    'customer_name' => 'Camille Martin',
                    'customer_first_name' => 'Camille',
                    'customer_last_name' => 'Martin',
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordiere, 69002 Lyon',
                    'address_line' => '20 Rue Bellecordiere',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'raw_address_parts' => ['20 Rue Bellecordiere', '69002', 'Lyon'],
                    'department_code' => '69',
                    'latitude' => null,
                    'longitude' => null,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
                [
                    'row_number' => 3,
                    'external_reference' => null,
                    'customer_name' => 'Julien Bernard',
                    'customer_first_name' => 'Julien',
                    'customer_last_name' => 'Bernard',
                    'customer_phone' => '0611223344',
                    'address' => '12 Cours de l Intendance, 33000 Bordeaux',
                    'address_line' => '12 Cours de l Intendance',
                    'postal_code' => '33000',
                    'city' => 'Bordeaux',
                    'raw_address_parts' => ['12 Cours de l Intendance', '33000', 'Bordeaux'],
                    'department_code' => '33',
                    'latitude' => null,
                    'longitude' => null,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ],
        [
            'lot_name' => 'Chunk 2',
            'summary' => 'Une ligne normalisee.',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 4,
                    'external_reference' => null,
                    'customer_name' => 'Sarah Petit',
                    'customer_first_name' => 'Sarah',
                    'customer_last_name' => 'Petit',
                    'customer_phone' => '0699887766',
                    'address' => '1 Place Bellecour, 69002 Lyon',
                    'address_line' => '1 Place Bellecour',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'raw_address_parts' => ['1 Place Bellecour', '69002', 'Lyon'],
                    'department_code' => '69',
                    'latitude' => null,
                    'longitude' => null,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ],
    ];
    $callCount = 0;

    Http::fake(function () use (&$callCount, $responses) {
        return Http::response([
            'output_text' => json_encode($responses[$callCount++], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    });

    $payload = (new LotAppointmentAiNormalizer())->normalize(collect([
        ['row_number' => 2, 'data' => ['client' => 'Camille Martin']],
        ['row_number' => 3, 'data' => ['client' => 'Julien Bernard']],
        ['row_number' => 4, 'data' => ['client' => 'Sarah Petit']],
    ]), 'Lot chunk', Lot::TYPE_FULL_CONTROL);

    expect($payload['lot_name'])->toBe('Lot chunk')
        ->and($payload['appointments'])->toHaveCount(3)
        ->and($payload['rejected_rows'])->toHaveCount(0)
        ->and($callCount)->toBe(2);
});

it('keeps database queue retry_after aligned with long import workers', function () {
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan(1500);
});

it('requires sampling percentage for sampling lot types', function () {
    Storage::fake('local');
    Queue::fake();

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);

    $this->actingAs($manager)
        ->post(route('manager.lots.imports.store'), [
            'name' => 'Lot échantillon',
            'delegataire' => 'Délégataire A',
            'received_at' => '2026-08-04',
            'type' => Lot::TYPE_SAMPLE_CONTROL,
            'service_id' => $service->id,
            'file' => UploadedFile::fake()->create('lot.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sampling_percentage');
});

it('stores the original spreadsheet when importing a lot', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);

    $file = UploadedFile::fake()->createWithContent('lot-client.csv', "client;adresse\nCamille Martin;20 Rue Bellecordiere, Lyon");
    $rows = collect([
        [
            'row_number' => 2,
            'data' => [
                'client' => 'Camille Martin',
                'adresse' => '20 Rue Bellecordiere, Lyon',
            ],
        ],
    ]);

    $extractor = \Mockery::mock(LotSpreadsheetExtractor::class);
    $extractor->shouldReceive('extract')
        ->once()
        ->with($file)
        ->andReturn($rows);

    $normalizer = \Mockery::mock(LotAppointmentAiNormalizer::class);
    $normalizer->shouldReceive('normalize')
        ->once()
        ->with($rows, 'Lot client', Lot::TYPE_FULL_CONTACT_CONTROL)
        ->andReturn([
            'lot_name' => 'Lot client',
            'summary' => 'Import de test',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'external_reference' => 'EXT-1',
                    'customer_name' => 'Camille Martin',
                    'customer_first_name' => 'Camille',
                    'customer_last_name' => 'Martin',
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordiere',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_type' => Service::TYPE_AUDIT,
                    'service_name' => 'Audit qualité site client',
                    'duration_minutes' => 120,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ]);

    $lot = (new LotExcelImportService($extractor, $normalizer, new LotBusinessIdentityResolver()))
        ->import(
            file: $file,
            userId: $manager->id,
            requestedLotName: 'Lot client',
            lotType: Lot::TYPE_FULL_CONTACT_CONTROL,
            serviceId: $service->id,
            comment: 'Consignes import direct',
        );

    expect($lot->original_filename)->toBe('lot-client.csv')
        ->and($lot->type)->toBe(Lot::TYPE_FULL_CONTACT_CONTROL)
        ->and($lot->service_id)->toBe($service->id)
        ->and($lot->comment)->toBe('Consignes import direct')
        ->and($lot->original_file_disk)->toBe('local')
        ->and($lot->original_file_path)->not->toBeNull()
        ->and($lot->appointments)->toHaveCount(1);

    $appointment = $lot->appointments->first();

    expect($appointment->service_id)->toBe($service->id)
        ->and($appointment->service_type)->toBe(Service::TYPE_AUDIT)
        ->and($appointment->service_name)->toBe('Audit qualité site client')
        ->and($appointment->duration_minutes)->toBe(120)
        ->and($appointment->status)->toBe(LotAppointment::STATUS_PENDING);

    Storage::disk('local')->assertExists($lot->original_file_path);
});

it('keeps beneficiary company and installer separated from Coffrac raw columns', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'lot-coffrac.csv',
        implode(';', [
            'RAISON SOCIALE du professionnel',
            "RAISON SOCIALE du bénéficiaire de l'opération",
            'Téléphone',
            'Adresse',
            'Code postal',
            'Ville',
        ])."\n".implode(';', [
            'Installateur Travaux SAS',
            'Bénéficiaire Industrie SA',
            '0612345678',
            '20 Rue Bellecordière',
            '69002',
            'Lyon',
        ]),
    );
    $extractor = new LotSpreadsheetExtractor();
    $rows = $extractor->extract($file);
    $normalizer = \Mockery::mock(LotAppointmentAiNormalizer::class);
    $normalizer
        ->shouldReceive('normalize')
        ->once()
        ->with(
            \Mockery::on(fn ($value): bool => $value instanceof \Illuminate\Support\Collection
                && $value->toArray() === $rows->toArray()),
            'Lot Coffrac',
            Lot::TYPE_FULL_CONTROL,
        )
        ->andReturn([
            'lot_name' => 'Lot Coffrac',
            'summary' => 'Import de test',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'external_reference' => 'EXT-COFFRAC',
                    'customer_name' => 'Installateur Travaux SAS',
                    'company_name' => 'Installateur Travaux SAS',
                    'site_name' => null,
                    'installer_name' => null,
                    'customer_first_name' => null,
                    'customer_last_name' => null,
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordière',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ]);

    $lot = (new LotExcelImportService($extractor, $normalizer, new LotBusinessIdentityResolver()))
        ->import(
            file: $file,
            userId: $manager->id,
            requestedLotName: 'Lot Coffrac',
            lotType: Lot::TYPE_FULL_CONTROL,
            serviceId: $service->id,
        );

    $appointment = $lot->appointments->first();

    expect($appointment->customer_name)->toBe('Bénéficiaire Industrie SA')
        ->and($appointment->company_name)->toBe('Bénéficiaire Industrie SA')
        ->and($appointment->installer_name)->toBe('Installateur Travaux SAS')
        ->and($appointment->raw_payload['RAISON SOCIALE du professionnel'])->toBe('Installateur Travaux SAS')
        ->and($appointment->raw_payload["RAISON SOCIALE du bénéficiaire de l'opération"])->toBe('Bénéficiaire Industrie SA');
});

it('resolves Coffrac beneficiary and installer even with noisy headers and row number fallback', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'lot-coffrac-noisy.csv',
        implode("\n", [
            implode(';', [
                'SIRET du professionnel',
                'Email du professionnel',
                'RAISON SOCIALE du professionnel ayant réalisé l’opération',
                'RAISON SOCIALE du bénéficiaire de l’opération',
                'Téléphone',
                'Adresse',
                'Code postal',
                'Ville',
            ]),
            implode(';', [
                '11111111100011',
                'premier@installateur.test',
                'Installateur Premier SAS',
                'Bénéficiaire Premier SA',
                '0600000001',
                '1 Rue Première',
                '75001',
                'Paris',
            ]),
            implode(';', [
                '12345678900011',
                'contact@installateur.test',
                'Installateur Noisy SAS',
                'Bénéficiaire Noisy SA',
                '0612345678',
                '20 Rue Bellecordière',
                '69002',
                'Lyon',
            ]),
        ]),
    );
    $extractor = new LotSpreadsheetExtractor();
    $rows = $extractor->extract($file);
    $normalizer = \Mockery::mock(LotAppointmentAiNormalizer::class);
    $normalizer
        ->shouldReceive('normalize')
        ->once()
        ->andReturn([
            'lot_name' => 'Lot Coffrac noisy',
            'summary' => 'Import de test',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'external_reference' => 'EXT-FIRST',
                    'customer_name' => 'Installateur Premier SAS',
                    'company_name' => 'Installateur Premier SAS',
                    'site_name' => null,
                    'installer_name' => null,
                    'customer_first_name' => null,
                    'customer_last_name' => null,
                    'customer_phone' => '0600000001',
                    'address' => '1 Rue Première',
                    'postal_code' => '75001',
                    'city' => 'Paris',
                    'department_code' => '75',
                    'latitude' => 48.8647,
                    'longitude' => 2.334,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
                [
                    'row_number' => 2,
                    'external_reference' => 'EXT-NOISY',
                    'customer_name' => 'Installateur Noisy SAS',
                    'company_name' => 'Installateur Noisy SAS',
                    'site_name' => null,
                    'installer_name' => null,
                    'customer_first_name' => null,
                    'customer_last_name' => null,
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordière',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ]);

    $lot = (new LotExcelImportService($extractor, $normalizer, new LotBusinessIdentityResolver()))
        ->import(
            file: $file,
            userId: $manager->id,
            requestedLotName: 'Lot Coffrac noisy',
            lotType: Lot::TYPE_FULL_CONTROL,
            serviceId: $service->id,
        );

    $appointment = $lot->appointments->firstWhere('external_reference', 'EXT-NOISY');

    expect($appointment->customer_name)->toBe('Bénéficiaire Noisy SA')
        ->and($appointment->company_name)->toBe('Bénéficiaire Noisy SA')
        ->and($appointment->installer_name)->toBe('Installateur Noisy SAS')
        ->and($appointment->row_number)->toBe(3)
        ->and($appointment->installer_name)->not->toBe('12345678900011')
        ->and($appointment->installer_name)->not->toBe('contact@installateur.test');
});

it('keeps Coffrac business identity columns consistent from a multi row header file', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'lot-coffrac-header-lines.csv',
        implode("\n", [
            implode(';', [
                'Données remplies par le demandeur',
                'colonne_2',
                'colonne_3',
                'colonne_4',
                'colonne_5',
                'colonne_6',
                'colonne_7',
            ]),
            implode(';', [
                'RAISON SOCIALE du demandeur',
                'RAISON SOCIALE du professionnel',
                "RAISON SOCIALE du bénéficiaire de l'opération",
                'Téléphone',
                'Adresse',
                'Code postal',
                'Ville',
            ]),
            implode(';', [
                'TOTAL ALEX',
                'N.E.C.H',
                'Bénéficiaire Ligne 1',
                '0611111111',
                '1 Rue Test',
                '69001',
                'Lyon',
            ]),
            implode(';', [
                'TOTAL ALEX',
                'N.E.C.H',
                'Bénéficiaire Ligne 2',
                '0622222222',
                '2 Rue Test',
                '69002',
                'Lyon',
            ]),
        ]),
    );
    $extractor = new LotSpreadsheetExtractor();
    $rows = $extractor->extract($file);
    $normalizer = \Mockery::mock(LotAppointmentAiNormalizer::class);
    $normalizer
        ->shouldReceive('normalize')
        ->once()
        ->andReturn([
            'lot_name' => 'Lot Coffrac header lines',
            'summary' => 'Import de test',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 3,
                    'external_reference' => 'EXT-HEADER-1',
                    'customer_name' => 'TOTAL ALEX',
                    'company_name' => 'TOTAL ALEX',
                    'site_name' => null,
                    'installer_name' => 'N.E.C.H',
                    'customer_first_name' => null,
                    'customer_last_name' => null,
                    'customer_phone' => '0611111111',
                    'address' => '1 Rue Test',
                    'postal_code' => '69001',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.76,
                    'longitude' => 4.84,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
                [
                    'row_number' => 4,
                    'external_reference' => 'EXT-HEADER-2',
                    'customer_name' => 'N.E.C.H',
                    'company_name' => 'N.E.C.H',
                    'site_name' => null,
                    'installer_name' => 'TOTAL ALEX',
                    'customer_first_name' => null,
                    'customer_last_name' => null,
                    'customer_phone' => '0622222222',
                    'address' => '2 Rue Test',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.75,
                    'longitude' => 4.83,
                    'comment' => null,
                    'confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ]);

    $lot = (new LotExcelImportService($extractor, $normalizer, new LotBusinessIdentityResolver()))
        ->import(
            file: $file,
            userId: $manager->id,
            requestedLotName: 'Lot Coffrac header lines',
            lotType: Lot::TYPE_FULL_CONTROL,
            serviceId: $service->id,
        );

    $firstAppointment = $lot->appointments->firstWhere('external_reference', 'EXT-HEADER-1');
    $secondAppointment = $lot->appointments->firstWhere('external_reference', 'EXT-HEADER-2');

    expect($firstAppointment->company_name)->toBe('Bénéficiaire Ligne 1')
        ->and($firstAppointment->customer_name)->toBe('Bénéficiaire Ligne 1')
        ->and($firstAppointment->installer_name)->toBe('N.E.C.H')
        ->and($secondAppointment->company_name)->toBe('Bénéficiaire Ligne 2')
        ->and($secondAppointment->customer_name)->toBe('Bénéficiaire Ligne 2')
        ->and($secondAppointment->installer_name)->toBe('N.E.C.H')
        ->and($secondAppointment->company_name)->not->toBe('TOTAL ALEX')
        ->and($secondAppointment->installer_name)->not->toBe('TOTAL ALEX');
});

it('confirms selected preview rows and creates a lot', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_COMPLETED,
        'progress' => 100,
        'name' => 'Lot preview',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'received_at' => '2026-08-01',
        'comment' => 'Commentaire du lot preview',
        'original_filename' => 'preview.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/preview.xlsx',
        'total_rows' => 2,
        'normalized_rows' => 2,
        'rejected_rows' => 0,
        'created_by' => $manager->id,
        'payload' => [
            'summary' => 'Preview OK',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'customer_name' => 'Camille Martin',
                    'customer_first_name' => 'Camille',
                    'customer_last_name' => 'Martin',
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordiere',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_type' => Service::TYPE_AUDIT,
                    'service_name' => 'Audit qualité site client',
                    'duration_minutes' => 120,
                    'ai_confidence' => 0.95,
                    'warnings' => [],
                ],
                [
                    'row_number' => 3,
                    'customer_name' => 'Julien Bernard',
                    'address' => '12 Cours de l Intendance, 33000 Bordeaux',
                    'department_code' => '33',
                    'latitude' => 44.842,
                    'longitude' => -0.575,
                    'ai_confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ],
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.lots.imports.confirm', $preview), [
            'selected_rows' => [2],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Lot "Lot preview" créé avec 1 RDV.');

    $this->assertDatabaseHas('lots', [
        'name' => 'Lot preview',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'imported_rows' => 1,
        'comment' => 'Commentaire du lot preview',
    ]);
    expect(Lot::query()->where('name', 'Lot preview')->first()?->received_at?->format('Y-m-d'))->toBe('2026-08-01');
    $this->assertDatabaseHas('lot_appointments', [
        'customer_name' => 'Camille Martin',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'service_id' => $service->id,
        'service_type' => Service::TYPE_AUDIT,
        'service_name' => 'Audit qualité site client',
        'duration_minutes' => 120,
        'status' => LotAppointment::STATUS_PENDING,
    ]);
    $this->assertDatabaseMissing('lot_appointments', [
        'customer_name' => 'Julien Bernard',
    ]);
});

it('blocks selected import preview rows that still have warnings', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit qualité site client',
        'average_duration_minutes' => 120,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_COMPLETED,
        'progress' => 100,
        'name' => 'Lot avec warnings',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'original_filename' => 'preview.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/preview.xlsx',
        'total_rows' => 2,
        'normalized_rows' => 2,
        'rejected_rows' => 0,
        'created_by' => $manager->id,
        'payload' => [
            'summary' => 'Preview avec correction attendue',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'customer_name' => 'Client à corriger',
                    'address' => 'Adresse incomplète',
                    'ai_confidence' => 0.4,
                    'warnings' => ['Adresse non géocodée'],
                ],
                [
                    'row_number' => 3,
                    'customer_name' => 'Client prêt',
                    'address' => '20 Rue Bellecordière',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'ai_confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ],
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.lots.imports.confirm', $preview), [
            'selected_rows' => [2],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Corrige ou décoche les lignes avec warning avant validation : 2.');

    $this->assertDatabaseMissing('lots', [
        'name' => 'Lot avec warnings',
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.lots.imports.confirm', $preview), [
            'selected_rows' => [3],
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Lot "Lot avec warnings" créé avec 1 RDV.');

    $this->assertDatabaseHas('lot_appointments', [
        'customer_name' => 'Client prêt',
        'status' => LotAppointment::STATUS_PENDING,
    ]);
    $this->assertDatabaseMissing('lot_appointments', [
        'customer_name' => 'Client à corriger',
    ]);
});

it('keeps company and site names when confirming a business lot preview', function () {
    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $preview = LotImportPreview::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'status' => LotImportPreview::STATUS_COMPLETED,
        'progress' => 100,
        'name' => 'Lot entreprises',
        'type' => Lot::TYPE_FULL_CONTROL,
        'delegataire' => 'Délégataire Entreprise',
        'original_filename' => 'preview.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => 'lot-import-previews/preview.xlsx',
        'total_rows' => 1,
        'normalized_rows' => 1,
        'rejected_rows' => 0,
        'created_by' => $manager->id,
        'payload' => [
            'summary' => 'Preview OK',
            'rejected_rows' => [],
            'appointments' => [
                [
                    'row_number' => 2,
                    'customer_name' => 'Ancien libellé',
                    'company_name' => 'ACME Industrie',
                    'site_name' => 'Site de Lyon',
                    'installer_name' => 'Installateur Rhône',
                    'customer_phone' => '0612345678',
                    'address' => '20 Rue Bellecordière',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'ai_confidence' => 0.95,
                    'warnings' => [],
                ],
            ],
        ],
    ]);

    $this->actingAs($manager)
        ->postJson(route('manager.lots.imports.confirm', $preview), [
            'selected_rows' => [2],
        ])
        ->assertOk();

    $this->assertDatabaseHas('lot_appointments', [
        'customer_name' => 'ACME Industrie',
        'company_name' => 'ACME Industrie',
        'site_name' => 'Site de Lyon',
        'installer_name' => 'Installateur Rhône',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'status' => LotAppointment::STATUS_PENDING,
    ]);
});

it('downloads the original imported lot file', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $path = 'lot-imports/2026/06/import.xlsx';
    Storage::disk('local')->put($path, 'spreadsheet-content');

    $lot = Lot::query()->create([
        'name' => 'Lot fichier client',
        'type' => Lot::TYPE_SAMPLE_CONTACT_CONTROL,
        'source' => 'Export client',
        'original_filename' => 'rdv-client.xlsx',
        'original_file_disk' => 'local',
        'original_file_path' => $path,
        'original_file_size' => strlen('spreadsheet-content'),
        'created_by' => $manager->id,
        'imported_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('manager.lots.download', $lot))
        ->assertOk()
        ->assertDownload('rdv-client.xlsx');
});
