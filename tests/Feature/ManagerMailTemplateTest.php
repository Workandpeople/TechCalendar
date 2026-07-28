<?php

use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('renders the manager mail templates page for managers', function () {
    $manager = managerMailTemplateUser();
    MailTemplate::query()->create([
        'name' => 'Confirmation RDV',
        'slug' => 'confirmation-rdv',
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

    $this->actingAs($manager)
        ->post(route('manager.mail-templates.store'), [
            'name' => 'Confirmation RDV',
            'slug' => '',
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
            'slug' => 'confirmation-modifiee',
            'subject' => 'Sujet modifié',
            'markdown_body' => 'Corps modifié',
        ])
        ->assertRedirect(route('manager.mail-templates'));

    $template->refresh();

    expect($template->name)->toBe('Confirmation modifiée')
        ->and($template->slug)->toBe('confirmation-modifiee')
        ->and($template->is_active)->toBeFalse();

    $this->actingAs($manager)
        ->delete(route('manager.mail-templates.destroy', $template))
        ->assertRedirect(route('manager.mail-templates'));

    $this->assertSoftDeleted('mail_templates', ['id' => $template->id]);
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
