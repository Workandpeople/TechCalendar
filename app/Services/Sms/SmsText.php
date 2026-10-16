<?php

namespace App\Services\Sms;

use Illuminate\Validation\ValidationException;

class SmsText
{
    public static function phone(string $input): string
    {
        $number = preg_replace('/[\s.()\-]+/u', '', trim($input));
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }
        if (preg_match('/\A0[67][0-9]{8}\z/', $number)) {
            $number = '+33'.substr($number, 1);
        }
        if (! preg_match('/\A\+[1-9][0-9]{7,14}\z/', $number)
            || (str_starts_with($number, '+33') && ! preg_match('/\A\+33[67][0-9]{8}\z/', $number))) {
            throw ValidationException::withMessages(['recipient' => 'Indique un seul numéro mobile : 06/07… ou le format international +… (indicatif explicite pour les DOM).']);
        }

        return $number;
    }

    public static function plain(string $body): string
    {
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('~<(br\b[^>]*|/(?:p|div|li))>~i', "\n", $body);

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($body)));
    }

    public static function metrics(string $body): array
    {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extended = "\f^{}\\[~]|€";
        $units = 0;
        foreach (mb_str_split($body) as $character) {
            if (str_contains($basic, $character)) {
                $units++;
            } elseif (str_contains($extended, $character)) {
                $units += 2;
            } else {
                $units = strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')) / 2;

                return ['unicode' => 1, 'characters' => mb_strlen($body), 'segments' => $units <= 70 ? 1 : (int) ceil($units / 67)];
            }
        }

        return ['unicode' => 0, 'characters' => mb_strlen($body), 'segments' => $units <= 160 ? 1 : (int) ceil($units / 153)];
    }
}
