<?php

use App\Mail\MailTemplateMailable;
use App\Models\Appointment;
use App\Models\AppointmentMailDelivery;
use App\Models\MailSender;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\User;
use App\Services\GlobalMailConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

uses(RefreshDatabase::class);

it('renders a one shot appointment mail preview with appointment data', function () {
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $appointment = plannerMailAppointment($planner);

    $response = $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.mail.preview', $appointment), [
            'subject' => 'RDV {{ client_name }} - {{ appointment_date }}',
            'markdown_body' => "# Bonjour {{ client_name }}\n\nIntervention : {{ service_label }}\n\nAdresse : {{ address }}",
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'RDV Camille Martin - 15/08/2026');

    expect($response->json('html'))
        ->toContain('Bonjour Camille Martin')
        ->toContain('COFRAC - BAR TH 171')
        ->toContain('12 rue de la Paix, 75002 Paris');
});

it('queues a one shot appointment mail without changing the stored template', function () {
    Mail::fake();

    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $appointment = plannerMailAppointment($planner);
    $template = MailTemplate::query()->create([
        'name' => 'Confirmation RDV',
        'slug' => 'confirmation-rdv',
        'mail_sender_id' => MailSender::query()->firstOrFail()->id,
        'subject' => 'Template original {{ client_name }}',
        'markdown_body' => '# Original {{ client_name }}',
        'is_active' => true,
    ]);

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.mail.send', $appointment), [
            'recipient_email' => 'client@example.com',
            'subject' => 'Sujet modifié {{ client_name }}',
            'markdown_body' => '# Corps modifié {{ client_name }}',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Mail ajouté à la file d’envoi.');

    Mail::assertQueued(
        MailTemplateMailable::class,
        fn (MailTemplateMailable $mail): bool => $mail->hasTo('client@example.com')
            && $mail->renderedSubject === 'Sujet modifié Camille Martin'
            && str_contains($mail->renderedMarkdown, 'Corps modifié Camille Martin')
    );

    $template->refresh();

    expect($template->subject)->toBe('Template original {{ client_name }}')
        ->and($template->markdown_body)->toBe('# Original {{ client_name }}');
});

it('lets managers queue a one shot appointment mail from appointment details', function () {
    Mail::fake();

    $manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $appointment = plannerMailAppointment($manager);

    $this->actingAs($manager)
        ->postJson(route('planner.book.appointments.mail.send', $appointment), [
            'recipient_email' => 'client@example.com',
            'subject' => 'Sujet gerant {{ client_name }}',
            'markdown_body' => '# Corps gerant {{ client_name }}',
        ])
        ->assertOk();

    Mail::assertQueued(
        MailTemplateMailable::class,
        fn (MailTemplateMailable $mail): bool => $mail->hasTo('client@example.com')
            && $mail->renderedSubject === 'Sujet gerant Camille Martin'
            && str_contains($mail->renderedMarkdown, 'Corps gerant Camille Martin')
    );
});

it('keeps the selected template sender logo for one shot appointment previews and sends', function () {
    Mail::fake();
    Storage::fake('public');

    Storage::disk('public')->put('mail-sender-logos/logo.png', 'fake-logo');

    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $appointment = plannerMailAppointment($planner);
    $sender = MailSender::query()->create([
        'name' => 'Genius Contrôle',
        'mail_host' => 'ssl0.ovh.net',
        'mail_port' => 587,
        'mail_username' => 'contact@example.test',
        'mail_password' => 'secret-password',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'contact@example.test',
        'mail_from_name' => 'Genius Contrôle',
        'logo_path' => 'mail-sender-logos/logo.png',
        'is_active' => true,
    ]);
    $template = MailTemplate::query()->create([
        'name' => 'Confirmation logo',
        'slug' => 'confirmation-logo',
        'mail_sender_id' => $sender->id,
        'subject' => 'RDV {{ client_name }}',
        'markdown_body' => '# Bonjour {{ client_name }}',
        'is_active' => true,
    ]);

    $response = $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.mail.preview', $appointment), [
            'mail_template_id' => $template->id,
            'subject' => 'Sujet personnalisé {{ client_name }}',
            'markdown_body' => '# Corps personnalisé {{ client_name }}',
        ])
        ->assertOk();

    expect($response->json('html'))
        ->toContain('<img')
        ->toContain('/storage/mail-sender-logos/logo.png');

    $this->actingAs($planner)
        ->postJson(route('planner.book.appointments.mail.send', $appointment), [
            'mail_template_id' => $template->id,
            'recipient_email' => 'client@example.com',
            'subject' => 'Sujet personnalisé {{ client_name }}',
            'markdown_body' => '# Corps personnalisé {{ client_name }}',
        ])
        ->assertOk();

    Mail::assertQueued(
        MailTemplateMailable::class,
        fn (MailTemplateMailable $mail): bool => $mail->hasTo('client@example.com')
            && str_contains((string) $mail->logoUrl, '/storage/mail-sender-logos/logo.png')
            && $mail->senderFromAddress === 'contact@example.test'
    );
});

function plannerMailAppointment(User $planner): Appointment
{
    $technician = User::factory()->create([
        'first_name' => 'Lucas',
        'last_name' => 'Tech',
        'email' => 'tech@example.com',
        'role' => 2,
        'admin' => false,
    ]);
    $service = Service::query()->create([
        'type' => Service::TYPE_COFFRAC,
        'name' => 'BAR TH 171',
        'average_duration_minutes' => 75,
    ]);
    $startsAt = Carbon::parse('2026-08-15 09:30:00');

    return Appointment::query()->create([
        'service_id' => $service->id,
        'technician_id' => $technician->id,
        'created_by' => $planner->id,
        'customer_first_name' => 'Camille',
        'customer_last_name' => 'Martin',
        'customer_phone' => '0612345678',
        'address' => '12 rue de la Paix, 75002 Paris',
        'latitude' => 48.8686,
        'longitude' => 2.3306,
        'starts_at' => $startsAt,
        'duration_minutes' => 75,
        'ends_at' => $startsAt->copy()->addMinutes(75),
        'comment' => 'Prévoir un accès au local technique.',
        'status' => Appointment::STATUS_SCHEDULED,
        'external_payload' => [
            'customer_email' => 'camille@example.com',
            'company_name' => 'ACME Energie',
            'site_name' => 'Site Paris Centre',
        ],
    ]);
}

function trackedPlannerMail(): array
{
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => true, 'mail.default' => 'smtp', 'simulation.enabled' => false]);
    Mail::fake();
    Http::preventStrayRequests();
    app()->instance(GlobalMailConfiguration::class, Mockery::mock(GlobalMailConfiguration::class)->allows('refresh')->getMock());
    $actor = User::factory()->create(['role' => 1, 'admin' => false]);

    return [$actor, plannerMailAppointment($actor), ['recipient_email' => 'test@example.invalid', 'subject' => 'Confirmation {{ client_name }}', 'markdown_body' => 'Corps prive du mail']];
}

it('tracks a new planning confirmation without claiming reception or replaying it', function () {
    [$actor, $appointment, $payload] = trackedPlannerMail();
    $this->actingAs($actor)->postJson(route('planner.book.appointments.mail.send', $appointment), $payload)->assertOk()
        ->assertJsonPath('messages.0.status', 'queued')->assertDontSee('Corps prive');
    $delivery = AppointmentMailDelivery::sole();
    $mail = Mail::queued(MailTemplateMailable::class)->sole();
    expect($mail->appointmentMailDeliveryId)->toBe($delivery->id)->and($mail->tries)->toBe(1)->and($mail->afterCommit)->toBeTrue();
    $transport = Mockery::mock(SentMessage::class);
    $calls = 0;
    $send = function () use (&$calls, $transport) {
        $calls++;

        return $transport;
    };
    $service = app(App\Services\AppointmentMailDelivery::class);
    expect($service->deliver($delivery->id, $send))->toBe($transport);
    $service->deliver($delivery->id, $send);
    $mail->failed(new RuntimeException('late failure'));
    expect($calls)->toBe(1)->and($delivery->fresh()->status)->toBe('sent');
    $this->getJson(route('planner.book.appointments.mail.status', $appointment))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('messages.0.status', 'sent')->assertJsonPath('messages.0.pending', false)->assertDontSee('Corps prive');
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('distinguishes SMTP rejection, uncertainty, simulation and a disabled worker', function () {
    [$actor, $appointment, $payload] = trackedPlannerMail();
    $this->actingAs($actor)->postJson(route('planner.book.appointments.mail.send', $appointment), $payload)->assertOk();
    $delivery = AppointmentMailDelivery::sole();
    $service = app(App\Services\AppointmentMailDelivery::class);
    expect(fn () => $service->deliver($delivery->id, fn () => throw new UnexpectedResponseException('PRIVATE SMTP', 550)))->toThrow(RuntimeException::class);
    $this->getJson(route('planner.book.appointments.mail.status', $appointment))->assertOk()->assertJsonPath('messages.0.status', 'failed')->assertDontSee('PRIVATE SMTP');
    $this->postJson(route('planner.book.appointments.mail.send', $appointment), $payload)->assertOk();
    $second = AppointmentMailDelivery::latest('id')->first();
    expect(fn () => $service->deliver($second->id, fn () => throw new RuntimeException('timeout')))->toThrow(RuntimeException::class);
    expect($second->fresh()->status)->toBe('unknown');
    $this->postJson(route('planner.book.appointments.mail.send', $appointment), $payload)->assertOk();
    $third = AppointmentMailDelivery::latest('id')->first();
    config(['mail.delivery_enabled' => false]);
    $service->deliver($third->id, fn () => throw new LogicException('must not send'));
    expect($third->fresh()->status)->toBe('blocked');
    $this->postJson(route('planner.book.appointments.mail.send', $appointment), $payload)->assertOk()->assertJsonPath('messages.0.status', 'simulated');
    Mail::assertQueuedCount(3);
    Mail::assertNothingSent();
});

it('scopes bounded mail outcomes to the selected appointment and denies external and technician access', function () {
    [$actor, $appointment] = trackedPlannerMail();
    foreach (range(1, 12) as $number) {
        AppointmentMailDelivery::create(['appointment_id' => $appointment->id, 'author_id' => $actor->id,
            'recipient' => 'test@example.invalid', 'subject' => 'Sujet '.$number, 'status' => 'queued']);
    }
    $route = route('planner.book.appointments.mail.status', $appointment);
    $this->actingAs($actor)->getJson($route)->assertOk()->assertJsonCount(10, 'messages');
    $other = $appointment->replicate();
    $other->save();
    $this->getJson(route('planner.book.appointments.mail.status', $other))->assertOk()->assertJsonCount(0, 'messages');
    $this->actingAs(User::factory()->create(['role' => 2]))->getJson($route)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_EXTERNAL, 'portal_enabled' => true]))->getJson($route)->assertForbidden();
    auth()->logout();
    $this->getJson($route)->assertUnauthorized();
});
