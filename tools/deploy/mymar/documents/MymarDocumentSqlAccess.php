<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use InvalidArgumentException;

/** Dedicated, initially locked accounts. Existing booking grants are never broadened. */
final class MymarDocumentSqlAccess
{
    public static function privileges(bool $writer = false, bool $documentDates = true): array
    {
        $tables = [
            'MYMAR.dossiers' => ['SELECT' => ['id', 'client_id', 'status_id', 'technicien_id', 'updated_at'], 'UPDATE' => ['status_id', 'sub_statut', 'updated_at']],
            'MYMAR.clients' => ['SELECT' => ['id', 'nom', 'prenom']],
            'MYMAR.dossier_rdv' => ['SELECT' => ['id', 'dossier_id', 'date_rdv', 'is_old', 'updated_at']],
            'MYMAR.dossier_documents' => ['SELECT' => ['id', 'dossier_id', 'nom', 'path', ...($documentDates ? ['created_at'] : [])],
                'INSERT' => ['dossier_id', 'author_id', 'path', 'nom', 'description', 'is_public', 'is_validated', 'request_data', 'created_at', 'updated_at']],
            'MYMAR.users' => ['SELECT' => ['id', 'enabled']],
            'MYMAR.roles' => ['SELECT' => ['id', 'name']],
            'MYMAR.model_has_roles' => ['SELECT' => ['model_id', 'model_type', 'role_id']],
            'MYMAR.status' => ['SELECT' => ['id', 'name', 'deleted_at']],
            'MYMAR.dossier_historiques' => ['INSERT' => ['author_id', 'dossier_id', 'status_id', 'text', 'created_at', 'updated_at']],
            'cofrac_mymar_integration.document_commands' => [
                'SELECT' => ['request_id', 'fingerprint', 'source_dossier_id', 'cofrac_actor_id', 'result'],
                'INSERT' => ['request_id', 'fingerprint', 'source_dossier_id', 'cofrac_actor_id', 'created_at'],
                'UPDATE' => ['result', 'completed_at'],
            ],
        ];

        return $writer ? $tables : array_diff_key(array_filter(array_map(static fn ($operations) => array_intersect_key($operations, ['SELECT' => true]), $tables)),
            ['cofrac_mymar_integration.document_commands' => true]);
    }

    public static function createSql(string $user, #[\SensitiveParameter] string $password, bool $writer = false): string
    {
        if (! preg_match('/^cofrac_mymar_'.($writer ? 'dw' : 'dr').'_[a-f0-9]{8}$/D', $user) || ! preg_match('/^Md4![a-f0-9]{64}$/D', $password)) {
            throw new InvalidArgumentException('mymar.invalid_document_account');
        }
        $sql = "CREATE USER '$user'@'localhost' IDENTIFIED BY '$password' ACCOUNT LOCK;\n";
        foreach (self::privileges($writer) as $table => $operations) {
            foreach ($operations as $verb => $columns) {
                $sql .= 'GRANT '.$verb.' (`'.implode('`,`', $columns).'`) ON `'.str_replace('.', '`.`', $table)."` TO '$user'@'localhost';\n";
            }
        }

        return $sql;
    }

    public static function dateAccessSql(string $user, bool $enable = true): string
    {
        if (! preg_match('/^cofrac_mymar_dr_[a-f0-9]{8}$/D', $user)) {
            throw new InvalidArgumentException('mymar.invalid_document_account');
        }

        return ($enable ? 'GRANT' : 'REVOKE').' SELECT (`created_at`) ON `MYMAR`.`dossier_documents` '
            .($enable ? 'TO' : 'FROM')." '$user'@'localhost';\n";
    }

    /** Returns date-read capability; the exact previous grants remain valid during rollout. */
    public static function validateGrants(array $grants, bool $writer = false): bool
    {
        $seen = [];
        $account = '`cofrac_mymar_'.($writer ? 'dw' : 'dr').'_[a-f0-9]{8}`@`localhost`';
        foreach ($grants as $grant) {
            if (! is_string($grant)) {
                throw new MymarDocumentFailure('documents_unsafe_grants', 503);
            }
            if (preg_match('/^GRANT USAGE ON \*\.\* TO '.$account.'$/D', $grant)) {
                continue;
            }
            if (! preg_match('/^GRANT (.+) ON `([a-zA-Z0-9_]+)`\.`([a-zA-Z0-9_]+)` TO '.$account.'$/D', $grant, $match)) {
                throw new MymarDocumentFailure('documents_unsafe_grants', 503);
            }
            preg_match_all('/(SELECT|INSERT|UPDATE) \(([^)]+)\)/', $match[1], $operations, PREG_SET_ORDER);
            if ($operations === [] || trim(preg_replace('/(SELECT|INSERT|UPDATE) \([^)]+\)/', '', $match[1]), ', ') !== '') {
                throw new MymarDocumentFailure('documents_unsafe_grants', 503);
            }
            foreach ($operations as $operation) {
                $table = $match[2].'.'.$match[3];
                if (isset($seen[$table][$operation[1]])) {
                    throw new MymarDocumentFailure('documents_unsafe_grants', 503);
                }
                $seen[$table][$operation[1]] = array_map(static fn ($column) => trim($column, ' `'), explode(',', $operation[2]));
            }
        }
        $normalize = static function (array $tables): array {
            foreach ($tables as &$operations) {
                foreach ($operations as &$columns) {
                    sort($columns);
                }
                unset($columns);
                ksort($operations);
            }
            unset($operations);
            ksort($tables);

            return $tables;
        };
        $seen = $normalize($seen);
        if ($seen === $normalize(self::privileges($writer))) {
            return true;
        }
        if ($seen === $normalize(self::privileges($writer, documentDates: false))) {
            return false;
        }

        throw new MymarDocumentFailure('documents_unsafe_grants', 503);
    }
}
