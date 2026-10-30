<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

/** An independent Apache/FPM pair. Never included by the shared Apache or either Laravel application. */
final class MymarRuntimePlan
{
    public const ROOT = '/var/lib/cofrac-mymar-gateway';

    public const CONFIG = '/etc/cofrac-mymar-gateway';

    public const USER = 'cofrac-mymar';

    public const PORT = 19846;

    public const UNITS = ['cofrac-mymar-fpm.service', 'cofrac-mymar-http.service'];

    public static function files(): array
    {
        $apache = <<<'CONF'
ServerRoot /etc/cofrac-mymar-gateway
ServerName 127.0.0.1
Listen 127.0.0.1:19846
PidFile /run/cofrac-mymar-http/apache.pid
DefaultRuntimeDir /run/cofrac-mymar-http
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so
LoadModule reqtimeout_module /usr/lib/apache2/modules/mod_reqtimeout.so
User cofrac-mymar
Group cofrac-mymar
ErrorLog /var/log/cofrac-mymar-gateway/http-error.log
LogLevel warn
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Timeout 7
KeepAlive Off
ServerLimit 1
StartServers 1
ThreadsPerChild 4
MaxRequestWorkers 4
MinSpareThreads 1
MaxSpareThreads 4
MaxConnectionsPerChild 100
RequestReadTimeout header=5-10,MinRate=500 body=5,MinRate=500
LimitRequestBody 1024
LimitRequestFields 20
LimitRequestFieldSize 8192
ProxyRequests Off
ProxyAddHeaders Off
ProxyTimeout 6
DocumentRoot /var/lib/cofrac-mymar-gateway/empty
<Directory />
    Require all denied
</Directory>
<Location />
    Require local
    CGIPassAuth On
</Location>
ProxyPassMatch "^/.*$" "unix:/run/cofrac-mymar-fpm/php.sock|fcgi://localhost/var/lib/cofrac-mymar-gateway/code/tools/deploy/mymar/runtime/entry.php"
CONF;
        $fpm = <<<'CONF'
[global]
pid = /run/cofrac-mymar-fpm/php.pid
error_log = /var/log/cofrac-mymar-gateway/fpm-error.log
daemonize = no

[mymar]
user = cofrac-mymar
group = cofrac-mymar
listen = /run/cofrac-mymar-fpm/php.sock
listen.owner = cofrac-mymar
listen.group = cofrac-mymar
listen.mode = 0600
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 10s
pm.max_requests = 100
request_terminate_timeout = 7s
catch_workers_output = yes
clear_env = yes
security.limit_extensions = .php
env[MYMAR_GATEWAY_CONFIG] = /var/lib/cofrac-mymar-gateway/private/config.json
env[MYMAR_GATEWAY_SCOPE] = /var/lib/cofrac-mymar-gateway/private/scope.json
php_admin_value[memory_limit] = 64M
php_admin_value[max_execution_time] = 5
php_admin_value[open_basedir] = /var/lib/cofrac-mymar-gateway:/var/run/mysqld:/run/mysqld
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[zend.exception_ignore_args] = on
php_admin_value[error_log] = /var/log/cofrac-mymar-gateway/worker-error.log
CONF;
        $unit = <<<'UNIT'
[Service]
Type=simple
Restart=on-failure
RestartSec=5
UMask=0077
RuntimeDirectoryMode=0755
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
NoNewPrivileges=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
CapabilityBoundingSet=CAP_SETUID CAP_SETGID CAP_CHOWN
IPAddressDeny=any
IPAddressAllow=127.0.0.1/32
ReadWritePaths=/var/log/cofrac-mymar-gateway
TimeoutStopSec=10
MemoryMax=192M
TasksMax=32

[Install]
WantedBy=multi-user.target
UNIT;

        return ['apache.conf' => $apache."\n", 'php-fpm.conf' => $fpm."\n",
            'cofrac-mymar-fpm.service' => "[Unit]\nDescription=MYMAR column-only private gateway PHP\nAfter=mysql.service\n\n".$unit
                ."\n[Service]\nExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/cofrac-mymar-gateway/php-fpm.conf\n"
                ."RuntimeDirectory=cofrac-mymar-fpm\nReadWritePaths=/run/cofrac-mymar-fpm\nRestrictAddressFamilies=AF_UNIX\n",
            'cofrac-mymar-http.service' => "[Unit]\nDescription=MYMAR loopback-only private gateway HTTP\nRequires=cofrac-mymar-fpm.service\nAfter=cofrac-mymar-fpm.service\n\n".$unit
                ."\n[Service]\nExecStart=/usr/sbin/apache2 -f /etc/cofrac-mymar-gateway/apache.conf -DFOREGROUND\n"
                ."RuntimeDirectory=cofrac-mymar-http\nReadWritePaths=/run/cofrac-mymar-http\nRestrictAddressFamilies=AF_UNIX AF_INET\n"];
    }
}
