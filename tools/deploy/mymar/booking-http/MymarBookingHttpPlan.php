<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

/** Same hardened runtime policy, different user, paths, units, credentials and loopback port. */
final class MymarBookingHttpPlan
{
    public const ROOT = '/var/lib/cofrac-mymar-booking-gateway';

    public const CONFIG = '/etc/cofrac-mymar-booking-gateway';

    public const USER = 'cofrac-mymar-booking';

    public const PORT = 19847;

    public const UNITS = ['cofrac-mymar-booking-fpm.service', 'cofrac-mymar-booking-http.service'];

    public static function files(): array
    {
        $files = [];
        foreach (MymarRuntimePlan::files() as $name => $content) {
            $content = str_replace('env[MYMAR_GATEWAY_SCOPE] = /var/lib/cofrac-mymar-gateway/private/scope.json'."\n", '', $content);
            $files[str_replace('cofrac-mymar', 'cofrac-mymar-booking', $name)] = strtr($content, [
                'cofrac-mymar' => 'cofrac-mymar-booking', '19846' => '19847', 'MYMAR_GATEWAY_CONFIG' => 'MYMAR_BOOKING_CONFIG',
                '/code/tools/deploy/mymar/runtime/entry.php' => '/code/entry.php',
            ]);
        }

        return $files;
    }
}
