<?php

namespace App\Support;

final class CofracText
{
    /** Normalize prose only; URLs, addresses, paths and template keys keep their identity. */
    public static function normalize(?string $text): ?string
    {
        if ($text === null || stripos($text, 'coffrac') === false) {
            return $text;
        }

        return preg_replace_callback(
            '~(?:https?://|www\.)[^\s<>"\']+|[\w.+-]+@[\w.-]+|(?:/?[\w.-]+/)+[^\s<>"\']*|\{\{.*?\}\}|\bcoffrac\b~iu',
            static function (array $match): string {
                if (strcasecmp($match[0], 'coffrac') !== 0) {
                    return $match[0];
                }

                return match ($match[0]) {
                    'COFFRAC' => 'COFRAC',
                    'coffrac' => 'cofrac',
                    default => 'Cofrac',
                };
            },
            $text,
        );
    }
}
