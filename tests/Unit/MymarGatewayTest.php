<?php

use App\Services\Mymar\MymarGatewayClient;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use Cofrac2\Deploy\Mymar\MymarGateway;
use Cofrac2\Deploy\Mymar\MymarGatewayConfig;
use Cofrac2\Deploy\Mymar\MymarReadOnlySource;
use Illuminate\Http\Client\Factory;

require_once __DIR__.'/../../tools/deploy/mymar/gateway/MymarGateway.php';
require_once __DIR__.'/../../tools/deploy/mymar/gateway/MymarReadOnlySource.php';
require_once __DIR__.'/../../tools/deploy/mymar/gateway/MymarGatewayConfig.php';

function mymarGatewayToken(): string
{
    return hash('sha256', 'synthetic-local-fixture-only');
}

function mymarGatewayWindow(): MymarReadWindow
{
    return new MymarReadWindow('2026-11-02T00:00:00+01:00', '2026-11-03T00:00:00+01:00', [32, 26]);
}

function mymarGatewaySnapshot(): array
{
    $gateway = new MymarGateway(true, mymarGatewayToken(), new MymarObservationPolicy, fn () => [
        ['rdv_id' => 777, 'dossier_id' => 91, 'technician_id' => 26, 'status_id' => 16,
            'is_old' => 0, 'stored_start' => '2026-11-02 09:30:00', 'raw_duration' => 2],
    ]);

    return $gateway->handle('GET', '/v1/occupations', http_build_query(mymarGatewayWindow()->query()),
        'Bearer '.mymarGatewayToken(), '127.0.0.1', new DateTimeImmutable('2026-10-30T16:00:00Z'))['body'];
}

it('keeps every gateway path inert before authentication and query validation', function (array $override, int $status) {
    $reads = 0;
    $gateway = new MymarGateway($override['enabled'] ?? true, mymarGatewayToken(), new MymarObservationPolicy, function () use (&$reads) {
        $reads++;

        return [];
    });
    $result = $gateway->handle($override['method'] ?? 'GET', $override['path'] ?? '/v1/occupations',
        $override['query'] ?? http_build_query(mymarGatewayWindow()->query()), $override['authorization'] ?? 'Bearer '.mymarGatewayToken(),
        $override['peer'] ?? '127.0.0.1', new DateTimeImmutable('2026-10-30T16:00:00Z'));
    expect($reads)->toBe(0)->and($result['status'])->toBe($status)->and($result['body']['complete'])->toBeFalse();
})->with([
    [['enabled' => false], 503], [['peer' => '192.0.2.1'], 403], [['authorization' => ''], 403],
    [['authorization' => 'Bearer SECRET_CANARY'], 403], [['method' => 'POST'], 405], [['method' => 'HEAD'], 405],
    [['path' => '/v1/technicians'], 404], [['query' => ''], 422],
    [['query' => http_build_query(mymarGatewayWindow()->query()).'&token=SECRET_CANARY'], 422],
    [['query' => http_build_query(mymarGatewayWindow()->query()).'&technician_ids=99'], 422],
    [['query' => http_build_query(mymarGatewayWindow()->query()).'&status_ids=99'], 422],
    [['query' => http_build_query(array_replace(mymarGatewayWindow()->query(), ['technician_ids' => '26,26']))], 422],
    [['query' => http_build_query(array_replace(mymarGatewayWindow()->query(), ['technician_ids' => '1 OR 1=1']))], 422],
    [['query' => http_build_query(array_replace(mymarGatewayWindow()->query(), ['technician_ids' => str_repeat('9', 25)]))], 422],
    [['query' => str_repeat('x', 4097)], 422], [['query' => 'window_start=%GG'], 422],
]);

it('exports only the numeric source contract and never creates planning availability', function () {
    $snapshot = mymarGatewaySnapshot();
    expect($snapshot['technician_ids'])->toBe([26, 32])->and($snapshot['source_timezone'])->toBe('Europe/Paris')
        ->and($snapshot['duration_unit'])->toBe('hours')->and($snapshot['complete'])->toBeTrue();
    $http = new Factory;
    $http->preventStrayRequests()->fake(['http://127.0.0.1:19846/*' => Factory::response($snapshot)]);
    $report = (new MymarGatewayClient($http, true, token: mymarGatewayToken()))->observe(mymarGatewayWindow(),
        new MymarObservationPolicy, new DateTimeImmutable('2026-10-30T16:00:10Z'));
    expect($report['mode'])->toBe('private_gateway_observation')->and($report['http_requests'])->toBeTrue()
        ->and($report['connector_enabled'])->toBeFalse()->and($report['can_affect_planning'])->toBeFalse()
        ->and($report['candidates'][0]['duration_minutes'])->toBe(120)
        ->and($report['candidates'][0]['ends_at'])->toBe('2026-11-02T11:30:00+01:00');
    $http->assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.mymarGatewayToken())
        && ! str_contains($request->url(), mymarGatewayToken()));
});

it('does not expose source failures or allow unexpected business fields through the gateway', function (string $case) {
    $gateway = new MymarGateway(true, mymarGatewayToken(), new MymarObservationPolicy, function () use ($case) {
        if ($case === 'exception') {
            throw new RuntimeException('PASSWORD_CANARY user@example.test SQL QUERY');
        }

        return [['email' => 'user@example.test']];
    });
    $response = $gateway->handle('GET', '/v1/occupations', http_build_query(mymarGatewayWindow()->query()),
        'Bearer '.mymarGatewayToken(), '127.0.0.1', new DateTimeImmutable);
    expect($response)->toBe(['status' => 503, 'body' => ['error' => 'source_unavailable', 'complete' => false]])
        ->and(json_encode($response))->not->toContain('CANARY', '@', 'SQL');
})->with(['exception', 'extra_fields']);

it('does not perform any HTTP request until the client is explicitly configured', function () {
    $http = new Factory;
    $http->preventStrayRequests()->fake();
    foreach ([new MymarGatewayClient($http), new MymarGatewayClient($http, true),
        new MymarGatewayClient($http, true, port: 80, token: mymarGatewayToken())] as $client) {
        expect(fn () => $client->observe(mymarGatewayWindow(), new MymarObservationPolicy, new DateTimeImmutable))->toThrow(RuntimeException::class);
    }
    $http->assertNothingSent();
});

it('refuses unsafe failed oversized stale or incomplete gateway responses rather than returning free time', function (string $case) {
    $snapshot = mymarGatewaySnapshot();
    $headers = ['Content-Type' => 'application/json'];
    $status = 200;
    switch ($case) {
        case 'http_error': $status = 503;
            break;
        case 'redirect': $status = 302;
            $headers['Location'] = 'https://example.test';
            break;
        case 'html': $headers['Content-Type'] = 'text/html';
            break;
        case 'compression': $headers['Content-Encoding'] = 'gzip';
            break;
        case 'large_header': $headers['Content-Length'] = MymarGatewayClient::MAXIMUM_BYTES + 1;
            break;
        case 'large_body': $snapshot = str_repeat('x', MymarGatewayClient::MAXIMUM_BYTES + 1);
            break;
        case 'broken_json': $snapshot = '{';
            break;
        case 'wrong_scope': $snapshot['technician_ids'] = [26];
            break;
        case 'wrong_window': $snapshot['window_end'] = '2026-11-03T23:00:00Z';
            break;
        case 'partial': $snapshot['complete'] = false;
            break;
        case 'stale': $snapshot['generated_at'] = '2026-10-30T12:00:00Z';
            break;
        case 'future': $snapshot['generated_at'] = '2026-10-31T12:00:00Z';
            break;
        case 'timezone': $snapshot['source_timezone'] = 'UTC';
            break;
        case 'duration': $snapshot['duration_unit'] = 'minutes';
            break;
        case 'extra': $snapshot['email'] = 'CANARY@example.test';
            break;
    }
    $http = new Factory;
    $http->preventStrayRequests()->fake(['*' => Factory::response(is_array($snapshot) ? json_encode($snapshot) : $snapshot, $status, $headers)]);
    try {
        (new MymarGatewayClient($http, true, token: mymarGatewayToken()))->observe(mymarGatewayWindow(),
            new MymarObservationPolicy, new DateTimeImmutable('2026-10-30T16:00:10Z'));
        $this->fail('Invalid response accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('mymar.gateway_observation_failed')->and($exception->getPrevious())->toBeNull();
    }
    $http->assertSentCount(1);
})->with(['http_error', 'redirect', 'html', 'compression', 'large_header', 'large_body', 'broken_json', 'wrong_scope', 'wrong_window',
    'partial', 'stale', 'future', 'timezone', 'duration', 'extra']);

it('rejects every global table-wide writable delegated or role-based SQL grant', function (string $grant) {
    expect(fn () => MymarReadOnlySource::validateGrants([$grant]))->toThrow(RuntimeException::class);
})->with([
    'GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost`',
    'GRANT SELECT ON `MYMAR`.* TO `cofrac_mymar_ro`@`localhost`',
    'GRANT SELECT ON `MYMAR`.`dossiers` TO `cofrac_mymar_ro`@`localhost`',
    'GRANT SELECT (`id`, `status_id`, `technicien_id`, `email`) ON `MYMAR`.`dossiers` TO `cofrac_mymar_ro`@`localhost`',
    'GRANT SELECT (`id`, `status_id`, `technicien_id`) ON `MYMAR`.`dossiers` TO `cofrac_mymar_ro`@`localhost` WITH GRANT OPTION',
    'GRANT `reader_role`@`%` TO `cofrac_mymar_ro`@`localhost`',
]);

it('accepts only exact SELECT column grants and builds one fixed parameterized query', function () {
    MymarReadOnlySource::validateGrants([
        'GRANT USAGE ON *.* TO `cofrac_mymar_ro`@`localhost`',
        'GRANT SELECT (`technicien_id`, `id`, `status_id`) ON `MYMAR`.`dossiers` TO `cofrac_mymar_ro`@`localhost`',
        'GRANT SELECT (`duree`, `date_rdv`, `id`, `is_old`, `dossier_id`) ON `MYMAR`.`dossier_rdv` TO `cofrac_mymar_ro`@`localhost`',
    ]);
    [$sql, $parameters] = MymarReadOnlySource::query(mymarGatewayWindow(), new MymarObservationPolicy);
    expect($parameters)->toBe([26, 32, 3, 16, '2026-11-01 00:00:00', '2026-11-03 00:00:00', 24])
        ->and($sql)->toContain('MAX_EXECUTION_TIME(1500)', 'r.is_old=0', 'LIMIT 2001')
        ->and($sql)->not->toContain('users', 'email', 'cofrac2production', '2026-11-02');
});

it('envelopes DST boundaries without excluding an overlapping 24 hour appointment', function () {
    $window = new MymarReadWindow('2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z', [26]);
    expect($window->sourceBounds('Europe/Paris'))->toBe(['2026-10-24 02:15:00', '2026-10-25 03:45:00']);
});

it('rejects unsafe or unbounded request windows', function (string $from, string $to, array $ids) {
    expect(fn () => new MymarReadWindow($from, $to, $ids))->toThrow(InvalidArgumentException::class);
})->with([
    ['2026-11-02 09:00:00', '2026-11-03T00:00:00Z', [26]],
    ['2026-02-30T00:00:00Z', '2026-03-01T00:00:00Z', [26]],
    ['2026-11-02T00:00:00Z', '2026-11-02T00:00:00Z', [26]],
    ['2026-11-02T00:00:00Z', '2026-12-04T00:00:00Z', [26]],
    ['2026-11-02T00:00:00Z', '2026-11-03T00:00:00Z', []],
    ['2026-11-02T00:00:00Z', '2026-11-03T00:00:00Z', ['26']],
    ['2026-11-02T00:00:00Z', '2026-11-03T00:00:00Z', [26, 26]],
    ['2026-11-02T00:00:00Z', '2026-11-03T00:00:00Z', range(1, 201)],
]);

it('loads only private literal JSON configuration and refuses weak credentials or application files', function () {
    $directory = sys_get_temp_dir().'/mymar-gateway-config-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $file = $directory.'/config.json';
    $config = ['enabled' => false, 'token' => mymarGatewayToken(), 'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours',
        'database_socket' => '/var/run/mysqld/mysqld.sock', 'database_user' => 'cofrac_mymar_ro', 'database_password' => str_repeat('x', 32)];
    try {
        file_put_contents($file, json_encode($config));
        chmod($file, 0600);
        expect(MymarGatewayConfig::load(realpath($file))['enabled'])->toBeFalse();
        foreach (['database_user' => 'debian-sys-maint', 'token' => str_repeat('a', 64), 'database_socket' => '127.0.0.1'] as $key => $value) {
            file_put_contents($file, json_encode(array_replace($config, [$key => $value])));
            expect(fn () => MymarGatewayConfig::load(realpath($file)))->toThrow(RuntimeException::class);
        }
        file_put_contents($file, json_encode($config));
        chmod($file, 0644);
        clearstatcache();
        expect(fn () => MymarGatewayConfig::load(realpath($file)))->toThrow(RuntimeException::class);
        chmod($file, 0600);
        symlink($file, $directory.'/link.json');
        expect(fn () => MymarGatewayConfig::load($directory.'/link.json'))->toThrow(RuntimeException::class);
        unlink($directory.'/link.json');
    } finally {
        unlink($file);
        rmdir($directory);
    }
});
