<?php

use Cofrac2\Deploy\Mymar\MymarRuntimePlan;

require_once __DIR__.'/../../tools/deploy/mymar/runtime/MymarRuntimePlan.php';

it('isolates HTTP and FPM from both existing applications and the public server', function () {
    $files = MymarRuntimePlan::files();
    expect(array_keys($files))->toBe(['apache.conf', 'php-fpm.conf', ...MymarRuntimePlan::UNITS]);
    $apache = $files['apache.conf'];
    expect($apache)->toContain('Listen 127.0.0.1:19846', 'Require local', 'ProxyAddHeaders Off', 'ThreadsPerChild 4', 'MaxRequestWorkers 4')
        ->not->toContain('0.0.0.0', 'Include', '/etc/apache2', '/var/www/cofrac2', '/home/ubuntu/www', 'CustomLog');
    expect($files['php-fpm.conf'])->toContain('pm.max_children = 2', 'listen.mode = 0600', 'clear_env = yes', 'memory_limit] = 64M')
        ->not->toContain('www-data', '/run/cofrac2/', 'bootstrap', 'autoload');
    foreach (MymarRuntimePlan::UNITS as $name) {
        expect($files[$name])->toContain('ProtectSystem=strict', 'ProtectHome=true', 'NoNewPrivileges=true', 'IPAddressDeny=any', 'MemoryMax=192M')
            ->not->toContain('ExecReload', 'apache2.service', 'cofrac2-queue', 'CAP_SYS_ADMIN', 'CAP_NET_ADMIN');
    }
});

it('keeps generated artifacts deterministic with no credentials or embedded identities', function () {
    expect(MymarRuntimePlan::files())->toBe(MymarRuntimePlan::files());
    $content = implode('', MymarRuntimePlan::files());
    expect($content)->not->toContain('password', 'Bearer', '@', 'approved-map');
});
