<?php

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/document-preview-nginx-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    $this->policy = "default-src 'self'; object-src 'none'; img-src 'self' data: blob: https://*.mapbox.com; connect-src 'self' https://*.mapbox.com wss:; worker-src 'self' blob:; child-src blob:;";
    $this->pagePolicy = $this->policy." frame-src 'self';";
    $this->vhost = <<<'NGINX'
# Existing demo vhost: only the explicit frame permission may be added for pages.
server {
    listen 80;
    server_name techcalendar.lucas-dinnichert.fr;
    root /var/www/TechCalendar-v2/public;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header Content-Security-Policy "POLICY" always;
    add_header X-TechCalendar-Release v2-demo always;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/techcalendar-v2-demo.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;
    }
    location ~ \.php$ { return 404; }
}
NGINX;
    $this->vhost = str_replace('POLICY', $this->policy, $this->vhost);
    file_put_contents($this->directory.'/input', $this->vhost);
    $this->prepare = fn (array $extra = []) => new Process([
        PHP_BINARY, __DIR__.'/../../tools/deploy/document-preview-nginx.php',
        $this->directory.'/input', $this->directory.'/output', $this->directory.'/map', ...$extra,
    ]);
});

afterEach(function () {
    foreach (glob($this->directory.'/*') as $path) {
        unlink($path);
    }
    rmdir($this->directory);
});

it('only relaxes object-src for the application marker and exact PDF response type', function () {
    $process = ($this->prepare)();
    expect($process->run())->toBe(0, $process->getErrorOutput());
    $vhost = file_get_contents($this->directory.'/output');
    $map = file_get_contents($this->directory.'/map');
    expect(file_get_contents($this->directory.'/input'))->toBe($this->vhost)
        ->and($vhost)->toBe(str_replace('"'.$this->policy.'"', '$tc_document_preview_csp', $this->vhost))
        ->and($map)->toContain('map "$upstream_http_x_tc_document_preview:$upstream_http_content_type" $tc_document_preview_csp',
            '    default "'.$this->pagePolicy.'";',
            '    "pdf:application/pdf" "'.str_replace("object-src 'none'", "object-src 'self'", $this->pagePolicy).'";')
        ->not->toContain('$http_x_tc_document_preview', '$http_content_type', '~', 'include ', 'sandbox');
});

it('is idempotent only with the exact existing generated map', function () {
    expect(($this->prepare)()->run())->toBe(0);
    $vhost = file_get_contents($this->directory.'/output');
    $map = file_get_contents($this->directory.'/map');
    file_put_contents($this->directory.'/input', $vhost);
    rename($this->directory.'/map', $this->directory.'/existing-map');
    unlink($this->directory.'/output');
    expect(($this->prepare)([$this->directory.'/existing-map'])->run())->toBe(0)
        ->and(file_get_contents($this->directory.'/output'))->toBe($vhost)
        ->and(file_get_contents($this->directory.'/map'))->toBe($map);
});

it('refuses unsafe or ambiguous configuration without producing partial files', function (string $change) {
    $source = match ($change) {
        'duplicate-header' => str_replace('    location / {', '    add_header Content-Security-Policy "default-src \'none\'" always;'."\n    location / {", $this->vhost),
        'multiple-servers' => $this->vhost."\nserver { listen 443; }",
        'wrong-root' => str_replace('TechCalendar-v2/public', 'TechCalendar/public', $this->vhost),
        'missing-always' => str_replace('"'.$this->policy.'" always;', '"'.$this->policy.'";', $this->vhost),
        'no-object-directive' => str_replace("object-src 'none';", '', $this->vhost),
        'object-self' => str_replace("object-src 'none'", "object-src 'self'", $this->vhost),
        'duplicate-object' => str_replace("object-src 'none'", "object-src 'self'; object-src 'none'", $this->vhost),
        'sandbox' => str_replace("object-src 'none'", "sandbox; object-src 'none'", $this->vhost),
        'blocked-frame' => str_replace("object-src 'none'", "frame-src 'none'; object-src 'none'", $this->vhost),
        'blocked-connect' => str_replace("connect-src 'self' https://*.mapbox.com wss:", "connect-src 'none'", $this->vhost),
        'blocked-image' => str_replace("img-src 'self' data: blob: https://*.mapbox.com", "img-src 'none'", $this->vhost),
        'blocked-ancestors' => str_replace("object-src 'none'", "frame-ancestors 'none'; object-src 'none'", $this->vhost),
        'deny' => str_replace('"SAMEORIGIN"', '"DENY"', $this->vhost),
        'variable' => str_replace("object-src 'none'", "object-src 'none'; report-uri /\$request_id", $this->vhost),
        'hidden-app-csp' => str_replace('    location / {', "    fastcgi_hide_header Content-Security-Policy;\n    location / {", $this->vhost),
        'missing-map' => str_replace('"'.$this->policy.'"', '$tc_document_preview_csp', $this->vhost),
        'tampered-map' => str_replace('"'.$this->policy.'"', '$tc_document_preview_csp', $this->vhost),
    };
    file_put_contents($this->directory.'/input', $source);
    file_put_contents($this->directory.'/existing-map', 'map $http_x_tc_document_preview $tc_document_preview_csp { default "object-src *"; }');
    $extra = $change === 'tampered-map' ? [$this->directory.'/existing-map'] : [];
    expect(($this->prepare)($extra)->run())->toBe(1)
        ->and(file_get_contents($this->directory.'/input'))->toBe($source)
        ->and(file_exists($this->directory.'/output'))->toBeFalse()
        ->and(file_exists($this->directory.'/map'))->toBeFalse();
})->with(['duplicate-header', 'multiple-servers', 'wrong-root', 'missing-always', 'no-object-directive',
    'object-self', 'duplicate-object', 'sandbox', 'blocked-frame', 'blocked-connect', 'blocked-image',
    'blocked-ancestors', 'deny', 'variable', 'hidden-app-csp', 'missing-map', 'tampered-map']);

it('never overwrites input or existing output files', function () {
    file_put_contents($this->directory.'/output', 'keep');
    expect(($this->prepare)()->run())->toBe(1)
        ->and(file_get_contents($this->directory.'/output'))->toBe('keep')
        ->and(file_get_contents($this->directory.'/input'))->toBe($this->vhost)
        ->and(file_exists($this->directory.'/map'))->toBeFalse();
});

it('keeps explicit frame and worker policies unchanged and does not duplicate frame-src', function () {
    $policy = $this->policy." frame-src 'self' https://trusted.example;";
    file_put_contents($this->directory.'/input', str_replace($this->policy, $policy, $this->vhost));
    expect(($this->prepare)()->run())->toBe(0);
    $map = file_get_contents($this->directory.'/map');
    expect($map)->toContain('    default "'.$policy.'";')
        ->and(substr_count($map, 'frame-src'))->toBe(2)
        ->and(substr_count($map, "worker-src 'self' blob:; child-src blob:;"))->toBe(2);
});
