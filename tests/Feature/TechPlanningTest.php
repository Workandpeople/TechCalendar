<?php

use App\Models\Appointment;
use App\Models\ExternalAppointmentRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('refreshes Coffrac documents from the technician planning detail', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments*' => Http::response([
            'result' => true,
            'data' => [[
                'id' => 'tech-doc-42',
                'source' => 'Coffrac',
                'status_name' => 'RDV attente visite',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'Inspection Coffrac',
                'customer_first_name' => 'Client',
                'customer_last_name' => 'Documents',
                'phone' => '0600000014',
                'address' => '5 Rue Nationale, 69001 Lyon, France',
                'department_code' => '69',
                'latitude' => 45.767,
                'longitude' => 4.833,
                'technician_email' => 'tech-docs@example.test',
                'starts_at' => '2026-06-18T10:00:00+02:00',
                'duration_minutes' => 90,
                'documents' => [[
                    'id' => 19,
                    'scope' => 'dossier',
                    'name' => 'Document actualisé Coffrac',
                    'url' => 'https://coffrac.test/documents/tech-doc-42.pdf',
                ]],
            ]],
        ]),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech-docs@example.test',
    ]);
    $otherTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);

    $startsAt = Carbon::parse('2026-06-18 10:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Documents',
        'customer_phone' => '0600000014',
        'address' => '5 Rue Nationale, 69001 Lyon',
        'latitude' => 45.767,
        'longitude' => 4.833,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
        'external_source' => CoffracAppointmentService::SOURCE,
        'external_reference' => 'tech-doc-42',
        'external_payload' => ['id' => 'tech-doc-42', 'documents' => [['name' => 'Ancien document']]],
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_reference' => 'tech-doc-42',
        'status' => ExternalAppointmentRequest::STATUS_PLACED,
        'appointment_id' => $appointment->id,
        'documents' => [['name' => 'Ancien document']],
    ]);

    $this->actingAs($otherTechnician)
        ->postJson(route('tech.planning.appointments.coffrac.refresh', $appointment))
        ->assertNotFound();

    $this->actingAs($technician)
        ->postJson(route('tech.planning.appointments.coffrac.refresh', $appointment))
        ->assertOk()
        ->assertJsonPath('documents.0.name', 'Document actualisé Coffrac')
        ->assertJsonPath('documents.0.url', 'https://coffrac.test/documents/tech-doc-42.pdf');

    expect($appointment->refresh()->external_payload['documents'][0]['name'])->toBe('Document actualisé Coffrac');
});
