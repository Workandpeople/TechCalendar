<?php

use App\Mail\MailTemplateMailable;
use App\Mail\UserCreatedCredentialsMail;
use App\Models\Appointment;
use App\Models\MailSender;
use App\Models\MailTemplate;
use App\Models\Service;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Admin\SmtpConnectionProbe;
use App\Services\ApplicationSettings;
use App\Services\Crm\DossierMail;
use App\Services\GlobalMailConfiguration;
use App\Services\MailSenderMailerConfigurator;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'mail.delivery_enabled' => true,
        'mail.contact_address' => 'obsolete-contact@example.invalid']);
    Cache::forget(ApplicationSettings::CACHE_KEY);
    Http::preventStrayRequests();
    $this->admin = User::factory()->create(['admin' => true, 'role' => 0]);
    app(ApplicationSettings::class)->update([
        'mail.from_address' => 'settings-contact@example.invalid', 'mail.from_name' => 'Parametres mail',
    ], $this->admin->id);
    $this->sender = MailSender::create(['name' => 'Obsolete sender', 'mail_host' => '127.0.0.1', 'mail_port' => 1,
        'mail_username' => 'obsolete@example.invalid', 'mail_password' => 'obsolete-password',
        'mail_from_address' => 'noreply@example.invalid', 'mail_from_name' => 'Obsolete', 'is_active' => false]);
    $this->template = MailTemplate::create(['name' => 'Modele actif', 'slug' => 'global-mail-test',
        'mail_sender_id' => $this->sender->id, 'subject' => 'Objet', 'markdown_body' => 'Message', 'is_active' => true]);
    $this->mock(MailSenderMailerConfigurator::class)->shouldNotReceive('configure');
});

afterEach(fn () => Http::assertNothingSent());

it('removes sender controls and legacy SMTP management from the v2 template page', function () {
    $response = $this->actingAs($this->admin)->get(route('manager.mail-templates'))->assertOk()
        ->assertSee('settings-contact@example.invalid')->assertSee('configuration des Paramètres')
        ->assertDontSee('name="mail_sender_id"', false)->assertDontSee('id="mail-sender-editor-modal"', false)
        ->assertDontSee('obsolete-password');
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent(), LIBXML_NONET);
    expect((new DOMXPath($dom))->query('//*[@data-mail-sender-create or @data-mail-sender-edit or @data-mail-sender-delete]')->length)->toBe(0);
});

it('creates and previews templates without requiring a sender and ignores stale form sender choices', function () {
    $this->actingAs($this->admin)->post(route('manager.mail-templates.store'), [
        'name' => 'Sans expediteur', 'subject' => 'Objet', 'markdown_body' => 'Contenu', 'is_active' => true,
        'mail_sender_id' => 'obsolete-untrusted-value',
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect(MailTemplate::where('name', 'Sans expediteur')->sole()->mail_sender_id)->toBeNull();
    $this->put(route('manager.mail-templates.update', $this->template), [
        'name' => 'Modele modifie', 'subject' => 'Objet modifie', 'markdown_body' => 'Contenu', 'is_active' => true,
        'mail_sender_id' => 999999,
    ])->assertSessionHasNoErrors();
    expect($this->template->fresh()->mail_sender_id)->toBe($this->sender->id);
    $this->postJson(route('manager.mail-templates.preview'), [
        'mail_template_id' => $this->template->id, 'mail_sender_id' => 999999,
        'subject' => 'Objet', 'markdown_body' => 'Contenu',
    ])->assertOk()->assertJsonPath('subject', 'Objet');
});

it('rejects obsolete v2 sender administration without deleting historical data', function () {
    $this->actingAs($this->admin)->post(route('manager.mail-templates.senders.store'), [])->assertStatus(410);
    $this->put(route('manager.mail-templates.senders.update', $this->sender), [])->assertStatus(410);
    $this->delete(route('manager.mail-templates.senders.destroy', $this->sender))->assertStatus(410);
    expect($this->sender->fresh()->trashed())->toBeFalse()->and($this->template->fresh()->mail_sender_id)->toBe($this->sender->id);
});

it('uses the global transport and identity even for mail serialized before the change', function () {
    config(['crm.v2_enabled' => false]);
    $queued = new MailTemplateMailable($this->template);
    $queued->from('noreply@example.invalid', 'Obsolete')->replyTo('obsolete@example.invalid');
    $serialized = serialize($queued);
    config(['crm.v2_enabled' => true]);
    $queued = unserialize($serialized);
    Mail::to('fixture@example.invalid')->send($queued);
    $messages = Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('settings-contact@example.invalid')
        ->and($messages->first()->getOriginalMessage()->getReplyTo()[0]->getAddress())->toBe('settings-contact@example.invalid')
        ->and($queued->senderId)->toBeNull()->and($queued->mailer)->toBe(config('mail.default'));
});

it('keeps delivery disabled for old queued sender choices as well', function () {
    config(['mail.delivery_enabled' => false]);
    (new AppServiceProvider(app()))->boot();
    $queued = new MailTemplateMailable($this->template);
    $queued->mailer = 'mail_sender_'.$this->sender->id;
    $queued->senderId = $this->sender->id;
    $queued->senderMailerName = $queued->mailer;
    Mail::to('fixture@example.invalid')->send($queued);
    expect(Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('also discards an obsolete transport on system emails already in the queue', function () {
    $queued = new UserCreatedCredentialsMail($this->admin, 'fictional-test-password');
    $queued->mailer = 'obsolete_smtp';
    $queued->from('obsolete@example.invalid');
    $queued = unserialize(serialize($queued));
    Mail::to('fixture@example.invalid')->send($queued);
    $messages = Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('settings-contact@example.invalid')
        ->and($queued->mailer)->toBe(config('mail.default'));
});

it('keeps active templates available in dossiers booking and tracking despite inactive or deleted legacy senders', function (bool $deleted) {
    Mail::fake();
    Queue::fake();
    if ($deleted) {
        $this->sender->delete();
    }
    expect(app(DossierMail::class)->templates()->pluck('id'))->toContain($this->template->id);
    $this->actingAs($this->admin)->get(route('planner.book'))->assertOk()
        ->assertViewHas('mailTemplates', fn ($rows) => $rows->contains('id', $this->template->id));
    $this->get(route('planner.tracking'))->assertOk()
        ->assertViewHas('mailTemplates', fn ($rows) => $rows->contains('id', $this->template->id));
    $technician = User::factory()->create(['role' => 2]);
    $service = Service::create(['name' => 'Prestation', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $appointment = Appointment::create(['service_id' => $service->id, 'technician_id' => $technician->id, 'created_by' => $this->admin->id,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => 'Rue Test',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60, 'status' => 'scheduled']);
    $this->postJson(route('planner.book.appointments.mail.preview', $appointment), [
        'mail_template_id' => $this->template->id, 'subject' => 'Objet', 'markdown_body' => 'Message',
    ])->assertOk();
    $this->template->update(['is_active' => false]);
    expect(app(DossierMail::class)->templates()->pluck('id'))->not->toContain($this->template->id);
})->with([false, true]);

it('reloads saved global mail settings and clears stale transports between worker jobs', function () {
    config(['mail.mailers.smtp' => ['transport' => 'array']]);
    app(ApplicationSettings::class)->update(['mail.mailer' => 'smtp', 'mail.username' => 'old@example.invalid'], $this->admin->id);
    $old = Mail::mailer('smtp');
    app(ApplicationSettings::class)->update(['mail.username' => 'current@example.invalid'], $this->admin->id);
    config(['mail.mailers.smtp.username' => 'old@example.invalid']);
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    Event::dispatch(new JobProcessing('database', $job));
    expect(config('mail.mailers.smtp.username'))->toBe('current@example.invalid')
        ->and(Mail::mailer('smtp'))->not->toBe($old);
});

it('never changes simulation transport or immutable delivery gates while refreshing settings', function () {
    config(['simulation.enabled' => true, 'mail.default' => 'log', 'mail.delivery_enabled' => false,
        'services.smsfactor.enabled' => false, 'services.smsfactor.mode' => 'simulate']);
    app(GlobalMailConfiguration::class)->refresh();
    expect(config('mail.default'))->toBe('log')->and(config('mail.delivery_enabled'))->toBeFalse()
        ->and(config('services.smsfactor.enabled'))->toBeFalse()->and(config('services.smsfactor.mode'))->toBe('simulate');
});

it('tests only the global smtp configuration in v2 even when an old sender id is submitted', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'global.example.invalid']);
    $this->mock(SmtpConnectionProbe::class)->shouldReceive('check')->once()
        ->with(Mockery::on(fn ($smtp) => $smtp['host'] === 'global.example.invalid'))->andReturn(true);
    $this->actingAs($this->admin)->postJson(route('admin.settings.diagnostics'), ['check' => 'mail', 'mail_sender_id' => $this->sender->id])
        ->assertOk()->assertJsonPath('status', 'ok');
});
