<?php

namespace Tests\Support;

use App\Services\LotTemplateMapper;
use Illuminate\Http\UploadedFile;

class LotTemplateFile
{
    public static function row(array $overrides = []): array
    {
        return array_replace([
            'ALVEA-ACT-1616542/OP-2261616', 'MARTIN', 'Camille',
            '12 RUE DE LARGILIERE-000 AB 0152', '60110', 'ESCHES',
            '608637781', 'beneficiaire@example.test', 'Beneficiaire SAS',
            '10 Rue du Siege', '75002', 'Paris', '348808007', 'Installateur SAS',
        ], $overrides);
    }

    public static function upload(?array $rows = null): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        $headers = array_map(fn ($value) => preg_replace('/ 2$/', '', $value), LotTemplateMapper::HEADERS);
        foreach ([$headers, ...($rows ?? [self::row()])] as $row) {
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('lot-modele.csv', $csv);
    }
}
