<?php

use App\Services\DocumentUpload;
use Cofrac2\Deploy\DocumentUploadNginx;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../tools/deploy/lib/DocumentUploadNginx.php';

function uploadNginxFixture(): string
{
    return "server {\n    listen 80;\n    server_name techcalendar.lucas-dinnichert.fr;\n"
        ."    root /var/www/TechCalendar-v2/public;\n    client_max_body_size 40m;\n"
        ."    add_header X-Frame-Options SAMEORIGIN always;\n    location / { try_files \$uri /index.php; }\n"
        ."    location = /index.php {\n        fastcgi_pass unix:/run/php/techcalendar-v2-demo.sock;\n    }\n}\n";
}

it('prepares only the dedicated demo vhost with multipart headroom and large upload timeouts idempotently', function (bool $existing) {
    $source = uploadNginxFixture();
    if (! $existing) {
        $source = str_replace("    client_max_body_size 40m;\n", '', $source);
    }
    $result = DocumentUploadNginx::prepare($source);
    expect($result)->toContain('client_max_body_size 1100m;', 'fastcgi_read_timeout 3900s;', 'fastcgi_send_timeout 3900s;',
        'add_header X-Frame-Options SAMEORIGIN always;')->not->toContain('40m;');
    expect(DocumentUploadNginx::prepare($result))->toBe($result);
})->with([true, false]);

it('refuses ambiguous vhosts and scope overrides rather than modifying another application', function (string $kind) {
    $source = match ($kind) {
        'other-app' => str_replace('TechCalendar-v2', 'Other', uploadNginxFixture()),
        'other-socket' => str_replace('techcalendar-v2-demo.sock', 'php8.3-fpm.sock', uploadNginxFixture()),
        'two-servers' => uploadNginxFixture()."\nserver {}",
        'two-limits' => str_replace('    location / {', "    client_max_body_size 1m;\n    location / {", uploadNginxFixture()),
        'location-limit' => str_replace('    client_max_body_size 40m;', '', str_replace('try_files', 'client_max_body_size 1m; try_files', uploadNginxFixture())),
        'global-timeout' => str_replace('    location / {', "    fastcgi_read_timeout 30s;\n    location / {", uploadNginxFixture()),
    };
    expect(fn () => DocumentUploadNginx::prepare($source))->toThrow(RuntimeException::class);
})->with(['other-app', 'other-socket', 'two-servers', 'two-limits', 'location-limit', 'global-timeout']);

it('keeps PHP and Apache limits above the app limit without allocating one GiB of memory per worker', function () {
    expect(DocumentUpload::MAX_BYTES)->toBe(1024 ** 3);
    foreach (['cofrac2-php-fpm.conf.example', 'php-fpm-demo.conf.example'] as $file) {
        $pool = file_get_contents(__DIR__.'/../../tools/deploy/'.$file);
        expect($pool)->toContain('php_admin_value[upload_max_filesize] = 1024M', 'php_admin_value[post_max_size] = 1100M',
            'php_admin_value[max_input_time] = 3600', 'request_terminate_timeout = 3900s', 'php_admin_value[memory_limit] = 256M');
    }
    $apache = file_get_contents(__DIR__.'/../../tools/deploy/apache-production-app.conf.example');
    expect($apache)->toContain('ServerName cofrac2.geniuscontrole.com', 'LimitRequestBody 1153433600', 'ProxyTimeout 3900');
    expect(1153433600)->toBeGreaterThan(DocumentUpload::MAX_BYTES);
});

it('updates dedicated configurations with backups and valid shell syntax', function (string $file) {
    $path = __DIR__.'/../../tools/deploy/'.$file;
    $process = new Process(['bash', '-n', $path]);
    expect($process->run())->toBe(0, $process->getErrorOutput());
    $source = file_get_contents($path);
    if ($file === 'update-production-release.sh') {
        expect($source)->toContain('php-fpm.before.conf', 'php-fpm.expected.conf', 'fpm_written=1', 'php-fpm8.4 --test --fpm-config')
            ->toContain('public/favicon.ico|tools/fixtures/collect-techcalendar-references.php', 'chmod 0644 "$APP/public/favicon.ico"')
            ->not->toContain('systemctl restart php8.4-fpm', 'systemctl restart apache2');
    }
})->with(['update-production-release.sh', 'update-demo.sh']);
