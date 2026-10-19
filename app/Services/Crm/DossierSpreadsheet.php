<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use XMLWriter;
use ZipArchive;

final class DossierSpreadsheet
{
    public function download(User $actor, array $filters): StreamedResponse
    {
        $query = app(DossierRegistry::class)->matchingQuery($actor, $filters);
        if ((clone $query)->count() > 50000) {
            throw ValidationException::withMessages(['q' => 'Limite de 50 000 dossiers par export. Affine les filtres.']);
        }
        $table = app(DossierTable::class);
        $query = $table->apply($query, $filters)->with([
            'client', 'delegataire', 'operations.service', 'operations.billing', 'latestStatusChange',
            'planningUser', 'listingAppointment.technician' => fn ($query) => $query->withTrashed(),
        ]);

        return response()->streamDownload(function () use ($query, $table): void {
            // Private temporary files, bounded memory; never place a generated export in public storage.
            $sheet = tempnam(sys_get_temp_dir(), 'dossiers-sheet-');
            $archive = tempnam(sys_get_temp_dir(), 'dossiers-xlsx-');
            try {
                if (! $sheet || ! $archive) {
                    throw new RuntimeException('Stockage temporaire indisponible.');
                }
                $xml = new XMLWriter;
                if (! $xml->openUri($sheet)) {
                    throw new RuntimeException('Export Excel impossible.');
                }
                $xml->startDocument('1.0', 'UTF-8');
                $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $xml->startElement('sheetViews');
                $xml->startElement('sheetView');
                $xml->writeAttribute('workbookViewId', '0');
                $xml->startElement('pane');
                foreach (['ySplit' => '1', 'topLeftCell' => 'A2', 'activePane' => 'bottomLeft', 'state' => 'frozen'] as $key => $value) {
                    $xml->writeAttribute($key, $value);
                }
                $xml->endElement();
                $xml->endElement();
                $xml->endElement();
                $xml->startElement('sheetData');
                $this->row($xml, ['Numéro de lot', 'Création', 'Modifié le', 'Modification', 'Date RDV', 'Bénéficiaire', 'Adresse', 'Code postal', 'Ville', 'Client', 'Délégataire', 'Téléphone', 'Prestations / états', 'Technicien', 'Suivi planning', 'Facturation']);
                $count = 1;
                foreach ($query->lazy(200) as $dossier) {
                    $this->row($xml, $this->values($dossier, $table));
                    $count++;
                }
                $xml->endElement();
                $xml->startElement('autoFilter');
                $xml->writeAttribute('ref', 'A1:P'.$count);
                $xml->endElement();
                $xml->endElement();
                $xml->endDocument();
                $xml->flush();
                unset($xml);
                $zip = new ZipArchive;
                if ($zip->open($archive, ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Archive Excel impossible.');
                }
                $parts = [
                    '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
                    '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                    'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Dossiers" sheetId="1" r:id="rId1"/></sheets></workbook>',
                    'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
                ];
                foreach ($parts as $path => $content) {
                    if (! $zip->addFromString($path, $content)) {
                        throw new RuntimeException('Archive Excel incomplète.');
                    }
                }
                if (! $zip->addFile($sheet, 'xl/worksheets/sheet1.xml') || ! $zip->close()) {
                    throw new RuntimeException('Archive Excel incomplète.');
                }
                readfile($archive);
            } finally {
                foreach ([$sheet, $archive] as $path) {
                    if (is_string($path) && is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }, 'dossiers-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function values(Dossier $dossier, DossierTable $table): array
    {
        $modification = $table->modification($dossier);

        return [$dossier->listing_lot, $dossier->created_at?->format('d/m/Y H:i'), $modification['date']?->format('d/m/Y H:i'),
            $modification['label'], $dossier->listingAppointment?->starts_at?->format('d/m/Y H:i'),
            $dossier->beneficiaryLabel(), $dossier->address, $dossier->postal_code, $dossier->city,
            $dossier->client?->company_name ?: $dossier->client?->name ?: ($dossier->client_contact['company_name'] ?? $dossier->client_contact['name'] ?? ''),
            $dossier->delegataire?->company_name ?: $dossier->delegataire?->name ?: ($dossier->delegataire_contact['company_name'] ?? $dossier->delegataire_contact['name'] ?? ''),
            $dossier->phone, $dossier->operations->map(fn ($op) => ($op->service?->name ?: $op->legacy_service_name).' : '.DossierStatusCatalog::describe($op->legacy_status)['label'])->implode("\n"),
            $dossier->listingAppointment?->technician?->full_name, $dossier->planningUser?->full_name, DossierBillingService::summary($dossier)['label']];
    }

    private function row(XMLWriter $xml, array $values): void
    {
        $xml->startElement('row');
        foreach ($values as $value) {
            // Inline strings preserve phone/postal leading zeroes and cannot execute Excel formulas.
            $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
            $xml->startElement('c');
            $xml->writeAttribute('t', 'inlineStr');
            $xml->startElement('is');
            $xml->writeElement('t', mb_substr($value, 0, 32767));
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->flush();
    }
}
