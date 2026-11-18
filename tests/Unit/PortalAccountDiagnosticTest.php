<?php

require_once dirname(__DIR__, 2).'/tools/deploy/diagnose-portal-accounts.php';

function portalDiagnosticRow(array $overrides = []): array
{
    return array_replace(['id' => 10, 'first_name' => 'Compte', 'last_name' => 'Fictif', 'role' => 3, 'admin' => 0,
        'portal_enabled' => 1, 'deleted_at' => null, 'external_client_id' => 20, 'organisation_id' => 20,
        'organisation_name' => 'Societe fictive', 'organisation_kind' => 'client', 'organisation_active' => 1,
        'password' => 'PASSWORD_CANARY', 'email' => 'EMAIL_CANARY', 'phone' => 'PHONE_CANARY'], $overrides);
}

it('reports exactly the native account metadata that explains a portal preflight refusal', function (array $overrides, string $reason) {
    $report = PortalAccountDiagnostic::inspect([portalDiagnosticRow($overrides)]);
    expect($report['invalid_accounts'])->toHaveCount(1)->and($report['invalid_accounts'][0]['reasons'])->toBe([$reason])
        ->and($report['http_requests'])->toBeFalse()->and($report['accounts_changed'])->toBeFalse()
        ->and(json_encode($report))->not->toContain('CANARY', 'password', 'email', 'phone');
})->with([
    [['role' => 2], 'invalid_external_role'],
    [['role' => null], 'invalid_external_role'],
    [['admin' => 1], 'administrator_account'],
    [['external_client_id' => null], 'missing_organisation_link'],
    [['organisation_id' => null], 'organisation_not_found'],
    [['organisation_active' => 0], 'organisation_inactive'],
    [['organisation_active' => null], 'organisation_inactive'],
    [['organisation_kind' => 'unknown'], 'invalid_organisation_kind'],
]);

it('does not export valid disabled or deleted portal accounts', function () {
    $report = PortalAccountDiagnostic::inspect([portalDiagnosticRow(), portalDiagnosticRow(['organisation_kind' => 'delegataire']),
        portalDiagnosticRow(['portal_enabled' => 0, 'role' => 2]), portalDiagnosticRow(['deleted_at' => '2026-11-18 15:30:00', 'role' => 2])]);
    expect($report['invalid_accounts'])->toBe([])->and($report['truncated'])->toBeFalse()
        ->and(json_encode($report))->not->toContain('Societe fictive', 'Compte', 'Fictif');
});

it('bounds the report and queries only invalid enabled undeleted native account metadata', function () {
    $report = PortalAccountDiagnostic::inspect(array_fill(0, 101, portalDiagnosticRow(['organisation_active' => 0])));
    expect($report['invalid_accounts'])->toHaveCount(100)->and($report['truncated'])->toBeTrue();
    expect(PortalAccountDiagnostic::sql())->toContain('cofrac2production.users', 'external_delegataires', 'LIMIT 101',
        'u.portal_enabled=1 AND u.deleted_at IS NULL')->not->toMatch('/\b(?:UPDATE|INSERT|DELETE|ALTER|GRANT|MYMAR|dossiers|documents)\b/i');
    $code = file_get_contents(dirname(__DIR__, 2).'/tools/deploy/diagnose-portal-accounts.php');
    expect($code)->toContain('SET SESSION TRANSACTION READ ONLY', '@@transaction_read_only', '$db->rollBack()')
        ->not->toContain('bootstrap/app.php', '::dispatch(', '->send(');
});
