<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Mail\TechnicianAppointmentNotificationMail;
use App\Services\CoffracAppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('does not fallback to simulated appointments on the planning dashboard', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.dashboard'))
        ->assertOk()
        ->assertSee('RDV à placer')
        ->assertSee('0 demande(s)')
        ->assertDontSee('crm-audit-lyon-001');
});

it('uses coffrac appointment requests on the planning dashboard when configured', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [[
            'id' => 44,
            'source' => 'Coffrac',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => null,
            'customer_first_name' => 'Claire',
            'customer_last_name' => 'COFFRAC',
            'phone' => '0600000044',
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'department_code' => '69',
            'latitude' => 45.7578,
            'longitude' => 4.832,
        ]],
    ]));
    app(CoffracAppointmentService::class)->sync();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.dashboard'))
        ->assertOk()
        ->assertSee('RDV à placer')
        ->assertSee('1 demande(s)')
        ->assertSee('COFFRAC Claire')
        ->assertSee(route('planner.book', ['crm_appointment_id' => 'coffrac-44']), false);

    Http::assertSentCount(1);
    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://coffrac.test/api/techcalendar/appointments')
        && str_contains($request->url(), 'status=all')
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('exposes the initial coffrac appointment id on the booking page', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [[
            'id' => 44,
            'source' => 'Coffrac',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => null,
            'customer_first_name' => 'Claire',
            'customer_last_name' => 'COFFRAC',
            'phone' => '0600000044',
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'department_code' => '69',
            'latitude' => 45.7578,
            'longitude' => 4.832,
        ]],
    ]));
    app(CoffracAppointmentService::class)->sync();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.book', ['crm_appointment_id' => 'coffrac-44']))
        ->assertOk()
        ->assertSee('RDV externes à placer')
        ->assertSee('API Coffrac disponible')
        ->assertSee('booking-crm-refresh')
        ->assertSee('Actualiser connecteur 2')
        ->assertSee('Connecteur 3 à connecter')
        ->assertSee('const bookingCrmPageSize = 10;', false)
        ->assertSee('window.requestAnimationFrame(scrollToBookingResults);', false)
        ->assertSee('const bookingInitialCrmAppointmentId = "coffrac-44";', false);
});

it('refreshes coffrac appointment requests on the booking page', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [[
            'id' => 44,
            'source' => 'Coffrac',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => null,
            'customer_first_name' => 'Claire',
            'customer_last_name' => 'COFFRAC',
            'phone' => '0600000044',
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'department_code' => '69',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'documents' => [[
                'id' => 9,
                'scope' => 'dossier',
                'name' => 'Avis de passage',
                'url' => 'https://coffrac.test/documents/avis.pdf',
            ]],
        ]],
    ]));

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.refresh'))
        ->assertOk()
        ->assertJsonCount(1, 'appointments')
        ->assertJsonPath('appointments.0.id', 'coffrac-44')
        ->assertJsonPath('appointments.0.documents.0.name', 'Avis de passage')
        ->assertJsonPath('coffrac_api_status.state', 'available')
        ->assertJsonPath('coffrac_api_status.label', 'API Coffrac disponible')
        ->assertJsonPath('external_sources.0.key', 'coffrac')
        ->assertJsonPath('external_sources.1.enabled', false)
        ->assertJsonPath('external_sources.1.status.label', 'Connecteur 2 à connecter')
        ->assertJsonPath('external_sources.2.status.label', 'Connecteur 3 à connecter')
        ->assertJsonStructure([
            'appointments' => [[
                'id',
                'source',
                'first_name',
                'last_name',
                'phone',
                'address',
                'department_code',
                'latitude',
                'longitude',
                'documents',
                'service',
            ]],
        ]);

    $this->assertDatabaseHas('external_appointment_requests', [
        'source' => 'coffrac',
        'external_reference' => '44',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'customer_last_name' => 'COFFRAC',
    ]);
});

it('keeps coffrac refresh stable when the remote api returns a long error', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake(fn () => Http::response([
        'message' => str_repeat('Erreur SQL Coffrac distante ', 30),
    ], 500));

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.refresh'))
        ->assertOk()
        ->assertJsonCount(0, 'appointments')
        ->assertJsonPath('coffrac_api_status.state', 'unavailable')
        ->assertJsonPath('coffrac_api_status.label', 'API Coffrac indisponible');

    $sync = ExternalApiSync::query()->where('source', 'coffrac')->firstOrFail();

    expect($sync->state)->toBe(ExternalApiSync::STATE_UNAVAILABLE)
        ->and(mb_strlen((string) $sync->message))->toBeLessThanOrEqual(240)
        ->and($sync->message)->toContain('Erreur SQL Coffrac distante');
});

it('skips a coffrac appointment that crashes remote page serialization', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    $appointmentPayload = fn (int $id): array => [
        'id' => $id,
        'source' => 'Coffrac',
        'status_name' => 'Prise de RDV',
        'service_type' => Service::TYPE_COFFRAC,
        'service_name' => null,
        'customer_first_name' => 'Client',
        'customer_last_name' => "COFFRAC {$id}",
        'phone' => "0600000{$id}",
        'address' => "{$id} Rue de la Paix, 75002 Paris, France",
        'department_code' => '75',
        'latitude' => 48.868,
        'longitude' => 2.331,
    ];

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($appointmentPayload) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $limit = (int) ($query['limit'] ?? 0);
        $offset = (int) ($query['offset'] ?? 0);

        if (($limit === 4 && $offset === 0) || ($limit === 2 && $offset === 2) || ($limit === 1 && $offset === 2)) {
            return Http::response([
                'message' => 'Call to a member function getKey() on array',
            ], 500);
        }

        $responses = [
            '2:0' => [$appointmentPayload(101), $appointmentPayload(102)],
            '1:3' => [$appointmentPayload(104)],
            '4:4' => [],
        ];

        return Http::response([
            'result' => true,
            'data' => $responses["{$limit}:{$offset}"] ?? [],
        ]);
    });

    $result = app(CoffracAppointmentService::class)->sync(4);

    expect($result['available'])->toBeTrue()
        ->and($result['count'])->toBe(3)
        ->and($result['message'])->toContain('1 RDV ignoré');

    expect(ExternalAppointmentRequest::query()->where('source', 'coffrac')->pluck('external_reference')->all())
        ->toBe(['101', '102', '104']);
});

it('syncs pending and placed coffrac appointment requests with documents locally', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);
    User::factory()->create([
        'admin' => true,
        'role' => 0,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech.coffrac@example.test',
    ]);
    $technician->services()->attach($service);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [
            [
                'id' => 44,
                'source' => 'Coffrac',
                'status_name' => 'Prise de RDV',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => null,
                'customer_first_name' => 'Claire',
                'customer_last_name' => 'COFFRAC',
                'phone' => '0600000044',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'documents' => [[
                    'id' => 9,
                    'scope' => 'dossier',
                    'name' => 'Avis de passage',
                    'url' => 'https://coffrac.test/documents/avis.pdf',
                ]],
            ],
            [
                'id' => 45,
                'source' => 'Coffrac',
                'status_name' => 'RDV attente visite',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'Inspection Coffrac',
                'customer_first_name' => 'Nora',
                'customer_last_name' => 'PLACEE',
                'phone' => '0600000045',
                'address' => '8 Place Royale, 44000 Nantes, France',
                'department_code' => '44',
                'latitude' => 47.2142,
                'longitude' => -1.5586,
                'technician_email' => 'tech.coffrac@example.test',
                'starts_at' => '2026-06-22T10:30:00+02:00',
                'duration_minutes' => 90,
                'documents' => [[
                    'id' => 10,
                    'scope' => 'fiche',
                    'name' => 'Rapport préparatoire',
                    'url' => 'https://coffrac.test/documents/rapport.pdf',
                ]],
            ],
        ],
    ]));

    $this->artisan('coffrac:sync')
        ->assertSuccessful();

    $pending = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '44')
        ->firstOrFail();
    $placed = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '45')
        ->firstOrFail();

    expect($pending->status)->toBe(ExternalAppointmentRequest::STATUS_PENDING)
        ->and($pending->documents[0]['name'])->toBe('Avis de passage')
        ->and($placed->status)->toBe(ExternalAppointmentRequest::STATUS_PLACED)
        ->and($placed->documents[0]['name'])->toBe('Rapport préparatoire')
        ->and($placed->technician_email)->toBe('tech.coffrac@example.test')
        ->and($placed->duration_minutes)->toBe(90);

    $appointment = Appointment::query()
        ->where('external_source', 'coffrac')
        ->where('external_reference', '45')
        ->firstOrFail();

    expect($appointment->service_id)->toBe($service->id)
        ->and($appointment->technician_id)->toBe($technician->id)
        ->and($appointment->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i'))->toBe('2026-06-22 10:30');
});

it('renders lot appointment requests on the booking page', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $placedStartsAt = now()->copy()->addDay()->setTime(11, 0);
    $lot = Lot::query()->create([
        'name' => 'Lot Rhône',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);

    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);
    $placedAppointment = Appointment::query()->create([
        'service_id' => Service::query()->first()->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Place',
        'customer_phone' => '0600000004',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'starts_at' => $placedStartsAt,
        'duration_minutes' => 120,
        'ends_at' => $placedStartsAt->copy()->addMinutes(120),
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $placedAppointment->service_id,
        'appointment_id' => $placedAppointment->id,
        'customer_name' => 'Client Place',
        'customer_phone' => '0600000004',
        'address' => '10 Rue de la Barre, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
        'status' => LotAppointment::STATUS_PLACED,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.book'))
        ->assertOk()
        ->assertSee('depuis des lots')
        ->assertSee('booking-crm-pagination')
        ->assertSee('Lot Rhône')
        ->assertSee('Client Lot')
        ->assertSee('20 Place Bellecour, 69002 Lyon')
        ->assertSee('Client Place')
        ->assertSee('RDV placé')
        ->assertSee('Audit interne')
        ->assertSee('Placer le RDV')
        ->assertSee('Voir le RDV')
        ->assertSee('appointment_id='.$placedAppointment->id, false);
});

it('searches additional booking technicians compatible with the requested service', function () {
    config(['services.mapbox.token' => null]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle initial',
        'average_duration_minutes' => 90,
    ]);
    $otherService = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);

    Department::query()->updateOrCreate(['code' => '01'], ['name' => 'Ain']);
    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $compatibleTechnician = User::factory()->create([
        'first_name' => 'Arthur',
        'last_name' => 'Martin',
        'role' => 2,
        'admin' => false,
        'phone' => '0600000001',
        'address' => '1 Rue de la République, Lyon',
        'department_code' => '69',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'day_start_time' => '08:00',
        'day_end_time' => '18:00',
        'break_duration_minutes' => 45,
    ]);
    $compatibleTechnician->services()->attach($service);
    $compatibleTechnician->departments()->attach(['69', '01']);

    $incompatibleTechnician = User::factory()->create([
        'first_name' => 'Martin',
        'last_name' => 'Durand',
        'role' => 2,
        'admin' => false,
        'phone' => '0600000002',
        'address' => '10 Rue Nationale, Villeurbanne',
        'department_code' => '69',
        'latitude' => 45.7719,
        'longitude' => 4.8902,
    ]);
    $incompatibleTechnician->services()->attach($otherService);
    $incompatibleTechnician->departments()->attach('69');

    $this->actingAs($planner)
        ->postJson(route('planner.book.technicians.search'), [
            'query' => 'Martin',
            'manual_appointment' => [
                'first_name' => 'Claire',
                'last_name' => 'Client',
                'phone' => '0700000000',
                'address' => '20 Place Bellecour, Lyon',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'service_id' => $service->id,
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'technicians')
        ->assertJsonPath('technicians.0.id', $compatibleTechnician->id)
        ->assertJsonPath('technicians.0.name', $compatibleTechnician->full_name_with_departments)
        ->assertJsonStructure([
            'technicians' => [[
                'id',
                'name',
                'phone',
                'address',
                'department_code',
                'latitude',
                'longitude',
                'driving_distance_km',
                'driving_duration_minutes',
                'route_source',
                'covers_requested_department',
            ]],
        ]);
});

it('analyzes a lot appointment request with a selected service', function () {
    config(['services.mapbox.token' => null]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);

    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'address' => '1 Rue de la République, Lyon',
        'department_code' => '69',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'day_start_time' => '08:00',
        'day_end_time' => '18:00',
    ]);
    $technician->services()->attach($service);
    $technician->departments()->attach('69');

    $lot = Lot::query()->create([
        'name' => 'Lot sans prestation',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.analyze'), [
            'lot_appointment_id' => $lotAppointment->id,
            'lot_service_id' => $service->id,
        ])
        ->assertOk()
        ->assertJsonPath('crm_appointment.id', 'lot-'.$lotAppointment->id)
        ->assertJsonPath('crm_appointment.is_lot', true)
        ->assertJsonPath('crm_appointment.service.id', $service->id)
        ->assertJsonPath('filters.is_lot', true)
        ->assertJsonCount(1, 'technicians')
        ->assertJsonPath('technicians.0.id', $technician->id)
        ->assertJsonPath('technicians.0.name', $technician->full_name_with_departments);
});

it('includes saturday in booking slot suggestions', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit samedi',
            'average_duration_minutes' => 90,
        ]);

        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'address' => '1 Rue de la République, Lyon',
            'department_code' => '69',
            'latitude' => 45.764,
            'longitude' => 4.8357,
            'day_start_time' => '07:00',
            'day_end_time' => '21:00',
        ]);
        $technician->services()->attach($service);
        $technician->departments()->attach('69');

        $response = $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'manual_appointment' => [
                    'first_name' => 'Claire',
                    'last_name' => 'Samedi',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
            ])
            ->assertOk();

        expect($response->json('suggestions'))->not->toBeEmpty()
            ->and(collect($response->json('suggestions'))
                ->contains(fn (array $suggestion): bool => \Carbon\Carbon::parse($suggestion['start'])->isSaturday()))
            ->toBeTrue();

        $firstSuggestionProps = $response->json('suggestions.0.extendedProps');

        expect(array_key_exists('travel_to_distance_km', $firstSuggestionProps))->toBeTrue()
            ->and(array_key_exists('travel_after_distance_km', $firstSuggestionProps))->toBeTrue()
            ->and(array_key_exists('home_to_distance_km', $firstSuggestionProps))->toBeTrue()
            ->and(array_key_exists('return_home_distance_km', $firstSuggestionProps))->toBeTrue()
            ->and($firstSuggestionProps['travel_to_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($firstSuggestionProps['travel_after_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($firstSuggestionProps['home_to_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($firstSuggestionProps['return_home_distance_km'])->toBeGreaterThanOrEqual(0);
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('adds home previous and next route metrics to booking suggestions', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit intercalé',
            'average_duration_minutes' => 90,
        ]);

        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'address' => '1 Rue de la République, Lyon',
            'department_code' => '69',
            'latitude' => 45.764,
            'longitude' => 4.8357,
            'day_start_time' => '07:00',
            'day_end_time' => '21:00',
        ]);
        $technician->services()->attach($service);
        $technician->departments()->attach('69');

        Appointment::query()->create([
            'service_id' => $service->id,
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'customer_first_name' => 'Avant',
            'customer_last_name' => 'Client',
            'customer_phone' => '0600000001',
            'address' => '10 Rue de Brest, 69002 Lyon',
            'latitude' => 45.7627,
            'longitude' => 4.8337,
            'starts_at' => \Carbon\Carbon::parse('2026-06-11 09:00:00'),
            'duration_minutes' => 60,
            'ends_at' => \Carbon\Carbon::parse('2026-06-11 10:00:00'),
        ]);
        Appointment::query()->create([
            'service_id' => $service->id,
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'customer_first_name' => 'Après',
            'customer_last_name' => 'Client',
            'customer_phone' => '0600000002',
            'address' => '5 Place des Terreaux, 69001 Lyon',
            'latitude' => 45.7675,
            'longitude' => 4.8342,
            'starts_at' => \Carbon\Carbon::parse('2026-06-11 14:00:00'),
            'duration_minutes' => 60,
            'ends_at' => \Carbon\Carbon::parse('2026-06-11 15:00:00'),
        ]);

        $response = $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'manual_appointment' => [
                    'first_name' => 'Entre',
                    'last_name' => 'Client',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
            ])
            ->assertOk();

        $suggestion = collect($response->json('suggestions'))
            ->first(fn (array $suggestion): bool => \Carbon\Carbon::parse($suggestion['start'])->isSameDay('2026-06-11'));

        expect($suggestion)->not->toBeNull();

        $props = $suggestion['extendedProps'];

        expect($props['has_previous_appointment'])->toBeTrue()
            ->and($props['has_next_appointment'])->toBeTrue()
            ->and($props['origin_label'])->toBe('rdv précédent')
            ->and($props['home_to_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($props['travel_to_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($props['travel_after_distance_km'])->toBeGreaterThanOrEqual(0)
            ->and($props['return_home_distance_km'])->toBeGreaterThanOrEqual(0);
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('keeps absent technicians visible but suppresses booking suggestions during absence', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit absence',
            'average_duration_minutes' => 90,
        ]);

        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'address' => '1 Rue de la République, Lyon',
            'department_code' => '69',
            'latitude' => 45.764,
            'longitude' => 4.8357,
            'day_start_time' => '07:00',
            'day_end_time' => '21:00',
        ]);
        $technician->services()->attach($service);
        $technician->departments()->attach('69');

        TechnicianAbsence::query()->create([
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'starts_at' => '2026-06-11 00:00:00',
            'ends_at' => '2026-06-25 23:59:59',
            'reason' => 'Conges',
        ]);

        $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'manual_appointment' => [
                    'first_name' => 'Claire',
                    'last_name' => 'Absence',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'technicians')
            ->assertJsonPath('technicians.0.id', $technician->id)
            ->assertJsonPath('technicians.0.absence_label', 'Abs du 11/06/2026 au 25/06/2026')
            ->assertJsonCount(0, 'suggestions');
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('rejects booking creation during technician absence', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit absence',
            'average_duration_minutes' => 90,
        ]);
        $technician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'latitude' => 45.764,
            'longitude' => 4.8357,
        ]);
        $technician->services()->attach($service);

        TechnicianAbsence::query()->create([
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'starts_at' => '2026-06-12 00:00:00',
            'ends_at' => '2026-06-12 23:59:59',
        ]);

        $this->actingAs($planner)
            ->postJson(route('planner.book.appointments.store'), [
                'manual_appointment' => [
                    'first_name' => 'Claire',
                    'last_name' => 'Absence',
                    'phone' => '0700000000',
                    'address' => '20 Place Bellecour, 69002 Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'service_id' => $service->id,
                ],
                'technician_id' => $technician->id,
                'starts_at' => '2026-06-12 10:00:00',
                'duration_minutes' => 90,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('technician_id');

        expect(\App\Models\Appointment::query()->exists())->toBeFalse();
    } finally {
        \Carbon\Carbon::setTestNow();
    }
});

it('places a coffrac appointment without service when a service is selected at validation time', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);
    Mail::fake();

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'result' => true,
                'data' => [[
                    'id' => 45,
                    'source' => 'Coffrac',
                    'service_type' => Service::TYPE_COFFRAC,
                    'service_name' => null,
                    'customer_first_name' => 'Nora',
                    'customer_last_name' => 'PETIT',
                    'phone' => '0648764421',
                    'address' => '8 Place Royale, 44000 Nantes, France',
                    'department_code' => '44',
                    'latitude' => 47.2142,
                    'longitude' => -1.5586,
                ]],
            ]);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/45/placed') {
            return Http::response([
                'result' => true,
                'message' => 'Rendez-vous basculé en attente visite.',
            ]);
        }

        return Http::response(['message' => 'Unexpected request'], 500);
    });

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_MAR,
        'name' => 'Contrôle MAR choisi au placement',
        'average_duration_minutes' => 105,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'latitude' => 47.2184,
        'longitude' => -1.5536,
    ]);
    $technician->services()->attach($service);
    app(CoffracAppointmentService::class)->sync();

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'crm_appointment_id' => 'coffrac-45',
            'crm_service_id' => $service->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 30)->toIso8601String(),
            'duration_minutes' => 105,
            'comment' => 'Service choisi dans le modal',
        ])
        ->assertCreated()
        ->assertJsonStructure(['appointment_id']);

    $appointment = Appointment::query()->firstOrFail();

    expect($appointment->service_id)->toBe($service->id)
        ->and($appointment->customer_first_name)->toBe('Nora')
        ->and($appointment->customer_last_name)->toBe('PETIT')
        ->and($appointment->external_source)->toBe('coffrac')
        ->and($appointment->external_reference)->toBe('45')
        ->and($appointment->comment)->toBe('Service choisi dans le modal');

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'created'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('places a coffrac appointment and moves it to attente visite', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);
    Mail::fake();

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'result' => true,
                'data' => [[
                    'id' => 44,
                    'source' => 'Coffrac',
                    'service_type' => Service::TYPE_COFFRAC,
                    'service_name' => 'Inspection Coffrac',
                    'customer_first_name' => 'Claire',
                    'customer_last_name' => 'DUPONT',
                    'phone' => '0600000044',
                    'address' => '20 Place Bellecour, 69002 Lyon, France',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                ]],
            ]);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/44/placed') {
            return Http::response([
                'result' => true,
                'message' => 'Rendez-vous basculé en attente visite.',
            ]);
        }

        return Http::response(['message' => 'Unexpected request'], 500);
    });

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech.coffrac@example.test',
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    app(CoffracAppointmentService::class)->sync();

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'crm_appointment_id' => 'coffrac-44',
            'technician_id' => $technician->id,
            'starts_at' => '2026-06-22 10:30:00',
            'duration_minutes' => 90,
            'comment' => 'Placement confirmé depuis TechCalendar',
        ])
        ->assertCreated()
        ->assertJsonStructure(['appointment_id']);

    $appointment = Appointment::query()->firstOrFail();

    expect($appointment->external_source)->toBe('coffrac')
        ->and($appointment->external_reference)->toBe('44')
        ->and($appointment->status)->toBe(Appointment::STATUS_SCHEDULED)
        ->and($appointment->service_id)->toBe($service->id)
        ->and($appointment->customer_first_name)->toBe('Claire')
        ->and($appointment->customer_last_name)->toBe('DUPONT');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/44/placed'
        && $request['technician_email'] === 'tech.coffrac@example.test'
        && $request['duration_minutes'] === 90
        && $request['comment'] === 'Placement confirmé depuis TechCalendar');

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'created'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('links a placed appointment back to its lot appointment', function () {
    config(['services.mapbox.token' => null]);
    Mail::fake();

    $planner = User::factory()->create([
        'role' => 1,
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
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    $lot = Lot::query()->create([
        'name' => 'Lot à placer',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
            'lot_service_id' => $service->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 120,
            'comment' => 'Placement depuis lot',
        ])
        ->assertCreated()
        ->assertJsonStructure(['appointment_id']);

    $lotAppointment->refresh();
    $lot->refresh();

    expect($lotAppointment->appointment_id)->not->toBeNull()
        ->and($lotAppointment->service_id)->toBe($service->id)
        ->and($lotAppointment->status)->toBe(LotAppointment::STATUS_PLACED)
        ->and($lot->status)->toBe(Lot::STATUS_COMPLETED);

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'created'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $lotAppointment->appointment_id,
    );
});
