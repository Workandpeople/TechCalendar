<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Services\Crm\DossierTable;
use App\Services\ImportedAddressCleaner;

it('formats French numbers for reading and calling without changing stored values', function (?string $input, array $expected) {
    $dossier = new Dossier(['phone' => $input]);
    $dossier->syncOriginal();
    $table = new DossierTable(new ImportedAddressCleaner);
    expect($table->phoneNumbers($dossier))->toBe($expected)
        ->and($dossier->phone)->toBe($input)->and($dossier->getDirty())->toBeEmpty();
})->with([
    'lost mobile zero' => ['609285333', [['label' => '06 09 28 53 33', 'href' => 'tel:+33609285333']]],
    'lost landline zero' => ['148000123', [['label' => '01 48 00 01 23', 'href' => 'tel:+33148000123']]],
    'existing zero' => ['0687446911', [['label' => '06 87 44 69 11', 'href' => 'tel:+33687446911']]],
    'punctuation' => ['06.87.44.69.11', [['label' => '06 87 44 69 11', 'href' => 'tel:+33687446911']]],
    'international France' => ['+33 6 87 44 69 11', [['label' => '06 87 44 69 11', 'href' => 'tel:+33687446911']]],
    'optional national prefix' => ['+33 (0)6 87 44 69 11', [['label' => '06 87 44 69 11', 'href' => 'tel:+33687446911']]],
    'international dial prefix' => ['0033 6 87 44 69 11', [['label' => '06 87 44 69 11', 'href' => 'tel:+33687446911']]],
    'foreign prefix' => ['+32 470 12 34 56', [['label' => '+32 470 12 34 56', 'href' => 'tel:+32470123456']]],
    'overseas prefix' => ['+262 692 12 34 56', [['label' => '+262 692 12 34 56', 'href' => 'tel:+262692123456']]],
    'two numbers' => ['609285333 / 07 12 34 56 78', [
        ['label' => '06 09 28 53 33', 'href' => 'tel:+33609285333'], ['label' => '07 12 34 56 78', 'href' => 'tel:+33712345678'],
    ]],
    'different separators' => ["609285333; 0712345678\n01.23.45.67.89", [
        ['label' => '06 09 28 53 33', 'href' => 'tel:+33609285333'], ['label' => '07 12 34 56 78', 'href' => 'tel:+33712345678'],
        ['label' => '01 23 45 67 89', 'href' => 'tel:+33123456789'],
    ]],
    'short number' => ['112', [['label' => '112', 'href' => null]]],
    'annotation' => ['Secrétariat : 609285333', [['label' => 'Secrétariat : 609285333', 'href' => null]]],
    'ambiguous concatenation' => ['609285333 0712345678', [['label' => '609285333 0712345678', 'href' => null]]],
    'null' => [null, []], 'empty' => ['  ', []], 'separators only' => [' / ; ', []],
]);

it('only removes known cadastral noise for display while preserving useful address details and the original value', function (string $input, string $street) {
    $dossier = new Dossier(['address' => $input, 'postal_code' => '31370', 'city' => 'MONTGRAS']);
    $dossier->syncOriginal();
    $table = new DossierTable(new ImportedAddressCleaner);
    $address = $table->address($dossier);
    expect($address['street'])->toBe($street)->and($address['locality'])->toBe('31370 MONTGRAS')
        ->and($address['full'])->toBe($street.', 31370 MONTGRAS')
        ->and($address['original'])->toContain(trim($input))
        ->and($dossier->address)->toBe($input)->and($dossier->getDirty())->toBeEmpty();
})->with([
    ['1 IMPASSE BORDE DU BOSC - 000 OA 0647 - 000 OA 0659 - 000 OA 0699', '1 IMPASSE BORDE DU BOSC'],
    ['44 ROUTE DE VERNAIS-000 OA 0304-000 OA 0693', '44 ROUTE DE VERNAIS'],
    ['12 RUE DES LILAS - Bâtiment B - Appartement 4', '12 RUE DES LILAS - Bâtiment B - Appartement 4'],
    ["  12\u{00A0}RUE\nDES LILAS  ", '12 RUE DES LILAS'],
    ['000 OA 0647', '000 OA 0647'],
]);

it('handles missing address parts without inventing a street or location', function () {
    $table = new DossierTable(new ImportedAddressCleaner);
    expect($table->address(new Dossier)['full'])->toBe('');
    expect($table->address(new Dossier(['city' => 'Paris']))['full'])->toBe('Paris');
});

it('keeps the client and delegate distinct with safe manual-name fallbacks', function () {
    $table = new DossierTable(new ImportedAddressCleaner);
    $dossier = new Dossier(['company_name' => 'Bénéficiaire, pas la société cliente',
        'client_contact' => ['company_name' => '', 'name' => 'Société manuelle'],
        'delegataire_contact' => ['company_name' => ['malformed'], 'name' => 'Délégataire manuel']]);
    $dossier->setRelation('client', null)->setRelation('delegataire', null)->syncOriginal();
    expect($table->companies($dossier))->toBe(['client' => 'Société manuelle', 'delegataire' => 'Délégataire manuel'])
        ->and($dossier->getDirty())->toBeEmpty();
    $dossier->setRelation('client', new ExternalDelegataire(['company_name' => ' ', 'name' => '10 Travaux']))
        ->setRelation('delegataire', new ExternalDelegataire(['company_name' => 'Groupe distinct', 'name' => 'Contact']));
    expect($table->companies($dossier))->toBe(['client' => '10 Travaux', 'delegataire' => 'Groupe distinct']);
    $empty = (new Dossier(['company_name' => 'Bénéficiaire']))->setRelation('client', null)->setRelation('delegataire', null);
    expect($table->companies($empty))->toBe(['client' => '', 'delegataire' => '']);
});
