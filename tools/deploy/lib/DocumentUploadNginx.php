<?php

declare(strict_types=1);

namespace Cofrac2\Deploy;

use RuntimeException;

final class DocumentUploadNginx
{
    public static function prepare(string $source): string
    {
        $active = preg_replace('/^\h*#.*$/m', '', $source);
        $root = '~^\h*root\h+/var/www/TechCalendar-v2/public;\h*$~m';
        $front = '~location\h+=\h+/index\.php\h*\{([^{}]*)\}~';
        if (preg_match_all('/\bserver\s*\{/', $active) !== 1 || preg_match_all($root, $active) !== 1
            || preg_match_all($front, $active, $matches) !== 1
            || ! str_contains($matches[1][0], 'fastcgi_pass unix:/run/php/techcalendar-v2-demo.sock;')) {
            throw new RuntimeException('Vhost V2 ou controleur PHP inattendu pour les limites de transfert.');
        }
        // Refuse per-location overrides rather than silently leaving a lower effective limit.
        if (preg_match_all('/^\h*client_max_body_size\h+[^;\r\n]+;\h*$/m', $active, $limits) > 1
            || substr_count($active, 'client_max_body_size') !== count($limits[0])) {
            throw new RuntimeException('Limites Nginx ambigues.');
        }
        $rootLine = preg_match($root, $active, $roots) ? $roots[0] : '';
        if ($limits[0] !== [] && strpos($active, $limits[0][0]) > strpos($active, 'location ')) {
            throw new RuntimeException('Limite Nginx locale non prise en charge.');
        }
        $result = $limits[0] === [] ? str_replace($rootLine, $rootLine."\n    client_max_body_size 1100m;", $source)
            : str_replace($limits[0][0], '    client_max_body_size 1100m;', $source);
        $body = $matches[1][0];
        foreach (['fastcgi_read_timeout', 'fastcgi_send_timeout'] as $directive) {
            if (substr_count($active, $directive) !== substr_count($body, $directive) || substr_count($body, $directive) > 1) {
                throw new RuntimeException('Delais FastCGI ambigus.');
            }
            $pattern = '/^\h*'.$directive.'\h+[^;\r\n]+;\h*$/m';
            if (str_contains($body, $directive) && preg_match_all($pattern, $body) !== 1) {
                throw new RuntimeException('Directive FastCGI non prise en charge.');
            }
            $body = str_contains($body, $directive) ? preg_replace($pattern, '    '.$directive.' 3900s;', $body)
                : rtrim($body)."\n    ".$directive." 3900s;\n";
        }

        return str_replace($matches[0][0], str_replace($matches[1][0], $body, $matches[0][0]), $result);
    }
}
