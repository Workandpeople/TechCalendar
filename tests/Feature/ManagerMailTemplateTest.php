<?php

use App\Models\MailSender;
use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function managerMailTemplateUser(array $overrides = []): User
{
    return User::query()->create(array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Gerant',
        'email' => 'manager-mail@example.test',
        'password' => bcrypt('password'),
        'admin' => false,
        'role' => 0,
    ], $overrides));
}

function managerMailSender(array $overrides = []): MailSender
{
    return MailSender::query()->create(array_merge([
        'name' => 'Genius Contrôle',
        'mail_host' => 'ssl0.ovh.net',
        'mail_port' => 587,
        'mail_username' => 'contact@example.test',
        'mail_password' => 'secret-password',
        'mail_encryption' => 'tls',
        'mail_from_address' => 'contact@example.test',
        'mail_from_name' => 'Genius Contrôle',
        'mail_admin_email' => 'admin@example.test',
        'is_active' => true,
    ], $overrides));
}

it('renders the manager mail templates page for managers', function () {
    $manager = managerMailTemplateUser();
    $sender = managerMailSender();
    MailTemplate::query()->create([
        'name' => 'Confirmation RDV',
        'slug' => 'confirmation-rdv',
        'mail_sender_id' => $sender->id,
        'subject' => 'RDV {{ appointment_date }}',
        'markdown_body' => '# Bonjour {{ client_name }}',
        'is_active' => true,
    ]);

    $this->actingAs($manager)
        ->get(route('manager.mail-templates'))
        ->assertOk()
        ->assertSee('Templates de mails')
        ->assertSee('Confirmation RDV')
        ->assertSee('appointment_date');
});

it('blocks mail templates management for planning users', function () {
    $planning = managerMailTemplateUser([
        'email' => 'planning-mail@example.test',
        'role' => 1,
    ]);

    $this->actingAs($planning)
        ->get(route('manager.mail-templates'))
        ->assertForbidden();
});

it('creates updates and soft deletes a mail template', function () {
    $manager = managerMailTemplateUser();
    $sender = managerMailSender();

    $this->actingAs($manager)
        ->post(route('manager.mail-templates.store'), [
            'name' => 'Confirmation RDV',
            'mail_sender_id' => $sender->id,
            'subject' => 'RDV {{ appointment_date }}',
            'markdown_body' => '# Bonjour {{ client_name }}',
            'is_active' => '1',
        ])
        ->assertRedirect(route('manager.mail-templates'));

    $template = MailTemplate::query()->firstOrFail();

    expect($template->slug)->toBe('confirmation-rdv')
        ->and($template->is_active)->toBeTrue()
        ->and($template->created_by_user_id)->toBe($manager->id);

    $this->actingAs($manager)
        ->put(route('manager.mail-templates.update', $template), [
            'name' => 'Confirmation modifiée',
            'mail_sender_id' => $sender->id,
            'subject' => 'Sujet modifié',
            'markdown_body' => 'Corps modifié',
        ])
        ->assertRedirect(route('manager.mail-templates'));

    $template->refresh();

    expect($template->name)->toBe('Confirmation modifiée')
        ->and($template->slug)->toBe('confirmation-rdv')
        ->and($template->is_active)->toBeFalse();

    $this->actingAs($manager)
        ->delete(route('manager.mail-templates.destroy', $template))
        ->assertRedirect(route('manager.mail-templates'));

    $this->assertSoftDeleted('mail_templates', ['id' => $template->id]);
});

it('stores previews and removes a mail sender logo', function () {
    Storage::fake('public');

    $manager = managerMailTemplateUser();
    $logo = UploadedFile::fake()->createWithContent(
        'logo.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')
    );

    $this->actingAs($manager)
        ->post(route('manager.mail-templates.senders.store'), [
            'name' => 'Genius Contrôle',
            'mail_host' => 'ssl0.ovh.net',
            'mail_port' => 587,
            'mail_username' => 'contact@example.test',
            'mail_password' => 'secret-password',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'contact@example.test',
            'mail_from_name' => 'Genius Contrôle',
            'mail_admin_email' => 'admin@example.test',
            'logo' => $logo,
            'is_active' => '1',
        ])
        ->assertRedirect(route('manager.mail-templates'));

    $sender = MailSender::query()->latest('id')->firstOrFail();

    expect($sender->logo_path)->not->toBeNull()
        ->and($sender->logo_path)->toStartWith('mail-sender-logos/');

    Storage::disk('public')->assertExists($sender->logo_path);

    $template = MailTemplate::query()->create([
        'name' => 'Confirmation RDV',
        'slug' => 'confirmation-rdv',
        'mail_sender_id' => $sender->id,
        'subject' => 'RDV {{ appointment_date }}',
        'markdown_body' => '# Bonjour {{ client_name }}',
        'is_active' => true,
    ]);

    $response = $this->actingAs($manager)
        ->postJson(route('manager.mail-templates.preview'), [
            'mail_template_id' => $template->id,
            'subject' => 'RDV {{ client_name }}',
            'markdown_body' => '# Bonjour {{ client_name }}',
        ])
        ->assertOk();

    expect($response->json('html'))
        ->toContain('<img')
        ->toContain('/storage/mail-sender-logos/');

    $previousLogoPath = $sender->logo_path;

    $this->actingAs($manager)
        ->put(route('manager.mail-templates.senders.update', $sender), [
            'name' => 'Genius Contrôle',
            'mail_host' => 'ssl0.ovh.net',
            'mail_port' => 587,
            'mail_username' => 'contact@example.test',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'contact@example.test',
            'mail_from_name' => 'Genius Contrôle',
            'mail_admin_email' => 'admin@example.test',
            'remove_logo' => '1',
            'is_active' => '1',
        ])
        ->assertRedirect(route('manager.mail-templates'));

    $sender->refresh();

    expect($sender->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($previousLogoPath);
});

it('renders a real mailable preview with sample variables and escaped html', function () {
    $manager = managerMailTemplateUser();

    $response = $this->actingAs($manager)
        ->postJson(route('manager.mail-templates.preview'), [
            'subject' => 'RDV {{ client_name }}',
            'markdown_body' => "# Bonjour {{ client_name }}\n\n<script>alert('xss')</script>",
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'RDV Camille Martin');

    $html = $response->json('html');

    expect($html)->toContain('Bonjour Camille Martin')
        ->and($html)->not->toContain("<script>alert('xss')</script>")
        ->and($html)->toContain('&lt;script&gt;');
});
