<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class LotSpreadsheetExtractor
{
    /**
     * @return Collection<int, array{row_number:int,data:array<string, string|null>}>
     */
    public function extract(UploadedFile $file): Collection
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->extractCsv($file),
            'xlsx' => $this->extractXlsx($file),
            default => throw new RuntimeException('Format non supporte. Utilise un fichier .xlsx ou .csv.'),
        };
    }

    /**
     * @return Collection<int, array{row_number:int,data:array<string, string|null>}>
     */
    private function extractCsv(UploadedFile $file): Collection
    {
        $path = $file->getRealPath();

        if (! $path) {
            throw new RuntimeException('Fichier temporaire introuvable.');
        }

        $delimiter = $this->detectCsvDelimiter($path);
        $handle = fopen($path, 'rb');

        if (! $handle) {
            throw new RuntimeException('Impossible de lire le fichier CSV.');
        }

        try {
            $headers = null;
            $rows = collect();
            $rowNumber = 0;

            while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;
                $values = array_map(fn ($value): ?string => $this->cleanCell($value), $line);

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($values);
                    continue;
                }

                $rows->push([
                    'row_number' => $rowNumber,
                    'data' => $this->combineRow($headers, $values),
                ]);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Collection<int, array{row_number:int,data:array<string, string|null>}>
     */
    private function extractXlsx(UploadedFile $file): Collection
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extension PHP zip manquante: impossible de lire les fichiers .xlsx.');
        }

        $path = $file->getRealPath();

        if (! $path) {
            throw new RuntimeException('Fichier temporaire introuvable.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Impossible d ouvrir le fichier .xlsx.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException('Feuille Excel introuvable.');
            }

            $worksheet = simplexml_load_string($sheetXml);

            if (! $worksheet instanceof SimpleXMLElement) {
                throw new RuntimeException('Feuille Excel invalide.');
            }

            $headers = null;
            $rows = collect();

            foreach ($worksheet->sheetData->row as $row) {
                $rowNumber = (int) ($row['r'] ?? 0);
                $valuesByColumn = [];

                foreach ($row->c as $cell) {
                    $reference = (string) ($cell['r'] ?? '');
                    $columnIndex = $this->columnIndexFromReference($reference);
                    $valuesByColumn[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
                }

                if ($valuesByColumn === []) {
                    continue;
                }

                ksort($valuesByColumn);
                $values = [];
                $maxColumn = max(array_keys($valuesByColumn));

                for ($column = 1; $column <= $maxColumn; $column++) {
                    $values[] = $valuesByColumn[$column] ?? null;
                }

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($values);
                    continue;
                }

                $rows->push([
                    'row_number' => $rowNumber,
                    'data' => $this->combineRow($headers, $values),
                ]);
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);

        if (! $sharedStrings instanceof SimpleXMLElement) {
            return [];
        }

        $values = [];

        foreach ($sharedStrings->si as $stringItem) {
            if (isset($stringItem->t)) {
                $values[] = $this->cleanCell((string) $stringItem->t) ?? '';
                continue;
            }

            $text = '';

            foreach ($stringItem->r as $run) {
                $text .= (string) $run->t;
            }

            $values[] = $this->cleanCell($text) ?? '';
        }

        return $values;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relationsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = simplexml_load_string($workbookXml);
        $relations = simplexml_load_string($relationsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $relations instanceof SimpleXMLElement) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $firstSheet = $workbook->sheets->sheet[0] ?? null;
        $relationshipId = $firstSheet?->attributes('r', true)?->id;

        if (! $relationshipId) {
            return 'xl/worksheets/sheet1.xml';
        }

        foreach ($relations->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== (string) $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');

            return str_starts_with($target, 'xl/')
                ? $target
                : 'xl/'.$target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function xlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            $index = (int) ($cell->v ?? -1);

            return $this->cleanCell($sharedStrings[$index] ?? null);
        }

        if ($type === 'inlineStr') {
            return $this->cleanCell((string) ($cell->is->t ?? ''));
        }

        return $this->cleanCell((string) ($cell->v ?? ''));
    }

    private function columnIndexFromReference(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(1, $index);
    }

    /**
     * @param array<int, string|null> $values
     * @return array<int, string>
     */
    private function normalizeHeaders(array $values): array
    {
        $headers = [];
        $seen = [];

        foreach ($values as $index => $value) {
            $header = trim((string) ($value ?: 'colonne_'.($index + 1)));
            $header = mb_substr($header, 0, 80);
            $dedupeKey = mb_strtolower($header);
            $seen[$dedupeKey] = ($seen[$dedupeKey] ?? 0) + 1;

            if ($seen[$dedupeKey] > 1) {
                $header .= ' '.$seen[$dedupeKey];
            }

            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string|null> $values
     * @return array<string, string|null>
     */
    private function combineRow(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = $values[$index] ?? null;
        }

        return $row;
    }

    private function cleanCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    /**
     * @param array<int, string|null> $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 4096) ?: '';
        $delimiters = [',' => 0, ';' => 0, "\t" => 0];

        foreach (array_keys($delimiters) as $delimiter) {
            $delimiters[$delimiter] = substr_count($sample, $delimiter);
        }

        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }
}
