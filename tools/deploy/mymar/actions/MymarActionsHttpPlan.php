<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

final class MymarActionsHttpPlan
{
    public const ROOT = '/var/lib/cofrac-mymar-actions-gateway';

    public const CONFIG = '/etc/cofrac-mymar-actions-gateway';

    public const USER = 'cofrac-mymar-actions';

    public const UNITS = ['cofrac-mymar-actions-fpm.service', 'cofrac-mymar-actions-http.service'];

    public static function files(): array
    {
        $files = [];
        foreach (MymarRuntimePlan::files() as $name => $content) {
            $content = str_replace('env[MYMAR_GATEWAY_SCOPE] = /var/lib/cofrac-mymar-gateway/private/scope.json'."\n", '', $content);
            $files[str_replace('cofrac-mymar', 'cofrac-mymar-actions', $name)] = strtr($content, [
                'cofrac-mymar' => 'cofrac-mymar-actions', '19846' => '19848', 'MYMAR_GATEWAY_CONFIG' => 'MYMAR_ACTIONS_CONFIG',
                '/code/tools/deploy/mymar/runtime/entry.php' => '/code/entry.php', 'LimitRequestBody 1024' => 'LimitRequestBody 16384',
            ]);
        }

        return $files;
    }
}
