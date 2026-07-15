<?php

use App\Services\AppointmentDocumentSerializer;

it('ignores empty document payloads instead of creating a generic document', function () {
    $documents = app(AppointmentDocumentSerializer::class)->fromPayload([
        'document' => [
            'status' => 'created',
            'created_at' => '2026-07-14T10:00:00+02:00',
        ],
    ], 'coffrac');

    expect($documents)->toBe([]);
});

it('ignores coffrac fiche template documents', function () {
    $documents = app(AppointmentDocumentSerializer::class)->fromPayload([
        'fiche' => [
            'documents' => [[
                'name' => 'Document imbriqué de fiche',
                'path' => 'modele-imbrique.pdf',
            ]],
        ],
        'fiche_documents' => [[
            'name' => 'Document modèle de prestation',
            'path' => 'modele.pdf',
        ]],
    ], 'coffrac');

    expect($documents)->toBe([]);
});

it('keeps real document payloads', function () {
    $documents = app(AppointmentDocumentSerializer::class)->fromPayload([
        'documents' => [[
            'name' => 'Avis de passage',
            'path' => 'avis.pdf',
        ]],
    ], 'coffrac');

    expect($documents)->toHaveCount(1)
        ->and($documents[0]['name'])->toBe('Avis de passage')
        ->and($documents[0]['path'])->toBe('avis.pdf');
});
