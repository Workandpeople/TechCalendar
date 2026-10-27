<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use Dotenv\Dotenv;
use RuntimeException;

final class ProductionMailEnablement
{
    public static function enabledEnvironment(string $contents): string
    {
        $before = Dotenv::parse($contents);
        if (! in_array($before['APP_URL'] ?? null, ['https://cofrac2.geniuscontrole.com', 'https://cofrac.geniuscontrole.com'], true)) {
            throw new RuntimeException('Domaine applicatif non approuve.');
        }
        foreach (['APP_ENV' => 'production',
            'DB_DATABASE' => 'cofrac2production', 'CRM_V2_ENABLED' => 'true', 'SIMULATION_MODE' => 'false',
            'MAIL_MAILER' => 'log', 'MAIL_DELIVERY_ENABLED' => 'false',
            'SMSFACTOR_ENABLED' => 'false', 'SMSFACTOR_MODE' => 'simulate', 'CAPCEE_ENABLED' => 'false'] as $key => $expected) {
            if (($before[$key] ?? null) !== $expected) {
                throw new RuntimeException('Configuration avant activation mail inattendue : '.$key);
            }
        }
        $next = $contents;
        foreach (['MAIL_MAILER' => 'smtp', 'MAIL_DELIVERY_ENABLED' => 'true'] as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match_all($pattern, $next) !== 1) {
                throw new RuntimeException('Cle mail absente ou dupliquee.');
            }
            $next = preg_replace_callback($pattern, fn () => $key.'='.$value, $next);
        }
        $after = Dotenv::parse($next);
        unset($before['MAIL_MAILER'], $before['MAIL_DELIVERY_ENABLED'], $after['MAIL_MAILER'], $after['MAIL_DELIVERY_ENABLED']);
        if ($before !== $after) {
            throw new RuntimeException('Modification hors des deux cles mail autorisees.');
        }

        return $next;
    }
}
