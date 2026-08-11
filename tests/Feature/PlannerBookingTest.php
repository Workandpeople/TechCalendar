<?php

use App\Jobs\SyncCoffracAppointmentsJob;
use App\Mail\TechnicianAppointmentNotificationMail;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\ExternalServiceAlias;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use App\Services\MapboxAddressGeocoder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

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
        'services.coffrac.ignored_references' => [],
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
        'services.coffrac.ignored_references' => [],
    ]);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [[
            'id' => 44,
            'source' => 'Coffrac',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => 'BAR EN 101 Isolation en combles perdus',
            'company_name' => 'ACME Energie',
            'customer_first_name' => 'Claire',
            'customer_last_name' => 'COFFRAC',
            'phone' => '0600000044',
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'department_code' => '69',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'comments' => [[
                'id' => 12,
                'text' => 'Commentaire préexistant Coffrac',
                'author_name' => 'Assistante Coffrac',
                'is_private' => false,
                'created_at' => '2026-07-14T09:10:00+02:00',
            ]],
        ]],
    ]));
    app(CoffracAppointmentService::class)->sync();

    $storedRequest = ExternalAppointmentRequest::query()
        ->where('source', CoffracAppointmentService::SOURCE)
        ->where('external_reference', '44')
        ->firstOrFail();

    expect($storedRequest->company_name)->toBe('ACME Energie')
        ->and($storedRequest->comments)->toHaveCount(1)
        ->and($storedRequest->comments[0]['text'])->toBe('Commentaire préexistant Coffrac');
    $pendingAppointment = app(CoffracAppointmentService::class)->pendingWithStatus(300)['appointments']->first();
    expect($pendingAppointment)->toMatchArray([
        'company_name' => 'ACME Energie',
        'service_display_name' => 'BAR EN 101 Isolation en combles perdus',
    ])
        ->and($pendingAppointment['comments'])->toHaveCount(1)
        ->and($pendingAppointment['comments'][0]['text'])->toBe('Commentaire préexistant Coffrac')
        ->and($pendingAppointment['comments'][0]['author_name'])->toBe('Assistante Coffrac');

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
        ->assertSee('ACME Energie')
        ->assertSee('BAR EN 101 Isolation en combles perdus')
        ->assertSee('Commentaire pr\u00e9existant Coffrac', false)
        ->assertSee('const bookingInitialCrmAppointmentId = "coffrac-44";', false);
});

it('refreshes coffrac appointment requests on the booking page', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);
    Queue::fake();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => 'already-local',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Déjà',
        'customer_last_name' => 'LOCAL',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'fetched_at' => now()->subMinute(),
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.refresh'))
        ->assertOk()
        ->assertJsonPath('sync_queued', true)
        ->assertJsonCount(0, 'appointments')
        ->assertJsonPath('coffrac_api_status.count', 1)
        ->assertJsonPath('coffrac_api_status.state', 'syncing')
        ->assertJsonPath('coffrac_api_status.label', 'Synchronisation Coffrac en cours')
        ->assertJsonPath('external_sources.0.key', 'coffrac')
        ->assertJsonPath('external_sources.1.enabled', false)
        ->assertJsonPath('external_sources.1.status.label', 'Connecteur 2 à connecter')
        ->assertJsonPath('external_sources.2.status.label', 'Connecteur 3 à connecter')
        ->assertJsonStructure([
            'sync_queued',
            'message',
            'appointments',
            'coffrac_api_status',
            'external_sources',
        ]);

    Queue::assertPushed(SyncCoffracAppointmentsJob::class, fn (SyncCoffracAppointmentsJob $job): bool => $job->incremental === false
        && $job->status === CoffracAppointmentService::REMOTE_STATUS_PENDING);

    $this->assertDatabaseHas('external_api_syncs', [
        'source' => 'coffrac',
        'state' => ExternalApiSync::STATE_SYNCING,
    ]);
});

it('uses separate unique locks for coffrac pending and placed sync jobs', function () {
    $pendingJob = new SyncCoffracAppointmentsJob(false, CoffracAppointmentService::REMOTE_STATUS_PENDING);
    $placedJob = new SyncCoffracAppointmentsJob(false, CoffracAppointmentService::REMOTE_STATUS_PLACED);
    $incrementalJob = new SyncCoffracAppointmentsJob(true, CoffracAppointmentService::REMOTE_STATUS_ALL);

    expect($pendingJob->uniqueId())->not->toBe($placedJob->uniqueId())
        ->and($pendingJob->uniqueId())->not->toBe($incrementalJob->uniqueId())
        ->and($placedJob->uniqueId())->not->toBe($incrementalJob->uniqueId());
});

it('returns a large local coffrac list and keeps appointments without gps visible', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    ExternalApiSync::query()->create([
        'source' => 'coffrac',
        'state' => ExternalApiSync::STATE_AVAILABLE,
        'message' => 'Synchronisation Coffrac terminée.',
        'last_successful_at' => now(),
        'metadata' => ['progress' => 100, 'stage' => 'Synchronisation Coffrac terminée.'],
    ]);

    foreach (range(1, 18) as $index) {
        ExternalAppointmentRequest::query()->create([
            'source' => 'coffrac',
            'external_reference' => (string) (9000 + $index),
            'status' => ExternalAppointmentRequest::STATUS_PENDING,
            'source_label' => 'Coffrac',
            'customer_first_name' => 'Client',
            'customer_last_name' => sprintf('TEST%02d', $index),
            'phone' => '0600000000',
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'department_code' => '69',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'fetched_at' => now(),
        ]);
    }

    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '9999',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Sans',
        'customer_last_name' => 'GPS',
        'phone' => '0600000000',
        'address' => 'Adresse à corriger',
        'department_code' => '69',
        'latitude' => null,
        'longitude' => null,
        'payload' => [
            'id' => 9999,
            'dossier_documents' => [[
                'name' => 'Fiche ancienne synchro',
                'path' => 'ancienne-synchro.pdf',
            ]],
        ],
        'fetched_at' => now(),
    ]);

    $this->actingAs($planner)
        ->getJson(route('planner.book.crm-appointments.index'))
        ->assertOk()
        ->assertJsonCount(19, 'appointments')
        ->assertJsonPath('coffrac_api_status.count', 19)
        ->assertJsonPath('coffrac_api_status.missing_coordinates_count', 1)
        ->assertJsonFragment([
            'id' => 'coffrac-9999',
            'latitude' => null,
            'longitude' => null,
        ])
        ->assertJsonFragment([
            'name' => 'Fiche ancienne synchro',
            'url' => 'https://coffrac.test/documents/ancienne-synchro.pdf',
        ]);
});

it('rejects coffrac analysis when the local appointment has no gps coordinates', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '9999',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Sans',
        'customer_last_name' => 'GPS',
        'phone' => '0600000000',
        'address' => 'Adresse à corriger',
        'department_code' => '69',
        'latitude' => null,
        'longitude' => null,
        'fetched_at' => now(),
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.analyze'), [
            'crm_appointment_id' => 'coffrac-9999',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['crm_appointment_id'])
        ->assertJsonPath('errors.crm_appointment_id.0', 'Coordonnées GPS absentes pour ce RDV. Ouvre le détail du RDV, corrige l’adresse puis relance le géocodage Mapbox.');
});

it('keeps coffrac sync stable when the remote api returns a long error', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    Http::fake(fn () => Http::response([
        'message' => str_repeat('Erreur SQL Coffrac distante ', 30),
    ], 500));

    $result = app(CoffracAppointmentService::class)->sync();

    $sync = ExternalApiSync::query()->where('source', 'coffrac')->firstOrFail();

    expect($result['available'])->toBeFalse()
        ->and($sync->state)->toBe(ExternalApiSync::STATE_UNAVAILABLE)
        ->and(mb_strlen((string) $sync->message))->toBeLessThanOrEqual(240)
        ->and($sync->message)->toContain('Erreur SQL Coffrac distante');
});

it('skips a coffrac appointment that crashes remote page serialization', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
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

it('continues coffrac pagination when the remote api skips a serialized appointment', function () {
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

        return match (((int) ($query['offset'] ?? 0)).':'.((int) ($query['limit'] ?? 0))) {
            '0:2' => Http::response([
                'result' => true,
                'data' => [$appointmentPayload(101)],
                'fetched_count' => 2,
                'skipped_count' => 1,
            ]),
            '2:2' => Http::response([
                'result' => true,
                'data' => [$appointmentPayload(103)],
                'fetched_count' => 1,
                'skipped_count' => 0,
            ]),
            default => Http::response([
                'result' => true,
                'data' => [],
                'fetched_count' => 0,
                'skipped_count' => 0,
            ]),
        };
    });

    $result = app(CoffracAppointmentService::class)->sync(2);

    expect($result['available'])->toBeTrue()
        ->and($result['count'])->toBe(2)
        ->and($result['message'])->toContain('1 RDV ignoré');

    expect(ExternalAppointmentRequest::query()->where('source', 'coffrac')->pluck('external_reference')->all())
        ->toBe(['101', '103']);
});

it('geocodes coffrac pending appointments without remote coordinates', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->once()
        ->with('145 RUE DE PARIS, 75019 PARIS, France')
        ->andReturn([
            'latitude' => 48.888112,
            'longitude' => 2.379024,
            'formatted_address' => '145 Rue de Paris, 75019 Paris, France',
            'mapbox_id' => 'address.75019',
            'mapbox_confidence' => 0.92,
            'warnings' => [],
        ]);
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    Http::fake(fn () => Http::response([
        'result' => true,
        'data' => [[
            'id' => 4256,
            'source' => 'Coffrac',
            'status_name' => 'Prise de RDV',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => 'BAR 145 AUDIT',
            'customer_first_name' => 'David',
            'customer_last_name' => 'DHERY',
            'phone' => '0600004256',
            'address' => '145 RUE DE PARIS, 75019 PARIS, France',
            'address_line' => '145 RUE DE PARIS',
            'postal_code' => '75019',
            'city' => 'PARIS',
            'department_code' => '75',
            'latitude' => null,
            'longitude' => null,
        ]],
    ]));

    app(CoffracAppointmentService::class)->sync();

    $stored = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '4256')
        ->firstOrFail();

    $appointments = app(CoffracAppointmentService::class)->pending(15);

    expect($stored->latitude)->toBe(48.888112)
        ->and($stored->longitude)->toBe(2.379024)
        ->and($appointments)->toHaveCount(1)
        ->and($appointments->first()['id'])->toBe('coffrac-4256');
});

it('keeps coffrac appointments when mapbox cannot geocode the remote address', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->once()
        ->with('ADRESSE INTROUVABLE, 99999 VILLE FANTOME, France')
        ->andReturn([
            'latitude' => null,
            'longitude' => null,
            'formatted_address' => null,
            'mapbox_id' => null,
            'mapbox_confidence' => null,
            'warnings' => ['Aucun résultat Mapbox pour cette adresse.'],
        ]);
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    Http::fake(fn () => Http::response([
        'result' => true,
        'data' => [[
            'id' => 4257,
            'source' => 'Coffrac',
            'status_name' => 'Prise de RDV',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => 'BAR 145 AUDIT',
            'customer_first_name' => 'Adresse',
            'customer_last_name' => 'INVALIDE',
            'phone' => '0600004257',
            'address' => 'ADRESSE INTROUVABLE, 99999 VILLE FANTOME, France',
            'address_line' => 'ADRESSE INTROUVABLE',
            'postal_code' => '99999',
            'city' => 'VILLE FANTOME',
            'department_code' => '99',
            'latitude' => null,
            'longitude' => null,
        ]],
    ]));

    app(CoffracAppointmentService::class)->sync();

    $stored = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '4257')
        ->firstOrFail();
    $appointments = app(CoffracAppointmentService::class)->pending(15);

    expect($stored->latitude)->toBeNull()
        ->and($stored->longitude)->toBeNull()
        ->and($stored->address)->toBe('ADRESSE INTROUVABLE, 99999 VILLE FANTOME, France')
        ->and($appointments)->toHaveCount(1)
        ->and($appointments->first()['id'])->toBe('coffrac-4257');
});

it('keeps coffrac appointments when mapbox throws during remote geocoding', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->once()
        ->with('10 RUE MAPBOX KO, 69003 LYON, France')
        ->andThrow(new RuntimeException('Timeout Mapbox'));
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    Http::fake(fn () => Http::response([
        'result' => true,
        'data' => [[
            'id' => 4258,
            'source' => 'Coffrac',
            'status_name' => 'Prise de RDV',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => 'BAR 145 AUDIT',
            'customer_first_name' => 'Mapbox',
            'customer_last_name' => 'TIMEOUT',
            'phone' => '0600004258',
            'address' => '10 RUE MAPBOX KO, 69003 LYON, France',
            'address_line' => '10 RUE MAPBOX KO',
            'postal_code' => '69003',
            'city' => 'LYON',
            'department_code' => '69',
            'latitude' => null,
            'longitude' => null,
        ]],
    ]));

    $result = app(CoffracAppointmentService::class)->sync();

    $stored = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '4258')
        ->firstOrFail();
    $appointments = app(CoffracAppointmentService::class)->pending(15);

    expect($result['available'])->toBeTrue()
        ->and($result['pending_count'])->toBe(1)
        ->and($result['message'])->not->toContain('ignoré')
        ->and($stored->latitude)->toBeNull()
        ->and($stored->longitude)->toBeNull()
        ->and($stored->address)->toBe('10 RUE MAPBOX KO, 69003 LYON, France')
        ->and($appointments)->toHaveCount(1)
        ->and($appointments->first()['id'])->toBe('coffrac-4258');
});

it('does not geocode an unchanged coffrac appointment twice', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->once()
        ->with('145 RUE DE PARIS, 75019 PARIS, France')
        ->andReturn([
            'latitude' => 48.888112,
            'longitude' => 2.379024,
            'formatted_address' => '145 Rue de Paris, 75019 Paris, France',
            'mapbox_id' => 'address.75019',
            'mapbox_confidence' => 0.92,
            'warnings' => [],
        ]);
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    Http::fake(fn () => Http::response([
        'result' => true,
        'data' => [[
            'id' => 4256,
            'source' => 'Coffrac',
            'status_name' => 'Prise de RDV',
            'service_type' => Service::TYPE_COFFRAC,
            'service_name' => 'BAR 145 AUDIT',
            'customer_first_name' => 'David',
            'customer_last_name' => 'DHERY',
            'phone' => '0600004256',
            'address' => '145 RUE DE PARIS, 75019 PARIS, France',
            'address_line' => '145 RUE DE PARIS',
            'postal_code' => '75019',
            'city' => 'PARIS',
            'department_code' => '75',
            'latitude' => null,
            'longitude' => null,
        ]],
    ]));

    app(CoffracAppointmentService::class)->sync();
    app(CoffracAppointmentService::class)->sync();

    $stored = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '4256')
        ->firstOrFail();

    expect($stored->latitude)->toBe(48.888112)
        ->and($stored->longitude)->toBe(2.379024);
});

it('keeps local coffrac appointments that are absent from an incremental sync delta', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.incremental_overlap_minutes' => 10,
    ]);

    ExternalApiSync::query()->create([
        'source' => 'coffrac',
        'state' => ExternalApiSync::STATE_AVAILABLE,
        'message' => 'Synchronisation Coffrac terminée.',
        'last_successful_at' => now()->subHour(),
        'metadata' => ['progress' => 100],
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '100',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Delta',
        'customer_last_name' => 'UPDATE',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'remote_updated_at' => now()->subDay(),
        'fetched_at' => now()->subDay(),
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '101',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Delta',
        'customer_last_name' => 'ABSENT',
        'address' => '8 Place Royale, 44000 Nantes, France',
        'department_code' => '44',
        'latitude' => 47.2142,
        'longitude' => -1.5586,
        'remote_updated_at' => now()->subDay(),
        'fetched_at' => now()->subDay(),
    ]);

    $requestedUpdatedAfter = null;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$requestedUpdatedAfter) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $requestedUpdatedAfter = $query['updated_after'] ?? null;

        return Http::response([
            'result' => true,
            'data' => [[
                'id' => 100,
                'source' => 'Coffrac',
                'status_name' => 'RDV attente visite',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'Inspection',
                'customer_first_name' => 'Delta',
                'customer_last_name' => 'UPDATE',
                'phone' => '0600000100',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'updated_at' => now()->toIso8601String(),
            ]],
        ]);
    });

    $result = app(CoffracAppointmentService::class)->sync(incremental: true);

    expect($requestedUpdatedAfter)->not->toBeNull()
        ->and($result['pending_count'])->toBe(1)
        ->and($result['placed_count'])->toBe(1)
        ->and($result['count'])->toBe(2);

    $keptRequest = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '101')
        ->firstOrFail();

    expect($keptRequest->status)->toBe(ExternalAppointmentRequest::STATUS_PENDING);
});

it('syncs only pending coffrac requests for manual booking refreshes', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);
    $lastFullSyncAt = now()->subHour()->startOfSecond();

    ExternalApiSync::query()->create([
        'source' => 'coffrac',
        'state' => ExternalApiSync::STATE_AVAILABLE,
        'message' => 'Synchronisation complète Coffrac terminée.',
        'last_successful_at' => $lastFullSyncAt,
        'metadata' => ['progress' => 100, 'mode' => 'full'],
    ]);

    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => 'placed-legacy',
        'status' => ExternalAppointmentRequest::STATUS_PLACED,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Legacy',
        'customer_last_name' => 'PLACEE',
        'address' => '8 Place Royale, 44000 Nantes, France',
        'department_code' => '44',
        'latitude' => 47.2142,
        'longitude' => -1.5586,
        'fetched_at' => now()->subDay(),
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => 'pending-stale',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'Ancienne',
        'customer_last_name' => 'DEMANDE',
        'address' => '1 Rue Nationale, 59800 Lille, France',
        'department_code' => '59',
        'latitude' => 50.6366,
        'longitude' => 3.0635,
        'fetched_at' => now()->subDay(),
    ]);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [[
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
        ]],
    ]));

    $result = app(CoffracAppointmentService::class)->sync(status: CoffracAppointmentService::REMOTE_STATUS_PENDING);

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), 'status=pending')
        && ! str_contains($request->url(), 'status=all'));

    expect($result['pending_count'])->toBe(1)
        ->and($result['placed_count'])->toBe(1)
        ->and($result['count'])->toBe(2);

    expect(ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', 'placed-legacy')
        ->value('status'))->toBe(ExternalAppointmentRequest::STATUS_PLACED);

    expect(ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', 'pending-stale')
        ->exists())->toBeFalse();

    expect(ExternalApiSync::query()
        ->where('source', 'coffrac')
        ->value('last_successful_at')
        ?->format('Y-m-d H:i:s'))->toBe($lastFullSyncAt->format('Y-m-d H:i:s'));
});

it('ignores configured obsolete coffrac references during pending syncs', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => ['4256', '4257', '4258'],
    ]);

    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '4256',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'customer_first_name' => 'David',
        'customer_last_name' => 'DHERY',
        'address' => '145 RUE DE PARIS, 75019 PARIS, France',
        'department_code' => '75',
        'latitude' => 48.878733,
        'longitude' => 2.401344,
        'fetched_at' => now()->subDay(),
    ]);

    Http::fake(fn (\Illuminate\Http\Client\Request $request) => Http::response([
        'result' => true,
        'data' => [
            [
                'id' => 4256,
                'source' => 'Coffrac',
                'status_name' => 'PRISE DE RDV',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'BAR 145 AUDIT',
                'customer_first_name' => 'David',
                'customer_last_name' => 'DHERY',
                'phone' => '0615959595',
                'address' => '145 RUE DE PARIS, 75019 PARIS, France',
                'department_code' => '75',
                'latitude' => 48.878733,
                'longitude' => 2.401344,
            ],
            [
                'id' => 44,
                'source' => 'Coffrac',
                'status_name' => 'PRISE DE RDV',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'AGRI TH 117',
                'customer_first_name' => 'Claire',
                'customer_last_name' => 'COFFRAC',
                'phone' => '0600000044',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
            ],
        ],
    ]));

    $result = app(CoffracAppointmentService::class)->sync(status: CoffracAppointmentService::REMOTE_STATUS_PENDING);
    $appointments = app(CoffracAppointmentService::class)->pending(15);

    expect($result['pending_count'])->toBe(1)
        ->and(ExternalAppointmentRequest::query()
            ->where('source', 'coffrac')
            ->where('external_reference', '4256')
            ->exists())->toBeFalse()
        ->and(ExternalAppointmentRequest::query()
            ->where('source', 'coffrac')
            ->where('external_reference', '44')
            ->exists())->toBeTrue()
        ->and($appointments->pluck('external_reference')->all())->toBe(['44']);
});

it('updates a local coffrac appointment before booking it', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit choisi depuis le détail',
        'average_duration_minutes' => 120,
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '4257',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'Prise de RDV',
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'MARTIN',
        'phone' => '0600004257',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'documents' => [[
            'name' => 'Avis Coffrac',
            'url' => 'https://coffrac.test/documents/4257.pdf',
        ]],
        'payload' => ['id' => 4257],
        'fetched_at' => now(),
    ]);
    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments/4257/address' => Http::response([
            'result' => true,
            'message' => 'Adresse corrigée.',
        ]),
    ]);

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')
        ->once()
        ->with('22 Rue Victor Hugo, 69002 Lyon')
        ->andReturn([
            'latitude' => 45.754921,
            'longitude' => 4.829713,
            'formatted_address' => '22 Rue Victor Hugo, 69002 Lyon, France',
            'mapbox_id' => 'address.69002',
            'mapbox_confidence' => 0.94,
            'warnings' => [],
        ]);
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    $this->actingAs($planner)
        ->patchJson(route('planner.book.crm-appointments.update', ['crmAppointmentId' => 'coffrac-4257']), [
            'service_id' => $service->id,
            'address' => '22 Rue Victor Hugo, 69002 Lyon',
            'comment' => 'Client à rappeler avant intervention.',
        ])
        ->assertOk()
        ->assertJsonPath('appointment.id', 'coffrac-4257')
        ->assertJsonPath('appointment.service.id', $service->id)
        ->assertJsonPath('appointment.address', '22 Rue Victor Hugo, 69002 Lyon, France')
        ->assertJsonPath('appointment.postal_code', '69002')
        ->assertJsonPath('appointment.city', 'Lyon')
        ->assertJsonPath('appointment.department_code', '69')
        ->assertJsonPath('appointment.comment', 'Client à rappeler avant intervention.')
        ->assertJsonPath('appointment.documents.0.name', 'Avis Coffrac');

    $stored = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '4257')
        ->firstOrFail();

    expect($stored->service_type)->toBe(Service::TYPE_AUDIT)
        ->and($stored->service_name)->toBe('Audit choisi depuis le détail')
        ->and($stored->address)->toBe('22 Rue Victor Hugo, 69002 Lyon, France')
        ->and($stored->address_line)->toBe('22 Rue Victor Hugo')
        ->and($stored->postal_code)->toBe('69002')
        ->and($stored->city)->toBe('Lyon')
        ->and($stored->latitude)->toBe(45.754921)
        ->and($stored->longitude)->toBe(4.829713)
        ->and($stored->comment)->toBe('Client à rappeler avant intervention.');

    Http::assertSentCount(1);
    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/4257/address'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['address'] === '22 Rue Victor Hugo, 69002 Lyon, France'
        && $request['address_line'] === '22 Rue Victor Hugo'
        && $request['postal_code'] === '69002'
        && $request['city'] === 'Lyon'
        && $request['latitude'] === 45.754921
        && $request['longitude'] === 4.829713
        && $request['comment'] === 'Client à rappeler avant intervention.');
});

it('updates a pending coffrac appointment comment without geocoding it', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => '4260',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'Prise de RDV',
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'MARTIN',
        'phone' => '0600004260',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'address_line' => '20 Place Bellecour',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'comment' => 'Ancien commentaire',
        'payload' => ['id' => 4260],
        'fetched_at' => now(),
    ]);
    Http::fake();

    $geocoder = \Mockery::mock(MapboxAddressGeocoder::class);
    $geocoder->shouldReceive('geocode')->never();
    app()->instance(MapboxAddressGeocoder::class, $geocoder);

    $this->actingAs($planner)
        ->patchJson(route('planner.book.crm-appointments.update', ['crmAppointmentId' => 'coffrac-4260']), [
            'comment' => 'Commentaire ajouté depuis la modale.',
        ])
        ->assertOk()
        ->assertJsonPath('appointment.id', 'coffrac-4260')
        ->assertJsonPath('appointment.address', '20 Place Bellecour, 69002 Lyon, France')
        ->assertJsonPath('appointment.comment', 'Commentaire ajouté depuis la modale.');

    $stored = ExternalAppointmentRequest::query()
        ->where('source', 'coffrac')
        ->where('external_reference', '4260')
        ->firstOrFail();

    expect($stored->address)->toBe('20 Place Bellecour, 69002 Lyon, France')
        ->and($stored->latitude)->toBe(45.7578)
        ->and($stored->longitude)->toBe(4.832)
        ->and($stored->comment)->toBe('Commentaire ajouté depuis la modale.');

    Http::assertSentCount(0);
});

it('marks a pending coffrac appointment as problem from its detail modal', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/problem-types' => Http::response([
            'result' => true,
            'data' => [
                ['value' => CoffracAppointmentService::PROBLEM_TYPE_RENVOI_CLIENT, 'label' => 'Renvoi client', 'requires_recall' => false],
                ['value' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'label' => 'Demande de rappel', 'requires_recall' => true],
                ['value' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT, 'label' => 'Problème document', 'requires_recall' => false],
            ],
        ]),
        'https://coffrac.test/api/techcalendar/appointments/4258/problem' => Http::response([
            'result' => true,
            'message' => 'Dossier basculé en problème.',
        ]),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $storedRequest = ExternalAppointmentRequest::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '4258',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'Prise de RDV',
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'MARTIN',
        'phone' => '0600004258',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'comment' => 'Note importée depuis Coffrac',
        'payload' => ['id' => 4258],
        'fetched_at' => now(),
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.problem', ['crmAppointmentId' => 'coffrac-4258']), [
            'comment' => '',
            'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_RENVOI_CLIENT,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('comment');

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.problem', ['crmAppointmentId' => 'coffrac-4258']), [
            'comment' => 'Client à rappeler.',
            'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recall_date', 'recall_time']);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.problem', ['crmAppointmentId' => 'coffrac-4258']), [
            'comment' => 'Client injoignable, dossier à retraiter côté Coffrac.',
            'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK,
            'recall_date' => '2026-06-24',
            'recall_time' => '09:30',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Problème RDV déclaré dans Coffrac.')
        ->assertJsonPath('appointment.id', 'coffrac-4258')
        ->assertJsonPath('appointment.status', ExternalAppointmentRequest::STATUS_PROBLEM)
        ->assertJsonCount(0, 'appointments');

    $storedRequest->refresh();

    expect($storedRequest->status)->toBe(ExternalAppointmentRequest::STATUS_PROBLEM)
        ->and($storedRequest->appointment_id)->toBeNull()
        ->and($storedRequest->comment)->toBe('Client injoignable, dossier à retraiter côté Coffrac.');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/4258/problem'
        && $request['comment'] === 'Client injoignable, dossier à retraiter côté Coffrac.'
        && $request['problem_type'] === CoffracAppointmentService::PROBLEM_TYPE_CALLBACK
        && $request['recall_date'] === '2026-06-24'
        && $request['recall_time'] === '09:30'
        && $request['techcalendar_external_request_id'] === $storedRequest->id);
});

it('matches a coffrac appointment service through an external alias', function () {
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Résidentiel EC 104',
        'average_duration_minutes' => 90,
    ]);
    ExternalServiceAlias::query()->create([
        'service_id' => $service->id,
        'source' => CoffracAppointmentService::SOURCE,
        'external_type' => Service::TYPE_COFFRAC,
        'external_name' => 'RES EC 104 (01/01/25)',
        'normalized_external_type' => ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC),
        'normalized_external_name' => ExternalServiceAlias::normalizeValue('RES EC 104 (01/01/25)'),
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '5000',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'Prise de RDV',
        'service_type' => Service::TYPE_COFFRAC,
        'service_name' => 'RES EC 104 (01/01/25)',
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'MARTIN',
        'phone' => '0600005000',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'fetched_at' => now(),
    ]);

    $appointment = app(CoffracAppointmentService::class)->find('coffrac-5000');

    expect($appointment['service']['id'])->toBe($service->id)
        ->and($appointment['service']['name'])->toBe('Résidentiel EC 104')
        ->and($appointment['service']['average_duration_minutes'])->toBe(90);
});

it('refreshes a pending coffrac appointment documents from booking detail', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments*' => Http::response([
            'result' => true,
            'data' => [[
                'id' => '9901',
                'source' => 'Coffrac',
                'status_name' => 'Prise de RDV',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'BAR EN 101',
                'customer_first_name' => 'Nina',
                'customer_last_name' => 'Martin',
                'phone' => '0600009901',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'documents' => [[
                    'id' => 991,
                    'scope' => 'dossier',
                    'name' => 'Document ajouté côté Coffrac',
                    'url' => 'https://coffrac.test/documents/9901-new.pdf',
                ]],
            ]],
            'fetched_count' => 1,
        ]),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'BAR EN 101',
        'average_duration_minutes' => 90,
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '9901',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'Prise de RDV',
        'service_type' => Service::TYPE_COFFRAC,
        'service_name' => 'BAR EN 101',
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'Martin',
        'phone' => '0600009901',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'documents' => [['name' => 'Ancien document']],
        'payload' => ['id' => '9901', 'documents' => [['name' => 'Ancien document']]],
        'fetched_at' => now(),
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.crm-appointments.refresh-one', ['crmAppointmentId' => 'coffrac-9901']))
        ->assertOk()
        ->assertJsonPath('appointment.id', 'coffrac-9901')
        ->assertJsonPath('appointment.documents.0.name', 'Document ajouté côté Coffrac')
        ->assertJsonPath('appointment.documents.0.url', 'https://coffrac.test/documents/9901-new.pdf');
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
    $longCoffracPhone = '06 00 00 00 45 / 07 00 00 00 45 / Standard: +33 1 23 45 67 89';

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
                'phone' => $longCoffracPhone,
                'address' => '8 Place Royale, 44000 Nantes, France',
                'department_code' => '44',
                'latitude' => 47.2142,
                'longitude' => -1.5586,
                'technician_email' => 'tech.coffrac@example.test',
                'starts_at' => '2026-06-22T10:30:00+02:00',
                'duration_minutes' => 90,
                'documents' => [[
                    'id' => 10,
                    'scope' => 'dossier',
                    'name' => 'Rapport préparatoire',
                    'path' => 'rapport.pdf',
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
        ->and($placed->documents[0]['url'])->toBe('https://coffrac.test/documents/rapport.pdf')
        ->and($placed->technician_email)->toBe('tech.coffrac@example.test')
        ->and($placed->duration_minutes)->toBe(90);

    $appointment = Appointment::query()
        ->where('external_source', 'coffrac')
        ->where('external_reference', '45')
        ->firstOrFail();

    expect($appointment->service_id)->toBe($service->id)
        ->and($appointment->technician_id)->toBe($technician->id)
        ->and($appointment->customer_phone)->toBe($longCoffracPhone)
        ->and($appointment->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i'))->toBe('2026-06-22 10:30');
});

it('scopes full placed coffrac sync to the configured date window without archiving pending requests', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', config('app.timezone')));

    try {
        config([
            'services.coffrac.api_url' => 'https://coffrac.test/api',
            'services.coffrac.api_token' => 'secret-token',
            'services.coffrac.placed_sync_past_years' => 1,
            'services.coffrac.placed_sync_future_months' => 2,
        ]);

        ExternalAppointmentRequest::query()->create([
            'source' => 'coffrac',
            'external_reference' => 'pending-keep',
            'status' => ExternalAppointmentRequest::STATUS_PENDING,
        ]);

        $requestedQueries = [];

        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$requestedQueries) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requestedQueries[] = $query;

            return Http::response([
                'result' => true,
                'data' => [],
                'fetched_count' => 0,
                'skipped_count' => 0,
            ]);
        });

        $this->artisan('coffrac:sync --status=placed')
            ->assertSuccessful();

        expect($requestedQueries[0]['status'] ?? null)->toBe(CoffracAppointmentService::REMOTE_STATUS_PLACED)
            ->and($requestedQueries[0]['date_from'] ?? null)->toBe('2025-08-11')
            ->and($requestedQueries[0]['date_to'] ?? null)->toBe('2026-10-11');

        $this->assertDatabaseHas('external_appointment_requests', [
            'source' => 'coffrac',
            'external_reference' => 'pending-keep',
            'status' => ExternalAppointmentRequest::STATUS_PENDING,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('renders lot appointment requests on the booking page', function () {
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
    ]);
    $placedStartsAt = now()->copy()->addDay()->setTime(11, 0);
    $lot = Lot::query()->create([
        'name' => 'Lot Rhône',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
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
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Non Place',
        'customer_phone' => '0600000005',
        'address' => '1 Rue Non Place',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.758,
        'longitude' => 4.833,
        'status' => LotAppointment::STATUS_NOT_PLACED,
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
        ->assertSee('booking-lot-filter-search')
        ->assertSee('data-booking-lot-id="'.$lot->id.'"', false)
        ->assertSee('data-lot-filter-department="69"', false)
        ->assertSee('data-lot-filter-status="pending"', false)
        ->assertSee('data-lot-filter-status="placed"', false)
        ->assertSee('techcalendar.planner.book.lot_filters.')
        ->assertSee('Client Lot')
        ->assertSee('20 Place Bellecour, 69002 Lyon')
        ->assertSee('Client Place')
        ->assertSee('RDV placé')
        ->assertDontSee('Client Non Place')
        ->assertDontSee('À vérifier')
        ->assertDontSee('A vérifier')
        ->assertDontSee('GPS à corriger')
        ->assertSee('Audit interne')
        ->assertSee('Placer le RDV')
        ->assertSee('Voir le RDV')
        ->assertSee('appointment_id='.$placedAppointment->id, false);
});

it('renders lot booking stats against the sampling objective', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot contact échantillonné',
        'type' => Lot::TYPE_SAMPLE_CONTACT_CONTROL,
        'sampling_percentage' => 20,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);

    foreach (range(1, 10) as $index) {
        LotAppointment::query()->create([
            'lot_id' => $lot->id,
            'row_number' => $index,
            'customer_name' => 'Client contact '.$index,
            'customer_phone' => '06000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'address' => '20 Place Bellecour',
            'postal_code' => '69002',
            'city' => 'Lyon',
            'department_code' => '69',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'status' => $index === 1 ? LotAppointment::STATUS_CONTACT_PROCESSED : LotAppointment::STATUS_PENDING,
            'processing_mode' => $index === 1 ? LotAppointment::PROCESSING_MODE_CONTACT : null,
            'contact_satisfaction' => $index === 1 ? true : null,
            'contact_comment' => $index === 1 ? 'Contact traité' : null,
            'contact_processed_at' => $index === 1 ? now() : null,
        ]);
    }

    $this->actingAs($planner)
        ->get(route('planner.book'))
        ->assertOk()
        ->assertSee('Lot contact échantillonné')
        ->assertSee('RDV téléphoniques')
        ->assertSee('1 / 2')
        ->assertSee('50%')
        ->assertSee('Client contact 1');
});

it('does not render lot appointments excluded from lot statistics on the booking page', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    $lot = Lot::query()->create([
        'name' => 'Lot filtré',
        'type' => Lot::TYPE_FULL_CONTROL,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);

    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client inclus',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);
    LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client sorti',
        'customer_phone' => '0600000005',
        'address' => '30 Place Bellecour',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.758,
        'longitude' => 4.833,
        'status' => LotAppointment::STATUS_PENDING,
        'excluded_from_lot_stats' => true,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.book'))
        ->assertOk()
        ->assertSee('Lot filtré')
        ->assertSee('Client inclus')
        ->assertDontSee('Client sorti');
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

it('analyzes a lot appointment request with the lot service', function () {
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
        'name' => 'Lot avec prestation',
        'type' => Lot::TYPE_SAMPLE_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot',
        'company_name' => 'ACME Industrie',
        'site_name' => 'Site de Lyon',
        'installer_name' => 'Installateur Rhône',
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
        ])
        ->assertOk()
        ->assertJsonPath('crm_appointment.id', 'lot-'.$lotAppointment->id)
        ->assertJsonPath('crm_appointment.is_lot', true)
        ->assertJsonPath('crm_appointment.customer_name', 'ACME Industrie')
        ->assertJsonPath('crm_appointment.company_name', 'ACME Industrie')
        ->assertJsonPath('crm_appointment.site_name', 'Site de Lyon')
        ->assertJsonPath('crm_appointment.installer_name', 'Installateur Rhône')
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
            ->first(fn (array $suggestion): bool => \Carbon\Carbon::parse($suggestion['start'])->isSameDay('2026-06-11')
                && $suggestion['extendedProps']['has_previous_appointment']
                && $suggestion['extendedProps']['has_next_appointment']);

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

it('suggests appointments before and after an existing booking while preserving the lunch break', function () {
    config(['services.mapbox.token' => null]);
    \Carbon\Carbon::setTestNow('2026-06-11 09:00:00');

    try {
        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_AUDIT,
            'name' => 'Audit pause',
            'average_duration_minutes' => 60,
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
            'break_duration_minutes' => 45,
        ]);
        $technician->services()->attach($service);
        $technician->departments()->attach('69');

        $existingAppointment = Appointment::query()->create([
            'service_id' => $service->id,
            'technician_id' => $technician->id,
            'created_by' => $planner->id,
            'customer_first_name' => 'Client',
            'customer_last_name' => 'Quatorze',
            'customer_phone' => '0600000001',
            'address' => '20 Place Bellecour, 69002 Lyon',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'starts_at' => \Carbon\Carbon::parse('2026-06-11 14:00:00'),
            'duration_minutes' => 60,
            'ends_at' => \Carbon\Carbon::parse('2026-06-11 15:00:00'),
        ]);

        $response = $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'manual_appointment' => [
                    'first_name' => 'Nouveau',
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

        $daySuggestions = collect($response->json('suggestions'))
            ->filter(fn (array $suggestion): bool => \Carbon\Carbon::parse($suggestion['start'])->isSameDay('2026-06-11'))
            ->values();

        $beforeExisting = $daySuggestions->first(fn (array $suggestion): bool => $suggestion['extendedProps']['next_appointment_id'] === $existingAppointment->id);
        $afterExisting = $daySuggestions->first(fn (array $suggestion): bool => $suggestion['extendedProps']['previous_appointment_id'] === $existingAppointment->id);

        expect($beforeExisting)->not->toBeNull()
            ->and($afterExisting)->not->toBeNull()
            ->and(\Carbon\Carbon::parse($beforeExisting['end'])->lte($existingAppointment->starts_at))->toBeTrue()
            ->and(\Carbon\Carbon::parse($afterExisting['start'])->gte($existingAppointment->ends_at))->toBeTrue()
            ->and($beforeExisting['extendedProps']['has_next_appointment'])->toBeTrue()
            ->and($afterExisting['extendedProps']['has_previous_appointment'])->toBeTrue();
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

it('replaces an existing appointment through the booking workflow without creating a duplicate', function () {
    config(['services.mapbox.token' => null]);
    Mail::fake();
    \Carbon\Carbon::setTestNow('2026-06-10 08:00:00');

    try {
        Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

        $planner = User::factory()->create([
            'role' => 1,
            'admin' => false,
        ]);
        $service = Service::query()->create([
            'type' => Service::TYPE_COFFRAC,
            'name' => 'Contrôle à replacer',
            'average_duration_minutes' => 90,
        ]);
        $originalTechnician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'first_name' => 'Paul',
            'last_name' => 'Original',
            'latitude' => 45.764,
            'longitude' => 4.8357,
            'day_start_time' => '08:00',
            'day_end_time' => '19:00',
            'break_duration_minutes' => 45,
        ]);
        $replacementTechnician = User::factory()->create([
            'role' => 2,
            'admin' => false,
            'first_name' => 'Nina',
            'last_name' => 'Remplaçante',
            'latitude' => 45.75,
            'longitude' => 4.85,
            'day_start_time' => '08:00',
            'day_end_time' => '19:00',
            'break_duration_minutes' => 45,
        ]);

        $originalTechnician->services()->attach($service);
        $replacementTechnician->services()->attach($service);
        $originalTechnician->departments()->attach('69');
        $replacementTechnician->departments()->attach('69');

        $appointment = Appointment::query()->create([
            'service_id' => $service->id,
            'technician_id' => $originalTechnician->id,
            'created_by' => $planner->id,
            'customer_first_name' => 'Alice',
            'customer_last_name' => 'Durand',
            'customer_phone' => '0600000000',
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'starts_at' => '2026-06-12 14:00:00',
            'duration_minutes' => 90,
            'ends_at' => '2026-06-12 15:30:00',
            'comment' => 'Ancien créneau',
        ]);

        $analysis = $this->actingAs($planner)
            ->postJson(route('planner.book.analyze'), [
                'replace_appointment_id' => $appointment->id,
            ])
            ->assertOk()
            ->json();

        $replacementEvent = collect($analysis['events'])->firstWhere('id', $appointment->id);

        expect($analysis['crm_appointment']['replace_appointment_id'])->toBe($appointment->id)
            ->and($replacementEvent)->not->toBeNull()
            ->and($replacementEvent['borderColor'])->toBe('#faff00')
            ->and($replacementEvent['extendedProps']['is_replacement_target'])->toBeTrue();

        $this->actingAs($planner)
            ->postJson(route('planner.book.appointments.store'), [
                'replace_appointment_id' => $appointment->id,
                'technician_id' => $replacementTechnician->id,
                'starts_at' => '2026-06-13 09:30:00',
                'duration_minutes' => 90,
                'comment' => 'Replacement confirmé',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Rendez-vous replacé.')
            ->assertJsonPath('appointment_id', $appointment->id);

        $appointment->refresh();

        expect(Appointment::query()->count())->toBe(1)
            ->and($appointment->technician_id)->toBe($replacementTechnician->id)
            ->and($appointment->starts_at->format('Y-m-d H:i:s'))->toBe('2026-06-13 09:30:00')
            ->and($appointment->comment)->toBe('Replacement confirmé');

        Mail::assertQueued(
            TechnicianAppointmentNotificationMail::class,
            fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'reassigned_to'
                && $mail->hasTo($replacementTechnician->email)
                && $mail->appointment->id === $appointment->id,
        );
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

it('places a coffrac appointment locally when the coffrac technician mapping is missing', function () {
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
                    'id' => 46,
                    'source' => 'Coffrac',
                    'status_name' => 'Prise de RDV',
                    'service_type' => Service::TYPE_COFFRAC,
                    'service_name' => 'Inspection Coffrac',
                    'customer_first_name' => 'Marc',
                    'customer_last_name' => 'SANS-TECH',
                    'phone' => '0600000046',
                    'address' => '12 Rue Nationale, 37000 Tours, France',
                    'department_code' => '37',
                    'latitude' => 47.3941,
                    'longitude' => 0.6848,
                ]],
            ]);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/46/placed') {
            return Http::response([
                'result' => true,
                'message' => 'Technicien Coffrac introuvable: le dossier reste en prise de RDV.',
                'data' => [
                    'id' => 46,
                    'source' => 'Coffrac',
                    'status_name' => 'Prise de RDV',
                    'service_type' => Service::TYPE_COFFRAC,
                    'service_name' => 'Inspection Coffrac',
                    'customer_first_name' => 'Marc',
                    'customer_last_name' => 'SANS-TECH',
                    'phone' => '0600000046',
                    'address' => '12 Rue Nationale, 37000 Tours, France',
                    'department_code' => '37',
                    'latitude' => 47.3941,
                    'longitude' => 0.6848,
                ],
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
        'first_name' => 'Lucas',
        'last_name' => 'TESTEUR',
        'email' => 'lucas.inconnu-coffrac@example.test',
        'latitude' => 47.39,
        'longitude' => 0.69,
    ]);
    $technician->services()->attach($service);
    app(CoffracAppointmentService::class)->sync();

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'crm_appointment_id' => 'coffrac-46',
            'technician_id' => $technician->id,
            'starts_at' => '2026-06-22 14:00:00',
            'duration_minutes' => 90,
            'comment' => 'Placement local malgré mapping Coffrac absent',
        ])
        ->assertCreated()
        ->assertJsonStructure(['appointment_id']);

    $appointment = Appointment::query()->firstOrFail();
    $externalRequest = ExternalAppointmentRequest::query()
        ->where('source', CoffracAppointmentService::SOURCE)
        ->where('external_reference', '46')
        ->firstOrFail();

    expect($appointment->external_source)->toBe(CoffracAppointmentService::SOURCE)
        ->and($appointment->external_reference)->toBe('46')
        ->and($externalRequest->status)->toBe(ExternalAppointmentRequest::STATUS_PENDING)
        ->and($externalRequest->appointment_id)->toBe($appointment->id)
        ->and($externalRequest->technician_email)->toBe('lucas.inconnu-coffrac@example.test')
        ->and(app(CoffracAppointmentService::class)->pending(15)->count())->toBe(0);

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/46/placed'
        && $request['technician_email'] === 'lucas.inconnu-coffrac@example.test'
        && $request['technician_name'] === 'Lucas TESTEUR');

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'created'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('does not rollback local coffrac placement when the remote api still rejects an unknown technician', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments/47/placed' => Http::response([
            'message' => 'Les données fournies ne sont pas valides.',
            'errors' => [
                'technician_email' => ['Aucun technicien Coffrac actif ne correspond à cet email.'],
            ],
        ], 422),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech.absent-coffrac@example.test',
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);
    $startsAt = now()->addDay()->setTime(9, 30);
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Coffrac',
        'customer_phone' => '0600000047',
        'address' => '1 Rue Test, 75001 Paris',
        'latitude' => 48.86,
        'longitude' => 2.35,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
        'external_source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '47',
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '47',
        'status' => ExternalAppointmentRequest::STATUS_PENDING,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'Prise de RDV',
        'service_type' => Service::TYPE_COFFRAC,
        'service_name' => 'Inspection Coffrac',
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Coffrac',
        'address' => '1 Rue Test, 75001 Paris',
        'latitude' => 48.86,
        'longitude' => 2.35,
        'fetched_at' => now(),
    ]);

    app(CoffracAppointmentService::class)->markPlaced($appointment, [
        'external_source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '47',
    ]);

    $externalRequest = ExternalAppointmentRequest::query()
        ->where('source', CoffracAppointmentService::SOURCE)
        ->where('external_reference', '47')
        ->firstOrFail();

    expect($externalRequest->status)->toBe(ExternalAppointmentRequest::STATUS_PENDING)
        ->and($externalRequest->appointment_id)->toBe($appointment->id)
        ->and($externalRequest->technician_email)->toBe('tech.absent-coffrac@example.test')
        ->and(app(CoffracAppointmentService::class)->pending(15)->count())->toBe(0);
});

it('links a placed appointment back to its lot appointment', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);
    Mail::fake();

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if ($request->method() === 'POST' && $request->url() === 'https://coffrac.test/api/techcalendar/appointments') {
            return Http::response([
                'result' => true,
                'message' => 'Dossier Coffrac créé depuis TechCalendar.',
                'data' => [
                    'id' => 9101,
                    'source' => 'Coffrac',
                    'status_name' => 'RDV attente visite',
                    'service_type' => Service::TYPE_AUDIT,
                    'service_name' => 'Audit interne',
                    'customer_first_name' => 'Client',
                    'customer_last_name' => 'Lot',
                    'customer_name' => 'Entreprise Lot',
                    'company_name' => 'Entreprise Lot',
                    'phone' => '0600000003',
                    'address' => '20 Place Bellecour, 69002 Lyon, France',
                    'address_line' => '20 Place Bellecour',
                    'postal_code' => '69002',
                    'city' => 'Lyon',
                    'department_code' => '69',
                    'latitude' => 45.7578,
                    'longitude' => 4.832,
                    'technician_email' => 'tech.lot@example.test',
                    'starts_at' => '2026-06-22T10:00:00+02:00',
                    'duration_minutes' => 120,
                    'comment' => 'Placement depuis lot',
                    'documents' => [],
                    'comments' => [],
                ],
            ], 201);
        }

        return Http::response(['message' => 'Unexpected request'], 500);
    });

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
        'email' => 'tech.lot@example.test',
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    $lot = Lot::query()->create([
        'name' => 'Lot à placer',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'delegataire' => 'Délégataire test',
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'customer_name' => 'Client Lot',
        'company_name' => 'Entreprise Lot',
        'site_name' => 'Site Bellecour',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
        'added_to_global_plus' => true,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
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
        ->and($lotAppointment->source)->toBe(CoffracAppointmentService::SOURCE)
        ->and($lotAppointment->external_reference)->toBe('9101')
        ->and($lot->status)->toBe(Lot::STATUS_IN_PROGRESS);

    $appointment = Appointment::query()->findOrFail((int) $lotAppointment->appointment_id);
    $externalRequest = ExternalAppointmentRequest::query()
        ->where('source', CoffracAppointmentService::SOURCE)
        ->where('external_reference', '9101')
        ->firstOrFail();

    expect($appointment->external_source)->toBe(CoffracAppointmentService::SOURCE)
        ->and($appointment->external_reference)->toBe('9101')
        ->and($externalRequest->appointment_id)->toBe($appointment->id);

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments'
        && $request['service_name'] === 'Audit interne'
        && $request['technician_email'] === 'tech.lot@example.test'
        && $request['delegataire'] === 'Délégataire test'
        && $request['lot_id'] === $lot->id
        && $request['lot_appointment_id'] === $lotAppointment->id
        && $request['global_plus'] === true);

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'created'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $lotAppointment->appointment_id,
    );
});

it('rolls back a physical lot appointment when coffrac does not confirm attente visite', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments' => Http::response([
            'result' => true,
            'message' => 'Dossier Coffrac créé depuis TechCalendar.',
            'data' => [
                'id' => 9201,
                'source' => 'Coffrac',
                'status_name' => 'Prise de RDV',
                'service_type' => Service::TYPE_AUDIT,
                'service_name' => 'Audit interne',
                'customer_name' => 'Entreprise Lot',
                'company_name' => 'Entreprise Lot',
                'phone' => '0600000003',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'address_line' => '20 Place Bellecour',
                'postal_code' => '69002',
                'city' => 'Lyon',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'technician_email' => null,
                'documents' => [],
                'comments' => [],
            ],
        ], 201),
    ]);

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
        'email' => 'tech.lot@example.test',
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    $lot = Lot::query()->create([
        'name' => 'Lot à placer',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'customer_name' => 'Client Lot',
        'company_name' => 'Entreprise Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 120,
            'comment' => 'Placement depuis lot',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lot_appointment_id']);

    expect(Appointment::query()->count())->toBe(0)
        ->and(ExternalAppointmentRequest::query()->where('external_reference', '9201')->exists())->toBeFalse()
        ->and($lotAppointment->refresh()->appointment_id)->toBeNull()
        ->and($lotAppointment->status)->toBe(LotAppointment::STATUS_PENDING)
        ->and($lot->refresh()->status)->toBe(Lot::STATUS_NOT_STARTED);
});

it('returns a readable coffrac html error when a physical lot appointment cannot be created', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments' => Http::response(
            '<html><body><h1>Server Error</h1><p>syntax error, unexpected token "&lt;" in Dossier.php:174</p></body></html>',
            500,
            ['Content-Type' => 'text/html'],
        ),
    ]);

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
        'email' => 'tech.html@example.test',
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    $lot = Lot::query()->create([
        'name' => 'Lot erreur Coffrac',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'service_id' => $service->id,
        'customer_name' => 'Client Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $response = $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 120,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lot_appointment_id']);

    expect($response->json('errors.lot_appointment_id.0'))
        ->toContain('HTTP 500')
        ->toContain('syntax error')
        ->and(Appointment::query()->count())->toBe(0)
        ->and($lotAppointment->refresh()->appointment_id)->toBeNull()
        ->and($lotAppointment->status)->toBe(LotAppointment::STATUS_PENDING);
});

it('uses the coffrac service alias when creating a physical appointment from a lot', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);
    Mail::fake();

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments' => Http::response([
            'result' => true,
            'message' => 'Dossier Coffrac créé depuis TechCalendar.',
            'data' => [
                'id' => 9102,
                'source' => 'Coffrac',
                'status_name' => 'RDV attente visite',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'BAR 145 TRAVAUX',
                'customer_name' => 'Entreprise Lot',
                'company_name' => 'Entreprise Lot',
                'phone' => '0600000003',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'address_line' => '20 Place Bellecour',
                'postal_code' => '69002',
                'city' => 'Lyon',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'technician_email' => 'tech.alias-lot@example.test',
                'starts_at' => '2026-06-22T10:00:00+02:00',
                'duration_minutes' => 120,
                'documents' => [],
                'comments' => [],
            ],
        ], 201),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'BAR TH 145 APRES TRAVAUX',
        'average_duration_minutes' => 120,
    ]);
    $globalAlias = ExternalServiceAlias::query()->create([
        'service_id' => $service->id,
        'source' => CoffracAppointmentService::SOURCE,
        'external_type' => Service::TYPE_COFFRAC,
        'external_name' => 'BAR 145 TRAVAUX',
        'normalized_external_type' => ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC),
        'normalized_external_name' => ExternalServiceAlias::normalizeValue('BAR 145 TRAVAUX'),
    ]);
    ExternalServiceAlias::query()->create([
        'service_id' => $service->id,
        'source' => CoffracAppointmentService::SOURCE,
        'external_type' => Service::TYPE_COFFRAC,
        'external_name' => 'BAR TH 145 APRES TRAVAUX',
        'normalized_external_type' => ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC),
        'normalized_external_name' => ExternalServiceAlias::normalizeValue('BAR TH 145 APRES TRAVAUX'),
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech.alias-lot@example.test',
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    $lot = Lot::query()->create([
        'name' => 'Lot alias',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'coffrac_service_alias_id' => $globalAlias->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot',
        'company_name' => 'Entreprise Lot',
        'customer_phone' => '0600000003',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 120,
        ])
        ->assertCreated();

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments'
        && $request['service_name'] === 'BAR 145 TRAVAUX'
        && $request['service_type'] === Service::TYPE_COFFRAC);
});

it('allows overriding the coffrac service alias for one physical lot appointment', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.mapbox.token' => null,
    ]);
    Mail::fake();

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments' => Http::response([
            'result' => true,
            'message' => 'Dossier Coffrac créé depuis TechCalendar.',
            'data' => [
                'id' => 9103,
                'source' => 'Coffrac',
                'status_name' => 'RDV attente visite',
                'service_type' => Service::TYPE_COFFRAC,
                'service_name' => 'BAR TH 145 APRES TRAVAUX',
                'customer_name' => 'Entreprise Override',
                'company_name' => 'Entreprise Override',
                'phone' => '0600000004',
                'address' => '20 Place Bellecour, 69002 Lyon, France',
                'address_line' => '20 Place Bellecour',
                'postal_code' => '69002',
                'city' => 'Lyon',
                'department_code' => '69',
                'latitude' => 45.7578,
                'longitude' => 4.832,
                'technician_email' => 'tech.override-lot@example.test',
                'starts_at' => '2026-06-22T10:00:00+02:00',
                'duration_minutes' => 120,
                'documents' => [],
                'comments' => [],
            ],
        ], 201),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'BAR TH 145 APRES TRAVAUX',
        'average_duration_minutes' => 120,
    ]);
    $globalAlias = ExternalServiceAlias::query()->create([
        'service_id' => $service->id,
        'source' => CoffracAppointmentService::SOURCE,
        'external_type' => Service::TYPE_COFFRAC,
        'external_name' => 'BAR 145 TRAVAUX',
        'normalized_external_type' => ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC),
        'normalized_external_name' => ExternalServiceAlias::normalizeValue('BAR 145 TRAVAUX'),
    ]);
    $overrideAlias = ExternalServiceAlias::query()->create([
        'service_id' => $service->id,
        'source' => CoffracAppointmentService::SOURCE,
        'external_type' => Service::TYPE_COFFRAC,
        'external_name' => 'BAR TH 145 APRES TRAVAUX',
        'normalized_external_type' => ExternalServiceAlias::normalizeValue(Service::TYPE_COFFRAC),
        'normalized_external_name' => ExternalServiceAlias::normalizeValue('BAR TH 145 APRES TRAVAUX'),
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech.override-lot@example.test',
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $technician->services()->attach($service);
    $lot = Lot::query()->create([
        'name' => 'Lot alias override',
        'type' => Lot::TYPE_FULL_CONTROL,
        'service_id' => $service->id,
        'coffrac_service_alias_id' => $globalAlias->id,
        'status' => Lot::STATUS_NOT_STARTED,
        'created_by' => $planner->id,
    ]);
    $lotAppointment = LotAppointment::query()->create([
        'lot_id' => $lot->id,
        'customer_name' => 'Client Lot Override',
        'company_name' => 'Entreprise Override',
        'customer_phone' => '0600000004',
        'address' => '20 Place Bellecour, 69002 Lyon, France',
        'postal_code' => '69002',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'status' => LotAppointment::STATUS_PENDING,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.store'), [
            'lot_appointment_id' => $lotAppointment->id,
            'lot_service_alias_id' => $overrideAlias->id,
            'technician_id' => $technician->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'duration_minutes' => 120,
        ])
        ->assertCreated();

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments'
        && $request['service_name'] === 'BAR TH 145 APRES TRAVAUX'
        && $request['service_type'] === Service::TYPE_COFFRAC);
});
