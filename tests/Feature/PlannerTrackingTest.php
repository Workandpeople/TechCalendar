<?php

use App\Jobs\SyncCoffracAppointmentsJob;
use App\Models\Appointment;
use App\Mail\TechnicianAppointmentNotificationMail;
use App\Models\Department;
use App\Models\ExternalApiSync;
use App\Models\ExternalAppointmentRequest;
use App\Models\MailSender;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('renders the appointment mail composer on planner and manager tracking pages', function () {
    MailTemplate::query()->create([
        'name' => 'Confirmation RDV',
        'slug' => 'confirmation-rdv',
        'mail_sender_id' => MailSender::query()->firstOrFail()->id,
        'subject' => 'RDV {{ client_name }}',
        'markdown_body' => '# Bonjour {{ client_name }}',
        'is_active' => true,
    ]);

    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $manager = User::factory()->create(['role' => 0, 'admin' => false]);

    $this->actingAs($planner)
        ->get(route('planner.tracking'))
        ->assertOk()
        ->assertDontSee('Recherche de RDV')
        ->assertDontSee('id="tracking-search-form"', false)
        ->assertSee('Envoyer le mail')
        ->assertSee('tracking-mail-form')
        ->assertSee('Confirmation RDV');

    $this->actingAs($manager)
        ->get(route('manager.appointments'))
        ->assertOk()
        ->assertDontSee('Recherche de RDV')
        ->assertDontSee('id="tracking-search-form"', false)
        ->assertSee('Envoyer le mail')
        ->assertSee('tracking-mail-form')
        ->assertSee('Confirmation RDV');
});

it('renders the dedicated appointment replacement pages for planners and managers', function () {
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $manager = User::factory()->create(['role' => 0, 'admin' => false]);

    $this->actingAs($planner)
        ->get(route('planner.appointments.modify'))
        ->assertOk()
        ->assertSee('Modifier un RDV')
        ->assertSee('Recherche de RDV à modifier')
        ->assertSee('booking-replacement-search-form')
        ->assertSee('data-booking-replacement-start', false)
        ->assertSee('const bookingMode = "replace";', false);

    $this->actingAs($manager)
        ->get(route('manager.appointments.modify'))
        ->assertOk()
        ->assertSee('Modifier un RDV')
        ->assertSee('Recherche de RDV à modifier')
        ->assertSee('booking-replacement-search-form')
        ->assertSee('data-booking-replacement-start', false)
        ->assertSee(str_replace('/', '\/', route('manager.appointments.search')), false);
});

it('refreshes placed coffrac appointments from the tracking page', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);
    Queue::fake();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    $this->actingAs($planner)
        ->get(route('planner.tracking'))
        ->assertOk()
        ->assertSee('tracking-coffrac-placed-refresh')
        ->assertSee(route('planner.tracking.coffrac.placed.refresh'), false);

    $this->actingAs($planner)
        ->postJson(route('planner.tracking.coffrac.placed.refresh'))
        ->assertOk()
        ->assertJsonPath('sync_queued', true);

    Queue::assertPushed(SyncCoffracAppointmentsJob::class, fn (SyncCoffracAppointmentsJob $job): bool => $job->incremental === false
        && $job->status === \App\Services\CoffracAppointmentService::REMOTE_STATUS_PLACED);

    $this->assertDatabaseHas('external_api_syncs', [
        'source' => 'coffrac',
        'state' => ExternalApiSync::STATE_SYNCING,
    ]);
});

it('disables placed coffrac refresh while another coffrac sync is running', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);
    Queue::fake();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);

    ExternalApiSync::query()->create([
        'source' => 'coffrac',
        'state' => ExternalApiSync::STATE_SYNCING,
        'message' => 'Synchronisation Coffrac en cours.',
        'metadata' => [
            'progress' => 42,
            'stage' => 'Synchronisation locale Coffrac 12/30...',
        ],
        'last_started_at' => now(),
    ]);

    $this->actingAs($planner)
        ->get(route('planner.tracking'))
        ->assertOk()
        ->assertSee('Synchronisation en cours...')
        ->assertSee('disabled', false);

    $this->actingAs($planner)
        ->getJson(route('planner.tracking.coffrac.placed.status'))
        ->assertOk()
        ->assertJsonPath('coffrac_api_status.state', 'syncing')
        ->assertJsonPath('coffrac_api_status.progress', 42);

    $this->actingAs($planner)
        ->postJson(route('planner.tracking.coffrac.placed.refresh'))
        ->assertStatus(409)
        ->assertJsonPath('sync_queued', false)
        ->assertJsonPath('coffrac_api_status.state', 'syncing');

    Queue::assertNotPushed(SyncCoffracAppointmentsJob::class);
});

it('shows the placed coffrac refresh action on the manager appointments page', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);
    Queue::fake();

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.appointments'))
        ->assertOk()
        ->assertSee('tracking-coffrac-placed-refresh')
        ->assertSee(route('manager.appointments.coffrac.placed.refresh'), false);

    $this->actingAs($manager)
        ->postJson(route('manager.appointments.coffrac.placed.refresh'))
        ->assertOk()
        ->assertJsonPath('sync_queued', true);

    Queue::assertPushed(SyncCoffracAppointmentsJob::class, fn (SyncCoffracAppointmentsJob $job): bool => $job->status === \App\Services\CoffracAppointmentService::REMOTE_STATUS_PLACED);
});

it('reassigns a tracking appointment to another compatible technician', function () {
    Mail::fake();

    $manager = User::factory()->create([
        'role' => 0,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit interne',
        'average_duration_minutes' => 120,
    ]);
    Department::query()->updateOrCreate(['code' => '01'], ['name' => 'Ain']);
    Department::query()->updateOrCreate(['code' => '69'], ['name' => 'Rhône']);

    $oldTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'latitude' => 45.764,
        'longitude' => 4.8357,
    ]);
    $newTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'address' => '10 Rue de la Barre, Lyon',
        'latitude' => 45.7597,
        'longitude' => 4.8342,
    ]);
    $oldTechnician->services()->attach($service);
    $newTechnician->services()->attach($service);
    $newTechnician->departments()->attach(['69', '01']);

    $startsAt = Carbon::parse('2026-06-12 10:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $oldTechnician->id,
        'created_by' => $manager->id,
        'customer_first_name' => 'Claire',
        'customer_last_name' => 'Martin',
        'customer_phone' => '0600000001',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'starts_at' => $startsAt,
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addMinutes(120),
    ]);

    foreach ([$oldTechnician, $newTechnician] as $technician) {
        TechnicianDailyRouteMetric::query()->create([
            'technician_id' => $technician->id,
            'service_date' => $startsAt->toDateString(),
            'appointment_count' => 1,
            'drive_distance_km' => 12.5,
            'drive_duration_minutes' => 24,
            'overtime_minutes' => 0,
            'calculation_source' => 'haversine',
            'route_hash' => hash('sha256', 'stale-'.$technician->id),
            'calculated_at' => now(),
        ]);
    }

    $this->actingAs($manager)
        ->patchJson(route('planner.tracking.appointments.technician', $appointment), [
            'technician_id' => $newTechnician->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Rendez-vous réaffecté.')
        ->assertJsonPath('technician.id', $newTechnician->id)
        ->assertJsonPath('technician.name', $newTechnician->full_name_with_departments);

    expect($appointment->refresh()->technician_id)->toBe($newTechnician->id)
        ->and(TechnicianDailyRouteMetric::query()
            ->whereDate('service_date', $startsAt->toDateString())
            ->whereIn('technician_id', [$oldTechnician->id, $newTechnician->id])
            ->exists())->toBeFalse();

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'reassigned_from'
            && $mail->hasTo($oldTechnician->email)
            && $mail->appointment->id === $appointment->id,
    );
    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'reassigned_to'
            && $mail->hasTo($newTechnician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('updates tracking appointment date duration and address', function () {
    Mail::fake();
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
        'services.coffrac.ignored_references' => [],
    ]);
    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments/4257/address' => Http::response([
            'result' => true,
            'message' => 'Adresse corrigée.',
        ]),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
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

    $startsAt = Carbon::parse('2026-06-18 09:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'Modif',
        'customer_phone' => '0600000020',
        'address' => '1 Rue Ancienne, 69001 Lyon',
        'latitude' => 45.767,
        'longitude' => 4.833,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
        'external_source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '4257',
        'external_payload' => ['id' => 4257],
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => CoffracAppointmentService::SOURCE,
        'external_reference' => '4257',
        'status' => ExternalAppointmentRequest::STATUS_PLACED,
        'appointment_id' => $appointment->id,
        'source_label' => 'Coffrac',
        'remote_status_name' => 'RDV attente visite',
        'customer_first_name' => 'Nina',
        'customer_last_name' => 'Modif',
        'phone' => '0600000020',
        'address' => '1 Rue Ancienne, 69001 Lyon',
        'address_line' => '1 Rue Ancienne',
        'postal_code' => '69001',
        'city' => 'Lyon',
        'department_code' => '69',
        'latitude' => 45.767,
        'longitude' => 4.833,
        'payload' => ['id' => 4257],
        'fetched_at' => now(),
    ]);

    TechnicianDailyRouteMetric::query()->create([
        'technician_id' => $technician->id,
        'service_date' => $startsAt->toDateString(),
        'appointment_count' => 1,
        'drive_distance_km' => 12.5,
        'drive_duration_minutes' => 24,
        'overtime_minutes' => 0,
        'calculation_source' => 'haversine',
        'route_hash' => hash('sha256', 'stale-details'),
        'calculated_at' => now(),
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.details', $appointment), [
            'starts_at' => '2026-06-19 14:30:00',
            'duration_minutes' => 135,
            'address' => '20 Place Bellecour, 69002 Lyon, France',
            'latitude' => 45.7578,
            'longitude' => 4.832,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Rendez-vous mis à jour.')
        ->assertJsonPath('appointment.duration_minutes', 135)
        ->assertJsonPath('appointment.postal_code', '69002')
        ->assertJsonPath('appointment.city', 'Lyon');

    $appointment->refresh();

    expect($appointment->starts_at->format('Y-m-d H:i:s'))->toBe('2026-06-19 14:30:00')
        ->and($appointment->ends_at->format('Y-m-d H:i:s'))->toBe('2026-06-19 16:45:00')
        ->and($appointment->address)->toBe('20 Place Bellecour, 69002 Lyon, France')
        ->and(TechnicianDailyRouteMetric::query()
            ->where('technician_id', $technician->id)
            ->whereDate('service_date', '2026-06-18')
            ->exists())->toBeFalse();

    $stored = ExternalAppointmentRequest::query()
        ->where('source', CoffracAppointmentService::SOURCE)
        ->where('external_reference', '4257')
        ->firstOrFail();

    expect($stored->address)->toBe('20 Place Bellecour, 69002 Lyon, France')
        ->and($stored->address_line)->toBe('20 Place Bellecour')
        ->and($stored->latitude)->toBe(45.7578)
        ->and($stored->longitude)->toBe(4.832);

    Http::assertSentCount(1);
    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/4257/address'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['address'] === '20 Place Bellecour, 69002 Lyon, France'
        && $request['address_line'] === '20 Place Bellecour'
        && $request['postal_code'] === '69002'
        && $request['city'] === 'Lyon'
        && $request['latitude'] === 45.7578
        && $request['longitude'] === 4.832
        && $request['techcalendar_appointment_id'] === $appointment->id);

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'details_updated'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('rejects tracking appointment update that overlaps another appointment', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle',
        'average_duration_minutes' => 90,
    ]);

    $startsAt = Carbon::parse('2026-06-20 09:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Source',
        'customer_phone' => '0600000021',
        'address' => '1 Rue Source, Lyon',
        'latitude' => 45.76,
        'longitude' => 4.83,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addMinutes(60),
    ]);
    Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Existant',
        'customer_phone' => '0600000022',
        'address' => '2 Rue Existant, Lyon',
        'latitude' => 45.77,
        'longitude' => 4.84,
        'starts_at' => Carbon::parse('2026-06-20 14:00:00'),
        'duration_minutes' => 90,
        'ends_at' => Carbon::parse('2026-06-20 15:30:00'),
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.details', $appointment), [
            'starts_at' => '2026-06-20 13:30:00',
            'duration_minutes' => 90,
            'address' => '1 Rue Source, Lyon',
            'latitude' => 45.76,
            'longitude' => 4.83,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');

    expect($appointment->refresh()->starts_at->format('H:i:s'))->toBe('09:00:00');
});

it('notifies the technician when a tracking appointment comment is updated', function () {
    Mail::fake();

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_MAR,
        'name' => 'Contrôle MAR',
        'average_duration_minutes' => 60,
    ]);

    $startsAt = Carbon::parse('2026-06-21 09:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Cycle',
        'customer_phone' => '0600000023',
        'address' => '1 Rue Cycle, Lyon',
        'latitude' => 45.76,
        'longitude' => 4.83,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addMinutes(60),
        'comment' => 'Commentaire initial',
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.comment', $appointment), [
            'comment' => 'Commentaire modifié',
        ])
        ->assertOk();

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'comment_updated'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});

it('rejects reassignment to a technician that does not cover the appointment service', function () {
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
    $oldTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $incompatibleTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $oldTechnician->services()->attach($service);
    $incompatibleTechnician->services()->attach($otherService);

    $startsAt = Carbon::parse('2026-06-12 14:00:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $oldTechnician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Paul',
        'customer_last_name' => 'Client',
        'customer_phone' => '0600000002',
        'address' => '1 Rue Nationale, Villeurbanne',
        'latitude' => 45.7719,
        'longitude' => 4.8902,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
    ]);

    $this->actingAs($planner)
        ->patchJson(route('planner.tracking.appointments.technician', $appointment), [
            'technician_id' => $incompatibleTechnician->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('technician_id');

    expect($appointment->refresh()->technician_id)->toBe($oldTechnician->id);
});

it('filters tracking events by service', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $targetService = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle cible',
        'average_duration_minutes' => 90,
    ]);
    $otherService = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit hors filtre',
        'average_duration_minutes' => 120,
    ]);

    $startsAt = Carbon::parse('2026-06-15 09:00:00');
    $targetAppointment = Appointment::query()->create([
        'service_id' => $targetService->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Alice',
        'customer_last_name' => 'Filtre',
        'customer_phone' => '0600000010',
        'address' => '1 Rue de la Paix, Paris',
        'latitude' => 48.8686,
        'longitude' => 2.3305,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
    ]);
    Appointment::query()->create([
        'service_id' => $otherService->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Bob',
        'customer_last_name' => 'Masque',
        'customer_phone' => '0600000011',
        'address' => '2 Rue de la Paix, Paris',
        'latitude' => 48.869,
        'longitude' => 2.331,
        'starts_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addHours(5),
    ]);

    $events = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-15 00:00:00',
            'end' => '2026-06-16 00:00:00',
            'service_id' => $targetService->id,
        ])
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe($targetAppointment->id)
        ->and($events[0]['extendedProps']['service_id'])->toBe($targetService->id);
});

it('filters tracking events by appointment status', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_MAR,
        'name' => 'Contrôle MAR',
        'average_duration_minutes' => 60,
    ]);

    $startsAt = Carbon::parse('2026-06-16 10:00:00');
    $activeAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Actif',
        'customer_phone' => '0600000012',
        'address' => '3 Rue Nationale, Lyon',
        'latitude' => 45.764,
        'longitude' => 4.8357,
        'starts_at' => $startsAt,
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addMinutes(60),
    ]);
    $problemAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Client',
        'customer_last_name' => 'Problème',
        'customer_phone' => '0600000013',
        'address' => '4 Rue Nationale, Lyon',
        'latitude' => 45.765,
        'longitude' => 4.836,
        'starts_at' => $startsAt->copy()->addHours(2),
        'duration_minutes' => 60,
        'ends_at' => $startsAt->copy()->addHours(3),
        'status' => Appointment::STATUS_PROBLEM,
        'problem_reported_at' => now(),
    ]);

    $activeEvents = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-16 00:00:00',
            'end' => '2026-06-17 00:00:00',
            'appointment_status' => 'active',
        ])
        ->assertOk()
        ->json('events');

    $problemEvents = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-16 00:00:00',
            'end' => '2026-06-17 00:00:00',
            'appointment_status' => 'problem',
        ])
        ->assertOk()
        ->json('events');

    expect($activeEvents)->toHaveCount(1)
        ->and($activeEvents[0]['id'])->toBe($activeAppointment->id)
        ->and($activeEvents[0]['extendedProps']['status'])->toBe(Appointment::STATUS_SCHEDULED)
        ->and($problemEvents)->toHaveCount(1)
        ->and($problemEvents[0]['id'])->toBe($problemAppointment->id)
        ->and($problemEvents[0]['extendedProps']['status'])->toBe(Appointment::STATUS_PROBLEM);
});

it('searches tracking appointments and returns the modal event payload', function () {
    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'first_name' => 'Paul',
        'last_name' => 'Tech',
        'address' => '10 Rue Centrale, 69001 Lyon, France',
        'latitude' => 45.767,
        'longitude' => 4.834,
    ]);
    $otherTechnician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Contrôle cible',
        'average_duration_minutes' => 90,
    ]);
    $otherService = Service::query()->create([
        'type' => Service::TYPE_AUDIT,
        'name' => 'Audit hors filtre',
        'average_duration_minutes' => 120,
    ]);

    $startsAt = Carbon::parse('2026-06-20 14:00:00');
    $targetAppointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Alice',
        'customer_last_name' => 'Durand',
        'customer_phone' => '06 11 22 33 44',
        'address' => '12 Rue Victor Hugo, 69002 Lyon, France',
        'latitude' => 45.759,
        'longitude' => 4.832,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
        'external_reference' => 'COF-123',
        'comment' => 'Prévenir le gardien.',
    ]);
    Appointment::query()->create([
        'service_id' => $otherService->id,
        'technician_id' => $otherTechnician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Bob',
        'customer_last_name' => 'Masque',
        'customer_phone' => '0600000000',
        'address' => '1 Rue Masquée, Paris',
        'latitude' => 48.8566,
        'longitude' => 2.3522,
        'starts_at' => $startsAt,
        'duration_minutes' => 120,
        'ends_at' => $startsAt->copy()->addMinutes(120),
    ]);

    $appointments = $this->actingAs($planner)
        ->postJson(route('planner.tracking.search'), [
            'q' => 'Alice Durand',
            'technician_ids' => [$technician->id],
            'date_from' => '2026-06-20',
            'date_to' => '2026-06-20',
            'service_id' => $service->id,
            'appointment_status' => 'all',
        ])
        ->assertOk()
        ->json('appointments');

    expect($appointments)->toHaveCount(1)
        ->and($appointments[0]['id'])->toBe($targetAppointment->id)
        ->and($appointments[0]['extendedProps']['customer_name'])->toBe('Alice Durand')
        ->and($appointments[0]['extendedProps']['technician_name'])->toContain('Paul Tech')
        ->and($appointments[0]['extendedProps']['service_label'])->toBe('COFFRAC - Contrôle cible')
        ->and($appointments[0]['extendedProps']['postal_code'])->toBe('69002')
        ->and($appointments[0]['extendedProps']['city'])->toBe('Lyon');
});

it('includes coffrac documents from stored external requests in tracking events', function () {
    config(['services.coffrac.api_url' => 'https://coffrac.test/api']);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
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
        'external_source' => 'coffrac',
        'external_reference' => 'doc-44',
        'external_payload' => ['id' => 'doc-44'],
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => 'doc-44',
        'status' => ExternalAppointmentRequest::STATUS_PLACED,
        'appointment_id' => $appointment->id,
        'documents' => [[
            'id' => 9,
            'scope' => 'dossier',
            'name' => 'Avis de passage',
            'path' => 'avis.pdf',
        ]],
    ]);

    $events = $this->actingAs($planner)
        ->postJson(route('planner.tracking.events'), [
            'technician_ids' => [$technician->id],
            'start' => '2026-06-18 00:00:00',
            'end' => '2026-06-19 00:00:00',
        ])
        ->assertOk()
        ->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['extendedProps']['documents'][0]['name'])->toBe('Avis de passage')
        ->and($events[0]['extendedProps']['documents'][0]['url'])->toBe('https://coffrac.test/documents/avis.pdf');
});

it('refreshes documents for a placed coffrac appointment from tracking detail', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);

    Http::fake([
        'https://coffrac.test/api/techcalendar/appointments*' => Http::response([
            'result' => true,
            'data' => [[
                'id' => 'doc-44',
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
                    'name' => 'Nouveau document Coffrac',
                    'url' => 'https://coffrac.test/documents/nouveau.pdf',
                ]],
            ]],
            'fetched_count' => 1,
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
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);
    $technician->services()->attach($service);

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
        'external_source' => 'coffrac',
        'external_reference' => 'doc-44',
        'external_payload' => ['id' => 'doc-44', 'documents' => [['name' => 'Ancien document']]],
    ]);
    ExternalAppointmentRequest::query()->create([
        'source' => 'coffrac',
        'external_reference' => 'doc-44',
        'status' => ExternalAppointmentRequest::STATUS_PLACED,
        'appointment_id' => $appointment->id,
        'documents' => [['name' => 'Ancien document']],
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.tracking.appointments.coffrac.refresh', $appointment))
        ->assertOk()
        ->assertJsonPath('documents.0.name', 'Nouveau document Coffrac')
        ->assertJsonPath('documents.0.url', 'https://coffrac.test/documents/nouveau.pdf');

    expect($appointment->refresh()->external_payload['documents'][0]['name'])->toBe('Nouveau document Coffrac');
});

it('validates coffrac problem details before reporting an appointment problem', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
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
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);

    $startsAt = Carbon::parse('2026-06-22 10:30:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Claire',
        'customer_last_name' => 'DUPONT',
        'customer_phone' => '0600000044',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
        'comment' => 'Commentaire initial',
        'external_source' => 'coffrac',
        'external_reference' => '44',
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.tracking.appointments.problem', $appointment), [
            'comment' => 'Client à rappeler.',
            'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recall_date', 'recall_time']);

    expect($appointment->refresh()->status)->toBe(Appointment::STATUS_SCHEDULED)
        ->and($appointment->problem_reported_at)->toBeNull();

    Http::assertSentCount(1);
});

it('reports a coffrac appointment problem and moves it to probleme rendez-vous', function () {
    config([
        'services.coffrac.api_url' => 'https://coffrac.test/api',
        'services.coffrac.api_token' => 'secret-token',
    ]);
    Mail::fake();

    Http::fake([
        'https://coffrac.test/api/techcalendar/problem-types' => Http::response([
            'result' => true,
            'data' => [
                ['value' => CoffracAppointmentService::PROBLEM_TYPE_RENVOI_CLIENT, 'label' => 'Renvoi client', 'requires_recall' => false],
                ['value' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK, 'label' => 'Demande de rappel', 'requires_recall' => true],
                ['value' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT, 'label' => 'Problème document', 'requires_recall' => false],
            ],
        ]),
        'https://coffrac.test/api/techcalendar/appointments/44/problem' => Http::response([
            'result' => true,
            'message' => 'Rendez-vous basculé en problème.',
        ]),
    ]);

    $planner = User::factory()->create([
        'role' => 1,
        'admin' => false,
    ]);
    $technician = User::factory()->create([
        'role' => 2,
        'admin' => false,
        'email' => 'tech.coffrac@example.test',
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'Inspection Coffrac',
        'average_duration_minutes' => 90,
    ]);

    $startsAt = Carbon::parse('2026-06-22 10:30:00');
    $appointment = Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Claire',
        'customer_last_name' => 'DUPONT',
        'customer_phone' => '0600000044',
        'address' => '20 Place Bellecour, 69002 Lyon',
        'latitude' => 45.7578,
        'longitude' => 4.832,
        'starts_at' => $startsAt,
        'duration_minutes' => 90,
        'ends_at' => $startsAt->copy()->addMinutes(90),
        'comment' => 'Commentaire initial',
        'external_source' => 'coffrac',
        'external_reference' => '44',
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.tracking.appointments.problem', $appointment), [
            'comment' => 'Client absent au rendez-vous, à retraiter côté Coffrac.',
            'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Problème RDV déclaré.')
        ->assertJsonPath('status', Appointment::STATUS_PROBLEM);

    $appointment->refresh();

    expect($appointment->status)->toBe(Appointment::STATUS_PROBLEM)
        ->and($appointment->problem_reported_at)->not->toBeNull()
        ->and($appointment->comment)->toBe('Client absent au rendez-vous, à retraiter côté Coffrac.');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://coffrac.test/api/techcalendar/appointments/44/problem'
        && $request['comment'] === 'Client absent au rendez-vous, à retraiter côté Coffrac.'
        && $request['problem_type'] === CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT);

    Mail::assertQueued(
        TechnicianAppointmentNotificationMail::class,
        fn (TechnicianAppointmentNotificationMail $mail): bool => $mail->eventType === 'problem_reported'
            && $mail->hasTo($technician->email)
            && $mail->appointment->id === $appointment->id,
    );
});
