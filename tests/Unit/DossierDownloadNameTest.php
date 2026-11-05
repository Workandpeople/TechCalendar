<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Services\Crm\DossierDownloadName;

it('adds beneficiary last and first names to PDF downloads while retaining the report reference', function () {
    $dossier = new Dossier(['last_name' => 'GIRARD', 'first_name' => 'Claude Louis Maurice']);
    expect(DossierDownloadName::make('Rapport de contrôle - REF-123.pdf', 'pdf', $dossier))
        ->toBe('Rapport de contrôle - REF-123 - GIRARD Claude Louis Maurice.pdf');
});

it('keeps existing beneficiary names and handles companies without adding a duplicate extension', function () {
    $dossier = new Dossier(['company_name' => 'BATIMENT GIRARD CLAUDE 2']);
    expect(DossierDownloadName::make('Rapport inspection - BATIMENT GIRARD CLAUDE 2.PDF', 'pdf', $dossier))
        ->toBe('Rapport inspection - BATIMENT GIRARD CLAUDE 2.pdf');
    expect(DossierDownloadName::make('Rapport', 'pdf', $dossier))->toBe('Rapport - BATIMENT GIRARD CLAUDE 2.pdf');
    expect(DossierDownloadName::make('Photo', 'png', $dossier))->toBe('Photo.png');
});

it('limits UTF-8 filenames and removes path or header characters without losing the identity', function () {
    $dossier = new Dossier(['last_name' => "Nom/../\r\nTest", 'first_name' => 'Élodie']);
    $name = DossierDownloadName::make(str_repeat('É', 200).' <script>', 'pdf', $dossier);
    expect(strlen($name))->toBeLessThanOrEqual(194)->and(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and($name)->not->toContain('/', '\\', "\r", "\n", '<', '>')->and($name)->toContain('Élodie.pdf');
    expect(DossierDownloadName::make('', 'pdf', new Dossier(['reference' => 'INTERNAL'])))->toBe('Document.pdf');
});

it('replaces the report label with its own opinion for a configured organisation', function (string $outcome, string $prefix) {
    $organisation = new ExternalDelegataire(['report_download_naming' => ExternalDelegataire::REPORT_DOWNLOAD_OUTCOME_PREFIX]);
    $dossier = (new Dossier(['company_name' => 'BATIMENT CLIBERT DENIS']))->setRelation('delegataire', $organisation);
    expect(DossierDownloadName::make('Rapport de contrôle - ALVEA-ACT-1575343_OP-2195345.PDF', 'pdf', $dossier,
        isReport: true, reportOutcome: $outcome))->toBe($prefix.' - ALVEA-ACT-1575343_OP-2195345 - BATIMENT CLIBERT DENIS.pdf');
})->with([
    ['satisfactory', 'S'], ['Satisfaisant', 'S'], [' S ', 'S'],
    ['unsatisfactory', 'NS'], ['NON SATISFAISANT', 'NS'], ['NS', 'NS'],
    ['unverifiable', 'NV'], ['Inaccessible / non vérifiable', 'NV'], ['Non vérifiable', 'NV'], ['NV', 'NV'],
]);

it('keeps other organisations ordinary documents unknown opinions and non PDFs unchanged', function () {
    $organisation = new ExternalDelegataire(['report_download_naming' => ExternalDelegataire::REPORT_DOWNLOAD_STANDARD]);
    $dossier = (new Dossier(['last_name' => 'ALVEA']))->setRelation('client', $organisation);
    $title = 'Rapport de contrôle - ALVEA-ACT-123';
    expect(DossierDownloadName::make($title, 'pdf', $dossier, isReport: true, reportOutcome: 'satisfactory'))
        ->toBe($title.'.pdf');
    $organisation->report_download_naming = ExternalDelegataire::REPORT_DOWNLOAD_OUTCOME_PREFIX;
    foreach ([null, '', 'pending', 'Non conforme'] as $outcome) {
        expect(DossierDownloadName::make($title, 'pdf', $dossier, isReport: true, reportOutcome: $outcome))
            ->toBe($title.'.pdf');
    }
    expect(DossierDownloadName::make($title, 'pdf', $dossier, reportOutcome: 'satisfactory'))->toBe($title.'.pdf')
        ->and(DossierDownloadName::make($title, 'png', $dossier, isReport: true, reportOutcome: 'satisfactory'))->toBe($title.'.png')
        ->and(DossierDownloadName::make($title, 'pdf', isReport: true, reportOutcome: 'satisfactory'))->toBe($title.'.pdf');
});

it('preserves existing identities and prevents duplicate opinion prefixes on archived report titles', function () {
    $organisation = new ExternalDelegataire(['report_download_naming' => ExternalDelegataire::REPORT_DOWNLOAD_OUTCOME_PREFIX]);
    $dossier = (new Dossier(['last_name' => 'BESSIN', 'first_name' => 'ETANCHEITE']))->setRelation('client', $organisation);
    foreach (['RAPPORT DE CONTROLE - ACT-1575078 - BESSIN ETANCHEITE.pdf', 'S - ACT-1575078 - BESSIN ETANCHEITE.pdf'] as $name) {
        expect(DossierDownloadName::make($name, 'pdf', $dossier, isReport: true, reportOutcome: 'satisfactory'))
            ->toBe('S - ACT-1575078 - BESSIN ETANCHEITE.pdf');
    }
    expect(DossierDownloadName::make('Ancien rapport.pdf', 'pdf', $dossier, isReport: true, reportOutcome: 'unsatisfactory'))
        ->toBe('NS - Ancien rapport - BESSIN ETANCHEITE.pdf');
    expect(DossierDownloadName::make('Rapport de contrôle.pdf', 'pdf', $dossier, isReport: true, reportOutcome: 'satisfactory'))
        ->toBe('S - BESSIN ETANCHEITE.pdf');
});
