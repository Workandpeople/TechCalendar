<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use RuntimeException;

/** Separate accounts from the live observation gateway; never broaden its permissions. */
final class MymarBookingSqlAccess
{
    public static function credentials(bool $readOnly = false): array
    {
        return ['database_user' => 'cofrac_mymar_'.($readOnly ? 'br_' : 'rw_').bin2hex(random_bytes(4)),
            'database_password' => 'Mb3!'.bin2hex(random_bytes(32)), 'token' => bin2hex(random_bytes(32))];
    }

    public static function privileges(bool $readOnly = false): array
    {
        $privileges = [
            'MYMAR.dossiers' => [
                'SELECT' => ['id', 'client_id', 'status_id', 'technicien_id', 'updated_at', 'created_at', 'mar_type', 'sub_statut', 'commentaire_status', 'date_rappel'],
                'UPDATE' => ['status_id', 'technicien_id', 'sub_statut', 'commentaire_status', 'date_rappel', 'updated_at'],
            ],
            'MYMAR.clients' => ['SELECT' => ['id', 'nom', 'prenom', 'adress', 'zip', 'ville', 'phone', 'mobile', 'email', 'latitude', 'longitude', 'updated_at']],
            'MYMAR.dossier_rdv' => [
                'SELECT' => ['id', 'dossier_id', 'date_rdv', 'duree', 'is_old', 'updated_at'],
                'INSERT' => ['dossier_id', 'author_id', 'date_rdv', 'duree', 'is_old', 'created_at', 'updated_at'],
                'UPDATE' => ['is_old', 'updated_at'],
            ],
            'MYMAR.dossier_historiques' => ['INSERT' => ['author_id', 'dossier_id', 'status_id', 'text', 'created_at', 'updated_at']],
            'MYMAR.users' => ['SELECT' => ['id', 'enabled']],
            'MYMAR.roles' => ['SELECT' => ['id', 'name']],
            'MYMAR.model_has_roles' => ['SELECT' => ['model_id', 'model_type', 'role_id']],
            'MYMAR.status' => ['SELECT' => ['id', 'deleted_at']],
            'cofrac_mymar_integration.booking_commands' => [
                'SELECT' => ['request_id', 'fingerprint', 'source_dossier_id', 'cofrac_actor_id', 'action', 'result', 'created_at', 'completed_at'],
                'INSERT' => ['request_id', 'fingerprint', 'source_dossier_id', 'cofrac_actor_id', 'action', 'result', 'created_at', 'completed_at'],
                'UPDATE' => ['result', 'completed_at'],
            ],
        ];
        if ($readOnly) {
            $privileges = array_filter(array_map(static fn ($operations) => array_intersect_key($operations, ['SELECT' => true]), $privileges));
        }

        return $privileges;
    }

    public static function createSql(string $username, #[\SensitiveParameter] string $password, bool $readOnly = false): string
    {
        if (! preg_match('/^cofrac_mymar_'.($readOnly ? 'br' : 'rw').'_[a-f0-9]{8}$/D', $username) || ! preg_match('/^Mb3![a-f0-9]{64}$/D', $password)) {
            throw new RuntimeException('mymar.invalid_generated_booking_account');
        }
        $sql = "CREATE USER '$username'@'localhost' IDENTIFIED BY '$password' ACCOUNT LOCK;\n";
        foreach (self::privileges($readOnly) as $table => $operations) {
            foreach ($operations as $verb => $columns) {
                $sql .= 'GRANT '.$verb.' (`'.implode('`,`', $columns).'`) ON '.str_replace('.', '`.`', '`'.$table.'`')
                    ." TO '$username'@'localhost';\n";
            }
        }

        return $sql;
    }

    public static function validateGrants(array $grants, bool $readOnly = false): void
    {
        $seen = [];
        $account = '`cofrac_mymar_'.($readOnly ? 'br' : 'rw').'_[a-f0-9]{8}`@`localhost`';
        foreach ($grants as $grant) {
            if (! is_string($grant)) {
                throw new RuntimeException('mymar.unsafe_booking_grants');
            }
            if (preg_match('/^GRANT USAGE ON \*\.\* TO '.$account.'$/D', $grant)) {
                continue;
            }
            if (! preg_match('/^GRANT (.+) ON `([a-zA-Z0-9_]+)`\.`([a-zA-Z0-9_]+)` TO '.$account.'$/D', $grant, $match)) {
                throw new RuntimeException('mymar.unsafe_booking_grants');
            }
            $table = $match[2].'.'.$match[3];
            preg_match_all('/(SELECT|INSERT|UPDATE) \(([^)]+)\)/', $match[1], $operations, PREG_SET_ORDER);
            if (trim(preg_replace('/(SELECT|INSERT|UPDATE) \([^)]+\)/', '', $match[1]), ', ') !== '' || $operations === []) {
                throw new RuntimeException('mymar.unsafe_booking_grants');
            }
            foreach ($operations as $operation) {
                $verb = $operation[1];
                $columns = array_map(static fn ($column) => trim($column, ' `'), explode(',', $operation[2]));
                if (isset($seen[$table][$verb])) {
                    throw new RuntimeException('mymar.unsafe_booking_grants');
                }
                sort($columns);
                $seen[$table][$verb] = $columns;
            }
        }
        $expected = self::privileges($readOnly);
        foreach ($expected as &$operations) {
            foreach ($operations as &$columns) {
                sort($columns);
            }
            unset($columns);
            ksort($operations);
        }
        unset($operations);
        foreach ($seen as &$operations) {
            ksort($operations);
        }
        unset($operations);
        ksort($expected);
        ksort($seen);
        if ($expected !== $seen) {
            throw new RuntimeException('mymar.unsafe_booking_grants');
        }
    }
}
