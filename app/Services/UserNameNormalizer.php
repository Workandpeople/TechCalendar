<?php

namespace App\Services;

class UserNameNormalizer
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        if (array_key_exists('first_name', $payload)) {
            $payload['first_name'] = self::firstName((string) $payload['first_name']);
        }

        if (array_key_exists('last_name', $payload)) {
            $payload['last_name'] = self::lastName((string) $payload['last_name']);
        }

        return $payload;
    }

    public static function firstName(string $value): string
    {
        $normalized = self::squish($value);
        $normalized = mb_convert_case(mb_strtolower($normalized, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return (string) preg_replace_callback(
            "/(?<=['’])\\p{Ll}/u",
            static fn (array $matches): string => mb_strtoupper($matches[0], 'UTF-8'),
            $normalized,
        );
    }

    public static function lastName(string $value): string
    {
        return mb_strtoupper(self::squish($value), 'UTF-8');
    }

    private static function squish(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }
}
