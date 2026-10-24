<?php

namespace App\Services\Coffrac;

/** Decode the sealed SQL export without altering the archived source bytes. */
final class LegacyTemplateText
{
    public static function read(array $record, string $field): ?string
    {
        $encoded = array_key_exists($field.'_base64', $record);
        if (array_key_exists($field, $record)) {
            $value = $record[$field];

            return ! $encoded && is_string($value) && self::validText($value) ? $value : null;
        }
        if (! $encoded || ! is_string($record[$field.'_base64'])
            || ($record[$field.'_charset'] ?? null) !== 'utf32'
            || strlen($record[$field.'_base64']) > 400000) {
            return null;
        }
        // MySQL TO_BASE64 wraps its output at 76 characters. No other whitespace is accepted.
        $base64 = str_replace(["\r\n", "\n"], '', $record[$field.'_base64']);
        $bytes = base64_decode($base64, true);
        // MySQL's utf32 stores big-endian code points, without a byte-order mark.
        if ($bytes === false || base64_encode($bytes) !== $base64
            || ! mb_check_encoding($bytes, 'UTF-32BE')) {
            return null;
        }
        $value = mb_convert_encoding($bytes, 'UTF-8', 'UTF-32BE');

        return self::validText($value) && mb_convert_encoding($value, 'UTF-32BE', 'UTF-8') === $bytes ? $value : null;
    }

    private static function validText(string $value): bool
    {
        return strlen($value) <= 300000 && mb_check_encoding($value, 'UTF-8')
            && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
    }
}
