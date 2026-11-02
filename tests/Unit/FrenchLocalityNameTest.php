<?php

use App\Services\MapboxGeocodingMatch;
use App\Support\FrenchLocalityName;

it('expands whole saint and sainte tokens without changing unrelated names', function (string $input, string $expected) {
    expect(FrenchLocalityName::normalize($input))->toBe($expected);
})->with([
    ['BRAUD ET ST LOUIS', 'braud et saint louis'], ['Braud-et-St.-Louis', 'braud et saint louis'],
    ['STE.-MARIE', 'sainte marie'], ['Ste Foy-lès-Lyon', 'sainte foy les lyon'],
    ['Saint-Philbert-de-Grand-Lieu', 'saint philbert de grand lieu'], ['Saintes', 'saintes'],
    ['Brest', 'brest'], ['Estaires', 'estaires'], ['Stains', 'stains'], ['Les Sables-d’Olonne', 'les sables d olonne'],
]);

it('also recognizes the abbreviation when validating a precise Mapbox address without weakening the postal check', function () {
    $feature = ['center' => [-0.658, 45.256], 'relevance' => 1, 'place_type' => ['address']];
    $parts = ['street_address' => '8 rue Test', 'postal_code' => '33820', 'city' => 'Braud-et-Saint-Louis', 'feature_type' => 'address'];
    $matcher = new MapboxGeocodingMatch;
    expect($matcher->accepts('8 rue Test, 33820 BRAUD ET ST LOUIS', $feature, $parts))->toBeTrue()
        ->and($matcher->accepts('8 rue Test, 33860 BRAUD ET ST LOUIS', $feature, $parts))->toBeFalse()
        ->and($matcher->accepts('8 rue Test, 33820 Et.auliers', $feature, $parts))->toBeFalse();
});
