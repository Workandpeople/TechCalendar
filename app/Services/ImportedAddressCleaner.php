<?php

namespace App\Services;

class ImportedAddressCleaner
{
    public function clean(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $address = trim(preg_replace('/\s+/', ' ', $address) ?? '');

        if ($address === '') {
            return null;
        }

        $parts = array_map('trim', preg_split('/\s+-\s+/', $address) ?: []);

        if (count($parts) <= 1) {
            return $address;
        }

        $head = array_shift($parts);
        $noiseParts = array_filter($parts, fn (string $part): bool => $this->looksLikeCadastralReference($part));

        if ($head !== null && count($noiseParts) === count($parts)) {
            return $head;
        }

        return $address;
    }

    private function looksLikeCadastralReference(string $value): bool
    {
        $value = strtoupper(trim($value));

        return (bool) preg_match('/^\d{2,5}\s+[A-Z0-9]{1,4}\s+\d{2,6}$/', $value);
    }
}
