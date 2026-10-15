<?php

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->cutoverDirectory = sys_get_temp_dir().'/demo-cutover-'.bin2hex(random_bytes(8));
    mkdir($this->cutoverDirectory, 0700);
    $this->vhost = <<<'NGINX'
server {
    listen 80;
    server_name techcalendar.lucas-dinnichert.fr;
    root /var/www/TechCalendar/public;
    index index.php index.html;
    charset utf-8;
    add_header Content-Security-Policy "default-src 'self'; worker-src 'self' blob:;" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    $this->cutoverProcess = fn () => new Process([
        PHP_BINARY, __DIR__.'/../../tools/deploy/demo-cutover.php', 'nginx',
        $this->cutoverDirectory.'/v1', $this->cutoverDirectory.'/v2', $this->cutoverDirectory.'/probe',
    ]);
});

afterEach(function () {
    foreach (glob($this->cutoverDirectory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->cutoverDirectory);
});

it('prepares an isolated local probe and preserves existing headers and the source vhost', function () {
    file_put_contents($this->cutoverDirectory.'/v1', $this->vhost);
    $process = ($this->cutoverProcess)();
    expect($process->run())->toBe(0);
    $v2 = file_get_contents($this->cutoverDirectory.'/v2');
    $probe = file_get_contents($this->cutoverDirectory.'/probe');
    expect(file_get_contents($this->cutoverDirectory.'/v1'))->toBe($this->vhost)
        ->and($v2)->toContain('root /var/www/TechCalendar-v2/public;', 'location = /index.php {',
            'location ~ \.php$ {', 'return 404;', 'techcalendar-v2-demo.sock',
            'proxy_pass http://127.0.0.1:8081;', 'X-TechCalendar-Release v2-demo',
            'listen 80;', 'deny all;', 'client_max_body_size 40m;',
            'add_header Content-Security-Policy "default-src \'self\'; worker-src \'self\' blob:;" always;',
            'add_header X-Frame-Options "SAMEORIGIN" always;')
        ->not->toContain('php8.3-fpm.sock', 'auth_basic')
        ->and($probe)->toBe(str_replace('listen 80;', 'listen 127.0.0.1:18081;', $v2));
});

it('refuses an unexpected vhost without changing the original', function (string $change) {
    $original = match ($change) {
        'already-v2' => str_replace('/var/www/TechCalendar/public', '/var/www/TechCalendar-v2/public', $this->vhost),
        'proxy' => str_replace('index index.php index.html;', 'proxy_pass http://127.0.0.1:8080;', $this->vhost),
        'duplicate' => $this->vhost."\n".$this->vhost,
    };
    file_put_contents($this->cutoverDirectory.'/v1', $original);
    expect(($this->cutoverProcess)()->run())->toBe(1)
        ->and(file_get_contents($this->cutoverDirectory.'/v1'))->toBe($original)
        ->and(file_exists($this->cutoverDirectory.'/v2'))->toBeFalse();
})->with(['already-v2', 'proxy', 'duplicate']);
