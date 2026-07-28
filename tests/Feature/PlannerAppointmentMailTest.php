<?php

use App\Mail\MailTemplateMailable;
use App\Models\Appointment;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
        ->toContain('COFFRAC - BAR TH 171')
        ->toContain('12 rue de la Paix, 75002 Paris');
});

it('queues a one shot appointment mail without changing the stored template', function () {
    Mail::fake();

    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $appointment = plannerMailAppointment($planner);
    $template = MailTemplate::query()->create([
        'name' => 'Confirmation RDV',
        'slug' => 'confirmation-rdv',
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

it('keeps the selected template logo for one shot appointment previews and sends', function () {
    Mail::fake();
    Storage::fake('public');

    Storage::disk('public')->put('mail-template-logos/logo.png', 'fake-logo');

    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $appointment = plannerMailAppointment($planner);
    $template = MailTemplate::query()->create([
        'name' => 'Confirmation logo',
        'slug' => 'confirmation-logo',
        'subject' => 'RDV {{ client_name }}',
        'markdown_body' => '# Bonjour {{ client_name }}',
        'logo_path' => 'mail-template-logos/logo.png',
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
        ->toContain('/storage/mail-template-logos/logo.png');

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
            && str_contains((string) $mail->logoUrl, '/storage/mail-template-logos/logo.png')
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
