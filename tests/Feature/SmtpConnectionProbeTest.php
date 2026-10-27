<?php

use App\Services\Admin\SmtpConnectionProbe;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

it('only starts and stops SMTP, with TLS and bounded timeouts, without sending mail', function (bool $authenticate) {
    $authenticators = [];
    $transport = Mockery::mock(EsmtpTransport::class)->makePartial();
    $transport->setUsername('fixture-user')->setPassword('fixture-password');
    $transport->shouldReceive('setAuthenticators')->once()->with(Mockery::on(function ($items) use (&$authenticators) {
        $authenticators = $items;

        return count($items) === 4;
    }));
    $transport->shouldReceive('setRequireTls')->once()->with(true)->andReturnSelf();
    $transport->shouldReceive('start')->once()->andReturnUsing(function () use ($authenticate, &$authenticators, $transport) {
        if ($authenticate) {
            $authenticators[2]->authenticate($transport);
        }
    });
    $transport->shouldReceive('stop')->once();
    $transport->shouldNotReceive('send');
    if ($authenticate) {
        $transport->shouldReceive('executeCommand')->once()
            ->with('AUTH PLAIN '.base64_encode("fixture-user\0fixture-user\0fixture-password")."\r\n", [235])->andReturn("235 Authenticated\r\n");
    } else {
        $transport->shouldNotReceive('executeCommand');
    }
    $manager = Mockery::mock(MailManager::class);
    $manager->shouldReceive('createSymfonyTransport')->once()->with(Mockery::on(fn ($config) => $config['timeout'] === 5
        && $config['verify_peer'] === true && $config['scheme'] === 'smtps' && $config['port'] === 465
        && ! isset($config['stream'], $config['source_ip'])))->andReturn($transport);
    expect((new SmtpConnectionProbe($manager))->check(['host' => 'smtp.example.test', 'port' => 465, 'username' => 'fixture-user',
        'verify_peer' => false, 'stream' => ['ssl' => ['verify_peer' => false]], 'source_ip' => '127.0.0.1']))->toBe($authenticate);
})->with([true, false]);

it('always closes the disposable SMTP transport after a failed handshake', function () {
    $transport = Mockery::mock(EsmtpTransport::class);
    $transport->shouldReceive('setAuthenticators')->once();
    $transport->shouldReceive('setRequireTls')->once();
    $transport->shouldReceive('start')->once()->andThrow(new RuntimeException('fixture-failure'));
    $transport->shouldReceive('stop')->once();
    $transport->shouldNotReceive('send');
    $manager = Mockery::mock(MailManager::class);
    $manager->shouldReceive('createSymfonyTransport')->once()->andReturn($transport);
    expect(fn () => (new SmtpConnectionProbe($manager))->check(['host' => 'smtp.example.test', 'port' => 587]))
        ->toThrow(RuntimeException::class, 'fixture-failure');
});

it('rejects arbitrary non SMTP ports and malformed saved hosts before opening a socket', function (array $config) {
    $manager = Mockery::mock(MailManager::class);
    $manager->shouldNotReceive('createSymfonyTransport');
    expect(fn () => (new SmtpConnectionProbe($manager))->check($config))->toThrow(InvalidArgumentException::class);
})->with([
    [['host' => 'smtp.example.test', 'port' => 80]],
    [['host' => 'smtp.example.test', 'port' => 3306]],
    [['host' => 'https://smtp.example.test', 'port' => 587]],
    [['host' => "smtp.example.test\r\n", 'port' => 587]],
]);
