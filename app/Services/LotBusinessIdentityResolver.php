<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LotBusinessIdentityResolver
{
    /**
     * @param array<string, mixed> $appointmentPayload
     * @param array{row_number:int,data:array<string, string|null>}|null $rawRowByNumber
     * @param array{row_number:int,data:array<string, string|null>}|null $rawRowByIndex
     * @return array{
     *     payload: array<string, string|null>|null,
     *     source: string,
     *     row_number: int|null,
     *     scores: array<string, int>
     * }
     */
    public function selectRawRow(array $appointmentPayload, ?array $rawRowByNumber, ?array $rawRowByIndex): array
    {
        $candidates = [];

        if (isset($rawRowByNumber['data']) && is_array($rawRowByNumber['data'])) {
            $candidates['row_number'] = [
                'payload' => $rawRowByNumber['data'],
                'row_number' => (int) ($rawRowByNumber['row_number'] ?? 0) ?: null,
                'score' => $this->rawRowCompatibilityScore($appointmentPayload, $rawRowByNumber['data']),
            ];
        }

        if (isset($rawRowByIndex['data']) && is_array($rawRowByIndex['data'])) {
            $candidates['index_fallback'] = [
                'payload' => $rawRowByIndex['data'],
                'row_number' => (int) ($rawRowByIndex['row_number'] ?? 0) ?: null,
                'score' => $this->rawRowCompatibilityScore($appointmentPayload, $rawRowByIndex['data']),
            ];
        }

        if ($candidates === []) {
            return [
                'payload' => null,
                'source' => 'missing',
                'row_number' => null,
                'scores' => [],
            ];
        }

        $scores = [];
        foreach ($candidates as $source => $candidate) {
            $scores[$source] = (int) $candidate['score'];
        }

        $selectedSource = 'row_number';

        if (isset($candidates['index_fallback'])) {
            $rowNumberScore = $candidates['row_number']['score'] ?? -1;
            $indexScore = $candidates['index_fallback']['score'];

            if (
                ! isset($candidates['row_number'])
                || $indexScore > $rowNumberScore
                || (
                    $indexScore === $rowNumberScore
                    && $candidates['index_fallback']['row_number'] !== $candidates['row_number']['row_number']
                )
            ) {
                $selectedSource = 'index_fallback';
            }
        }

        $selected = $candidates[$selectedSource];

        return [
            'payload' => $selected['payload'],
            'source' => $selectedSource,
            'row_number' => $selected['row_number'],
            'scores' => $scores,
        ];
    }

    /**
     * @param Collection<int, array{row_number:int,data:array<string, string|null>}> $rows
     * @return array{
     *     source:string,
     *     header_row_number:int|null,
     *     beneficiary_key:string|null,
     *     beneficiary_header:string|null,
     *     installer_key:string|null,
     *     installer_header:string|null,
     *     ignored_headers:array<int, string>
     * }
     */
    public function buildColumnMapping(Collection $rows): array
    {
        $mapping = $this->emptyColumnMapping();
        $firstData = $rows->first()['data'] ?? null;

        if (is_array($firstData)) {
            $mapping = $this->columnMappingFromHeaders($firstData, 'raw_headers', null);

            if ($mapping['beneficiary_key'] !== null || $mapping['installer_key'] !== null) {
                $this->logColumnMapping($mapping);

                return $mapping;
            }
        }

        $bestMapping = $this->emptyColumnMapping();
        $bestScore = -1;

        foreach ($rows->take(8) as $row) {
            $rowData = $row['data'] ?? null;

            if (! is_array($rowData)) {
                continue;
            }

            $candidate = $this->columnMappingFromHeaderValues(
                $rowData,
                (int) ($row['row_number'] ?? 0) ?: null,
            );
            $score = $this->columnMappingScore($candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMapping = $candidate;
            }

            if ($candidate['beneficiary_key'] !== null && $candidate['installer_key'] !== null) {
                $this->logColumnMapping($candidate);

                return $candidate;
            }
        }

        $this->logColumnMapping($bestMapping);

        return $bestMapping;
    }

    /**
     * @param array<string, mixed> $appointmentPayload
     * @param array<string, string|null>|null $rawRow
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function apply(array $appointmentPayload, ?array $rawRow, array $context = [], ?array $columnMapping = null): array
    {
        if (! is_array($rawRow) || $rawRow === []) {
            Log::warning('Lot import business identity: raw row missing, AI values kept.', [
                'context' => $context,
                'ai_company_name' => $this->shortValue($appointmentPayload['company_name'] ?? null),
                'ai_installer_name' => $this->shortValue($appointmentPayload['installer_name'] ?? null),
            ]);

            return $appointmentPayload;
        }

        $beforeCompanyName = $this->nullableString($appointmentPayload['company_name'] ?? null);
        $beforeInstallerName = $this->nullableString($appointmentPayload['installer_name'] ?? null);
        $businessColumns = [];
        $beneficiaryColumn = $this->mappedColumn($rawRow, $columnMapping, 'beneficiary');
        $installerColumn = $this->mappedColumn($rawRow, $columnMapping, 'installer');

        if ($columnMapping === null || ($beneficiaryColumn === null && $installerColumn === null)) {
            $businessColumns = $this->businessColumns($rawRow);
            $beneficiaryColumn = $beneficiaryColumn
                ?? $this->firstColumnOfType($businessColumns, 'beneficiary');
            $installerColumn = $installerColumn
                ?? $this->firstColumnOfType($businessColumns, 'installer');
        }

        $beneficiaryCompany = $beneficiaryColumn['value'] ?? null;
        $installerName = $installerColumn['value'] ?? null;

        if ($beneficiaryColumn !== null) {
            $appointmentPayload['company_name'] = $beneficiaryCompany;
            $appointmentPayload['customer_name'] = $beneficiaryCompany;
        }

        if ($installerColumn !== null) {
            $appointmentPayload['installer_name'] = $installerName;
        }

        $logPayload = [
            'context' => $context,
            'matched_beneficiary_header' => $beneficiaryColumn['header'] ?? null,
            'matched_installer_header' => $installerColumn['header'] ?? null,
            'ai_company_name' => $this->shortValue($beforeCompanyName),
            'ai_installer_name' => $this->shortValue($beforeInstallerName),
            'resolved_company_name' => $this->shortValue($appointmentPayload['company_name'] ?? null),
            'resolved_installer_name' => $this->shortValue($appointmentPayload['installer_name'] ?? null),
            'column_mapping' => $columnMapping,
        ];

        if ($beneficiaryColumn === null || $installerColumn === null) {
            Log::warning('Lot import business identity: incomplete column mapping.', $logPayload + [
                'business_headers' => collect($businessColumns)->pluck('header')->values()->all(),
                'raw_headers' => array_keys($rawRow),
            ]);
        } else {
            Log::warning('Lot import business identity resolved.', $logPayload);
        }

        return $appointmentPayload;
    }

    /**
     * @return array{
     *     source:string,
     *     header_row_number:int|null,
     *     beneficiary_key:string|null,
     *     beneficiary_header:string|null,
     *     installer_key:string|null,
     *     installer_header:string|null,
     *     ignored_headers:array<int, string>
     * }
     */
    private function emptyColumnMapping(): array
    {
        return [
            'source' => 'missing',
            'header_row_number' => null,
            'beneficiary_key' => null,
            'beneficiary_header' => null,
            'installer_key' => null,
            'installer_header' => null,
            'ignored_headers' => [],
        ];
    }

    /**
     * @param array<string, string|null> $rawRow
     * @return array{
     *     source:string,
     *     header_row_number:int|null,
     *     beneficiary_key:string|null,
     *     beneficiary_header:string|null,
     *     installer_key:string|null,
     *     installer_header:string|null,
     *     ignored_headers:array<int, string>
     * }
     */
    private function columnMappingFromHeaders(array $rawRow, string $source, ?int $headerRowNumber): array
    {
        $mapping = $this->emptyColumnMapping();
        $mapping['source'] = $source;
        $mapping['header_row_number'] = $headerRowNumber;

        foreach (array_keys($rawRow) as $key) {
            $this->assignColumnMapping($mapping, (string) $key, (string) $key);
        }

        return $mapping;
    }

    /**
     * @param array<string, string|null> $rawRow
     * @return array{
     *     source:string,
     *     header_row_number:int|null,
     *     beneficiary_key:string|null,
     *     beneficiary_header:string|null,
     *     installer_key:string|null,
     *     installer_header:string|null,
     *     ignored_headers:array<int, string>
     * }
     */
    private function columnMappingFromHeaderValues(array $rawRow, ?int $headerRowNumber): array
    {
        $mapping = $this->emptyColumnMapping();
        $mapping['source'] = 'header_row_values';
        $mapping['header_row_number'] = $headerRowNumber;

        foreach ($rawRow as $key => $headerCandidate) {
            $headerCandidate = $this->nullableString($headerCandidate);

            if ($headerCandidate === null) {
                continue;
            }

            $this->assignColumnMapping($mapping, (string) $key, $headerCandidate);
        }

        return $mapping;
    }

    /**
     * @param array{
     *     source:string,
     *     header_row_number:int|null,
     *     beneficiary_key:string|null,
     *     beneficiary_header:string|null,
     *     installer_key:string|null,
     *     installer_header:string|null,
     *     ignored_headers:array<int, string>
     * } $mapping
     */
    private function assignColumnMapping(array &$mapping, string $key, string $header): void
    {
        $normalizedHeader = $this->normalizeHeader($header);

        if (! str_contains($normalizedHeader, 'raison social')
            && ! str_contains($normalizedHeader, 'raison sociale')
            && ! $this->hasBeneficiaryKeyword($normalizedHeader)
            && ! $this->hasInstallerKeyword($normalizedHeader)) {
            return;
        }

        if ($this->isIgnoredBusinessHeader($normalizedHeader) || $this->isExcludedBusinessHeader($normalizedHeader)) {
            $mapping['ignored_headers'][] = $header;

            return;
        }

        $type = $this->columnType($normalizedHeader);

        if (
            $type === 'beneficiary'
            && (
                $mapping['beneficiary_key'] === null
                || $this->businessHeaderPriority($normalizedHeader, 'beneficiary') > $this->businessHeaderPriority(
                    $this->normalizeHeader((string) $mapping['beneficiary_header']),
                    'beneficiary',
                )
            )
        ) {
            $mapping['beneficiary_key'] = $key;
            $mapping['beneficiary_header'] = $header;

            return;
        }

        if (
            $type === 'installer'
            && (
                $mapping['installer_key'] === null
                || $this->businessHeaderPriority($normalizedHeader, 'installer') > $this->businessHeaderPriority(
                    $this->normalizeHeader((string) $mapping['installer_header']),
                    'installer',
                )
            )
        ) {
            $mapping['installer_key'] = $key;
            $mapping['installer_header'] = $header;
        }
    }

    /**
     * @param array{
     *     source:string,
     *     header_row_number:int|null,
     *     beneficiary_key:string|null,
     *     beneficiary_header:string|null,
     *     installer_key:string|null,
     *     installer_header:string|null,
     *     ignored_headers:array<int, string>
     * } $mapping
     */
    private function columnMappingScore(array $mapping): int
    {
        return ($mapping['beneficiary_key'] !== null ? 10 : 0)
            + ($mapping['installer_key'] !== null ? 10 : 0)
            + count($mapping['ignored_headers']);
    }

    /**
     * @param array<string, string|null> $rawRow
     * @param array<string, mixed>|null $columnMapping
     * @return array{header:string,normalized_header:string,value:string|null,type:string}|null
     */
    private function mappedColumn(array $rawRow, ?array $columnMapping, string $type): ?array
    {
        if ($columnMapping === null) {
            return null;
        }

        $key = $columnMapping[$type.'_key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $header = (string) ($columnMapping[$type.'_header'] ?? $key);

        return [
            'header' => $header,
            'normalized_header' => $this->normalizeHeader($header),
            'value' => $this->nullableString($rawRow[$key] ?? null),
            'type' => $type,
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function logColumnMapping(array $mapping): void
    {
        Log::warning('Lot import business identity column mapping selected.', [
            'source' => $mapping['source'] ?? null,
            'header_row_number' => $mapping['header_row_number'] ?? null,
            'beneficiary_key' => $mapping['beneficiary_key'] ?? null,
            'beneficiary_header' => $mapping['beneficiary_header'] ?? null,
            'installer_key' => $mapping['installer_key'] ?? null,
            'installer_header' => $mapping['installer_header'] ?? null,
            'ignored_headers' => $mapping['ignored_headers'] ?? [],
        ]);
    }

    /**
     * @param array<string, string|null> $rawRow
     * @return array<int, array{header:string,normalized_header:string,value:string,type:string|null}>
     */
    private function businessColumns(array $rawRow): array
    {
        $columns = [];

        foreach ($rawRow as $header => $value) {
            $value = $this->nullableString($value);
            $normalizedHeader = $this->normalizeHeader((string) $header);

            if ($value === null || ! $this->isBusinessCompanyHeader($normalizedHeader)) {
                continue;
            }

            $columns[] = [
                'header' => (string) $header,
                'normalized_header' => $normalizedHeader,
                'value' => $value,
                'type' => $this->columnType($normalizedHeader),
            ];
        }

        return $columns;
    }

    /**
     * @param array<int, array{header:string,normalized_header:string,value:string,type:string|null}> $columns
     * @return array{header:string,normalized_header:string,value:string,type:string|null}|null
     */
    private function firstColumnOfType(array $columns, string $type): ?array
    {
        $selectedColumn = null;
        $selectedScore = -1;

        foreach ($columns as $column) {
            if ($column['type'] !== $type) {
                continue;
            }

            $score = $this->businessHeaderPriority($column['normalized_header'], $type);

            if ($score > $selectedScore) {
                $selectedColumn = $column;
                $selectedScore = $score;
            }
        }

        return $selectedColumn;
    }

    /**
     * @param array<int, array{header:string,normalized_header:string,value:string,type:string|null}> $columns
     * @return array{header:string,normalized_header:string,value:string,type:string|null}|null
     */
    private function fallbackBeneficiaryColumn(array $columns): ?array
    {
        $nonInstallerColumns = array_values(array_filter(
            $columns,
            fn (array $column): bool => $column['type'] !== 'installer',
        ));

        return $nonInstallerColumns[0] ?? null;
    }

    /**
     * @param array<int, array{header:string,normalized_header:string,value:string,type:string|null}> $columns
     * @param array{header:string,normalized_header:string,value:string,type:string|null}|null $beneficiaryColumn
     * @return array{header:string,normalized_header:string,value:string,type:string|null}|null
     */
    private function fallbackInstallerColumn(array $columns, ?array $beneficiaryColumn): ?array
    {
        foreach ($columns as $column) {
            if ($beneficiaryColumn !== null && $column['header'] === $beneficiaryColumn['header']) {
                continue;
            }

            return $column;
        }

        return null;
    }

    private function isBusinessCompanyHeader(string $header): bool
    {
        if ($this->isIgnoredBusinessHeader($header) || $this->isExcludedBusinessHeader($header)) {
            return false;
        }

        return str_contains($header, 'raison sociale')
            || str_contains($header, 'raison social')
            || (str_contains($header, 'societe') && $this->hasInstallerKeyword($header))
            || (str_contains($header, 'entreprise') && $this->hasInstallerKeyword($header))
            || (str_contains($header, 'beneficiaire') && ! str_contains($header, 'telephone'))
            || (str_contains($header, 'professionnel') && ! str_contains($header, 'telephone'));
    }

    private function isExcludedBusinessHeader(string $header): bool
    {
        return str_contains($header, 'siret')
            || str_contains($header, 'siren')
            || str_contains($header, 'telephone')
            || str_contains($header, 'tel ')
            || str_contains($header, 'mobile')
            || str_contains($header, 'email')
            || str_contains($header, 'mail')
            || str_contains($header, 'adresse')
            || str_contains($header, 'code postal')
            || str_contains($header, 'ville')
            || str_contains($header, 'commune');
    }

    private function isIgnoredBusinessHeader(string $header): bool
    {
        return str_contains($header, 'demandeur')
            || str_contains($header, 'delegataire')
            || str_contains($header, 'oblige')
            || str_contains($header, 'obligation');
    }

    private function columnType(string $header): ?string
    {
        if ($this->hasInstallerKeyword($header)) {
            return 'installer';
        }

        if ($this->hasBeneficiaryKeyword($header)) {
            return 'beneficiary';
        }

        return null;
    }

    private function businessHeaderPriority(string $header, string $type): int
    {
        $hasRaisonSociale = str_contains($header, 'raison sociale')
            || str_contains($header, 'raison social');
        $score = 0;

        if ($hasRaisonSociale) {
            $score += 100;
        }

        if ($type === 'beneficiary' && $this->hasBeneficiaryKeyword($header)) {
            $score += 50;
        }

        if ($type === 'installer' && $this->hasInstallerKeyword($header)) {
            $score += 50;
        }

        if (str_contains($header, 'nom du site') || str_contains($header, 'site beneficiaire')) {
            $score -= 40;
        }

        if (str_contains($header, 'sous traitant') || str_contains($header, 'sous-traitant')) {
            $score -= 25;
        }

        if (str_contains($header, 'controle')) {
            $score -= 20;
        }

        return $score;
    }

    private function hasBeneficiaryKeyword(string $header): bool
    {
        return str_contains($header, 'beneficiaire')
            || str_contains($header, 'client final')
            || str_contains($header, 'maitre ouvrage')
            || str_contains($header, 'maitrise ouvrage')
            || str_contains($header, 'donneur ordre');
    }

    private function hasInstallerKeyword(string $header): bool
    {
        return str_contains($header, 'professionnel')
            || str_contains($header, 'installateur')
            || str_contains($header, 'installatrice')
            || str_contains($header, 'entreprise travaux')
            || str_contains($header, 'artisan')
            || str_contains($header, 'mandataire')
            || str_contains($header, 'societe travaux');
    }

    /**
     * @param array<string, mixed> $appointmentPayload
     * @param array<string, string|null> $rawRow
     */
    private function rawRowCompatibilityScore(array $appointmentPayload, array $rawRow): int
    {
        $rawText = $this->searchableText(array_values($rawRow));
        $rawPhone = $this->comparablePhone(implode(' ', array_map(
            fn (mixed $value): string => (string) $value,
            array_values($rawRow),
        )));
        $score = 0;

        $payloadPhone = $this->comparablePhone($appointmentPayload['customer_phone'] ?? null);
        if ($payloadPhone !== null && $rawPhone !== null && $payloadPhone === $rawPhone) {
            $score += 30;
        }

        foreach ([
            'postal_code' => 25,
            'city' => 20,
            'address' => 20,
            'address_line' => 20,
            'customer_name' => 10,
            'company_name' => 8,
            'site_name' => 5,
        ] as $field => $weight) {
            $needle = $this->searchableValue($appointmentPayload[$field] ?? null);

            if ($needle === null) {
                continue;
            }

            if (str_contains($rawText, $needle)) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function searchableText(array $values): string
    {
        return $this->searchableValue(implode(' ', array_filter(
            array_map(fn (mixed $value): string => (string) $value, $values),
            fn (string $value): bool => trim($value) !== '',
        ))) ?? '';
    }

    private function searchableValue(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $value = $this->normalizeHeader($value);

        return mb_strlen($value) >= 3 ? $value : null;
    }

    private function comparablePhone(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '33') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) < 9) {
            return null;
        }

        return substr($digits, -10);
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

        $header = mb_strtolower($header);
        $header = preg_replace('/[^\pL\pN]+/u', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/u', ' ', $header) ?? '');
    }

    private function shortValue(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_substr($value, 0, 120);
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
