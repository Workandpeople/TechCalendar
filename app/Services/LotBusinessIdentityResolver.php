<?php

namespace App\Services;

class LotBusinessIdentityResolver
{
    /**
     * @param array<string, mixed> $appointmentPayload
     * @param array<string, string|null>|null $rawRow
     * @return array<string, mixed>
     */
    public function apply(array $appointmentPayload, ?array $rawRow): array
    {
        if (! is_array($rawRow) || $rawRow === []) {
            return $appointmentPayload;
        }

        $beneficiaryCompany = $this->firstMatchingValue($rawRow, fn (string $header): bool => $this->isBeneficiaryCompanyHeader($header));
        $installerName = $this->firstMatchingValue($rawRow, fn (string $header): bool => $this->isInstallerCompanyHeader($header));

        if ($beneficiaryCompany !== null) {
            $appointmentPayload['company_name'] = $beneficiaryCompany;
        }

        if ($installerName !== null) {
            $appointmentPayload['installer_name'] = $installerName;
        }

        return $appointmentPayload;
    }

    /**
     * @param array<string, string|null> $rawRow
     */
    private function firstMatchingValue(array $rawRow, callable $matcher): ?string
    {
        foreach ($rawRow as $header => $value) {
            $value = $this->nullableString($value);

            if ($value === null || ! $matcher($this->normalizeHeader((string) $header))) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function isBeneficiaryCompanyHeader(string $header): bool
    {
        return str_contains($header, 'raison sociale')
            && (
                str_contains($header, 'beneficiaire')
                || str_contains($header, 'client final')
                || str_contains($header, 'maitre ouvrage')
            );
    }

    private function isInstallerCompanyHeader(string $header): bool
    {
        return str_contains($header, 'raison sociale')
            && (
                str_contains($header, 'professionnel')
                || str_contains($header, 'installateur')
                || str_contains($header, 'societe installatrice')
                || str_contains($header, 'entreprise travaux')
                || str_contains($header, 'artisan')
            );
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtr($header, [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'Ç' => 'C', 'ç' => 'c',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ý' => 'Y', 'Ÿ' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'Œ' => 'OE',
        ]);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($header)) ?? '');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
