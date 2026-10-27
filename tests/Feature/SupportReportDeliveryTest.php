<?php

use App\Services\GlobalMailConfiguration;
use App\Services\Support\SupportReportDelivery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'mail.delivery_enabled' => true,
        'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'mail.example.invalid',
            'username' => 'contact@example.fr', 'password' => 'fixture-password', 'port' => 587, 'timeout' => 900],
        'mail.from.address' => 'contact@example.fr', 'mail.from.name' => 'Genius Contrôle']);
    Http::preventStrayRequests();
    $this->mock(GlobalMailConfiguration::class)->shouldNotReceive('refresh');
    $this->root = sys_get_temp_dir().'/cofrac-delivery-test-'.Str::uuid();
    mkdir($this->root, 0700);
    $image = UploadedFile::fake()->image('test.png');
    copy($image->getRealPath(), $this->root.'/capture-1.png');
    $this->report = ['id' => (string) Str::uuid(), 'kind' => 'bug', 'title' => 'Un problème <b>signalé</b>',
        'message' => '<script>alert("untrusted")</script>', 'page' => '/v2/dossiers/{dossier}',
        'screenshots' => [['name' => 'capture-1.png', 'mime' => 'image/png']]];
});

afterEach(function () {
    Http::assertNothingSent();
    File::deleteDirectory($this->root);
});

it('uses only saved global SMTP settings and the fixed developer address with a bounded disposable transport', function () {
    // No SMTP connection: the on-demand mailer uses an in-memory transport.
    $memory = Mail::build(['transport' => 'array']);
    Mail::partialMock()->shouldReceive('build')->once()->withArgs(function ($configuration) {
        expect($configuration['host'])->toBe('mail.example.invalid')->and($configuration['username'])->toBe('contact@example.fr')
            ->and($configuration['timeout'])->toBe(10);

        return true;
    })->andReturn($memory);
    expect(app(SupportReportDelivery::class)->send($this->report, $this->root))->toBe('sent');
    $message = $memory->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('contact@lucas-dinnichert.fr')
        ->and($message->getFrom()[0]->getAddress())->toBe('contact@example.fr')
        ->and($message->getHtmlBody())->toContain('lang="fr"', '&lt;script&gt;', '/images/logo.png')
        ->not->toContain('<script>', '<b>signalé</b>')
        ->and($message->getAttachments()[0]->getFilename())->toBe('capture-1.png')
        ->and($message->getAttachments()[0]->getContentType())->toBe('image/png');
});

it('never claims delivery or opens SMTP in disabled simulated or non-SMTP modes', function (array $settings) {
    config($settings);
    Mail::shouldReceive('build')->never();
    expect(app(SupportReportDelivery::class)->send($this->report, $this->root))->toBe('simulated');
})->with([
    [['mail.delivery_enabled' => false]], [['simulation.enabled' => true]], [['mail.mailers.smtp.transport' => 'array']],
]);
