<?php

use App\Models\Dossier;
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
