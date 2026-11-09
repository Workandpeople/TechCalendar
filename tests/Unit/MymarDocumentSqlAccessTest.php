<?php

use Cofrac2\Deploy\Mymar\MymarDocumentFailure;
use Cofrac2\Deploy\Mymar\MymarDocumentSqlAccess;

require_once dirname(__DIR__, 2).'/tools/deploy/mymar/documents/MymarDocumentFailure.php';
require_once dirname(__DIR__, 2).'/tools/deploy/mymar/documents/MymarDocumentSqlAccess.php';

function documentDateGrants(bool $writer = false, bool $dates = true): array
{
    $grants = [];
    foreach (MymarDocumentSqlAccess::privileges($writer, $dates) as $table => $operations) {
        foreach ($operations as $verb => $columns) {
            $grants[] = 'GRANT '.$verb.' (`'.implode('`,`', $columns).'`) ON `'.str_replace('.', '`.`', $table)
                .'` TO `cofrac_mymar_'.($writer ? 'dw' : 'dr').'_1234abcd`@`localhost`';
        }
    }

    return $grants;
}

it('accepts only the exact previous or date-aware permissions during a staged rollout', function (bool $writer, bool $dates) {
    expect(MymarDocumentSqlAccess::validateGrants(documentDateGrants($writer, $dates), $writer))->toBe($dates);
})->with([[false, false], [false, true], [true, false], [true, true]]);

it('never accepts unrelated extra columns global permissions or incomplete grants', function (string $case) {
    $grants = documentDateGrants();
    match ($case) {
        'global' => $grants[] = 'GRANT SELECT ON *.* TO `cofrac_mymar_dr_1234abcd`@`localhost`',
        'password' => $grants[] = 'GRANT SELECT (`password`) ON `MYMAR`.`users` TO `cofrac_mymar_dr_1234abcd`@`localhost`',
        'incomplete' => array_pop($grants),
        'updated_at' => $grants[3] = str_replace('`created_at`', '`updated_at`', $grants[3]),
        'write' => $grants[] = 'GRANT UPDATE (`nom`) ON `MYMAR`.`dossier_documents` TO `cofrac_mymar_dr_1234abcd`@`localhost`',
    };
    expect(fn () => MymarDocumentSqlAccess::validateGrants($grants))->toThrow(MymarDocumentFailure::class);
})->with(['global', 'password', 'incomplete', 'updated_at', 'write']);

it('targets only created_at on the existing read-only account without changing account state', function () {
    expect(MymarDocumentSqlAccess::dateAccessSql('cofrac_mymar_dr_1234abcd'))
        ->toBe("GRANT SELECT (`created_at`) ON `MYMAR`.`dossier_documents` TO 'cofrac_mymar_dr_1234abcd'@'localhost';\n")
        ->and(MymarDocumentSqlAccess::dateAccessSql('cofrac_mymar_dr_1234abcd', false))
        ->toBe("REVOKE SELECT (`created_at`) ON `MYMAR`.`dossier_documents` FROM 'cofrac_mymar_dr_1234abcd'@'localhost';\n");
    foreach (['root', 'cofrac_mymar_dw_1234abcd', "cofrac_mymar_dr_1234abcd'--", 'cofrac_mymar_dr_1234abcd@remote'] as $invalid) {
        expect(fn () => MymarDocumentSqlAccess::dateAccessSql($invalid))->toThrow(InvalidArgumentException::class);
    }
});
