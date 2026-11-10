<?php

use App\Services\Crm\DossierDuplicateCheck;

it('compares complete phone numbers without combining separate contacts or accepting junk', function (string $raw, array $expected) {
    expect(DossierDuplicateCheck::phones($raw))->toBe($expected);
})->with([
    ['06 12 34 56 78', ['+33612345678']],
    ['+33 (0)6.12.34.56.78', ['+33612345678']],
    ['0033 6 12 34 56 78', ['+33612345678']],
    ['33612345678', ['+33612345678']],
    ['612345678', ['+33612345678']],
    ['06.12.34.56.78 / 01-11-22-33-44 ; 0612345678', ['+33612345678', '+33111223344']],
    ['+262 692 12 34 56', ['+262692123456']],
    ['00692692123456', ['+692692123456']],
    ['', []], ['0000000000', []], ['inconnu', []], ['12345', []],
]);

it('normalizes full addresses but never equates different streets or town centroids', function () {
    $data = ['address' => '10 rue de l’Église', 'city' => 'Lyon', 'postal_code' => '69001'];
    $key = DossierDuplicateCheck::address($data);
    expect($key)->toBe(DossierDuplicateCheck::address([...$data, 'address' => '10 RUE DE L EGLISE, 69001 LYON, FRANCE']))
        ->and($key)->not->toBe(DossierDuplicateCheck::address([...$data, 'address' => '12 rue de l’Église']))
        ->and($key)->not->toBe(DossierDuplicateCheck::address([...$data, 'postal_code' => '69002']))
        ->and(DossierDuplicateCheck::address([...$data, 'address' => '69001 Lyon']))->toBeNull()
        ->and(DossierDuplicateCheck::address([...$data, 'address' => 'Lyon']))->toBeNull()
        ->and(DossierDuplicateCheck::address([...$data, 'address' => '']))->toBeNull();
    expect(DossierDuplicateCheck::address(['address' => 'Lieu-dit Le Pinier, 33820 Braud-et-St-Louis', 'postal_code' => '33820', 'city' => 'Braud et Saint Louis']))
        ->toBe(DossierDuplicateCheck::address(['address' => 'Lieu dit Le Pinier', 'postal_code' => '33820', 'city' => 'Braud-et-Saint-Louis']));
});
