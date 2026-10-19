<?php

namespace App\Services\Crm;

final class DocumentResponseHeaders
{
    public static function previewable(string|false|null $mime): bool
    {
        return in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    public static function forFile(string|false $mime, bool $inline): array
    {
        return [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            ...($inline && $mime === 'application/pdf' ? ['X-TC-Document-Preview' => 'pdf'] : []),
            // A CSP sandbox disables native PDF viewers. Only sniffed PDF/raster files bypass it.
            'Content-Security-Policy' => $inline
                ? "default-src 'none'; img-src 'self'; object-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'"
                : "default-src 'none'; sandbox",
        ];
    }
}
