<?php

use App\Support\WorkspaceUi;

it('keeps functional colours consistent across roles', function (string $route, string $tone, string $icon) {
    $context = WorkspaceUi::forRoute($route);
    expect($context['tone'])->toBe($tone)
        ->and($context['icon'])->toBe($icon)
        ->and($context['steps'])->toHaveCount(3);
})->with([
    ['v2.dossiers.index', 'blue', 'dossiers'],
    ['v2.dossiers.show', 'blue', 'dossiers'],
    ['planner.book', 'teal', 'book'],
    ['planner.tracking', 'teal', 'planning'],
    ['manager.appointments', 'teal', 'planning'],
    ['manager.lots.show', 'ochre', 'lots'],
    ['client.lots.show', 'ochre', 'lots'],
    ['manager.delegataires', 'cyan', 'delegataires'],
    ['manager.clients.edit', 'cyan', 'delegataires'],
    ['manager.mail-templates', 'cyan', 'mail'],
    ['admin.settings', 'slate', 'settings'],
]);

it('gives external users their own read and response guidance without internal decisions', function () {
    $context = WorkspaceUi::forRoute('client.dossiers.show');
    expect($context['area'])->toBe('client-dossiers')
        ->and($context['label'])->toBe('Mes dossiers')
        ->and(json_encode($context))->not->toContain('Valider le rapport', 'Contrôle interne');
});

it('provides a safe presentation for an unknown route or status', function () {
    expect(WorkspaceUi::forRoute(null)['tone'])->toBe('slate')
        ->and(WorkspaceUi::status('unexpected'))->toBe(['dossiers', 'État à vérifier'])
        ->and(WorkspaceUi::status('non_compliant')[1])->toBe('Réponse du client attendue')
        ->and(WorkspaceUi::status('recontrol')[1])->toBe('Éléments à revérifier');
});
