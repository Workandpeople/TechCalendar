<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/** A real restricted SQL read through the authenticated handler, without an HTTP listener or CRM activation. */
final class MymarSqlGatewayPreparation
{
    public static function credentials(): array
    {
        return ['database_user' => 'cofrac_mymar_ro_'.bin2hex(random_bytes(4)),
            'database_password' => 'Mm2!'.bin2hex(random_bytes(32)), 'token' => bin2hex(random_bytes(32))];
    }

    public static function createSql(string $username, #[\SensitiveParameter] string $password): string
    {
        self::username($username);
        if (! preg_match('/^Mm2![a-f0-9]{64}$/D', $password)) {
            throw new RuntimeException('mymar.invalid_generated_password');
        }

        return "CREATE USER '$username'@'localhost' IDENTIFIED BY '$password' ACCOUNT LOCK;\n"
            ."GRANT SELECT (id,status_id,technicien_id) ON MYMAR.dossiers TO '$username'@'localhost';\n"
            ."GRANT SELECT (id,dossier_id,date_rdv,duree,is_old) ON MYMAR.dossier_rdv TO '$username'@'localhost';\n";
    }

    public static function accountSql(string $username, string $operation): string
    {
        self::username($username);

        return match ($operation) {
            'absent' => "SELECT COUNT(*) FROM mysql.user WHERE User='$username';",
            'unlock' => "ALTER USER '$username'@'localhost' ACCOUNT UNLOCK;",
            'lock' => "ALTER USER IF EXISTS '$username'@'localhost' ACCOUNT LOCK;",
            'locked' => "SELECT account_locked FROM mysql.user WHERE User='$username' AND Host='localhost';",
            default => throw new RuntimeException('mymar.invalid_account_operation'),
        };
    }

    public static function probe(#[\SensitiveParameter] array $config, array $freshMapping, DateTimeImmutable $now, \PDO $database): array
    {
        if (($config['enabled'] ?? null) !== false || ($freshMapping['format'] ?? null) !== 'cofrac.mymar-approved-technician-map'
            || ($freshMapping['connector_enabled'] ?? null) !== false || ($freshMapping['can_affect_planning'] ?? null) !== false
            || ! is_array($freshMapping['bindings'] ?? null) || $freshMapping['bindings'] === []) {
            throw new RuntimeException('mymar.disabled_preparation_required');
        }
        $policy = new MymarObservationPolicy(sourceTimezone: $config['source_timezone'], durationUnit: $config['duration_unit']);
        $from = $now->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0);
        $window = new MymarReadWindow($from->format(DATE_ATOM), $from->modify('+7 days')->format(DATE_ATOM), array_column($freshMapping['bindings'], 'source_technician_id'));
        $reads = 0;
        $failure = 'mymar.restricted_source_probe_failed';
        $read = static function (MymarReadWindow $scope, MymarObservationPolicy $rules) use ($config, $database, &$reads, &$failure): array {
            $reads++;
            try {
                $identity = $database->query('SELECT CURRENT_USER(), CURRENT_ROLE()')->fetch(\PDO::FETCH_NUM);
                if ($identity !== [$config['database_user'].'@localhost', 'NONE']) {
                    throw new RuntimeException('mymar.unexpected_restricted_account');
                }

                return (new MymarReadOnlySource($database))->fetch($scope, $rules);
            } catch (Throwable $exception) {
                if (preg_match('/^mymar\.[a-z_]+$/D', $exception->getMessage())) {
                    $failure = $exception->getMessage();
                }
                throw $exception;
            }
        };
        // The persisted switch remains false. Only this one-shot in-process handler is exercised.
        $handler = new MymarGateway(true, $config['token'], $policy, $read);
        $query = http_build_query($window->query());
        $denied = $handler->handle('GET', '/v1/occupations', $query, 'Bearer '.str_repeat('0', 64), '127.0.0.1', $now);
        if ($denied['status'] !== 403 || $reads !== 0) {
            throw new RuntimeException('mymar.handler_authentication_not_enforced');
        }
        $response = $handler->handle('GET', '/v1/occupations', $query, 'Bearer '.$config['token'], '127.0.0.1', $now);
        if ($response['status'] !== 200 || $reads !== 1) {
            throw new RuntimeException($failure);
        }
        $mapping = array_column($freshMapping['bindings'], 'cofrac_user_id', 'source_technician_id');
        $report = (new MymarObservation)->inspect($response['body'], $policy, $now, $mapping);
        foreach ($report['candidates'] as &$candidate) {
            $candidate['mapping_basis'] = 'operator_approved_and_freshly_revalidated';
        }
        unset($candidate);

        return array_replace($report, ['mode' => 'restricted_sql_authenticated_handler_probe', 'source_database_read' => true,
            'authenticated_handler_tested' => true, 'restricted_sql_account_verified' => true,
            'mapping_revalidated_at_utc' => $freshMapping['validated_at_utc'],
            'http_requests' => false, 'http_listener_started' => false, 'production_http_tested' => false,
            'global_technician_coverage_confirmed' => false]);
    }

    private static function username(string $username): void
    {
        if (! preg_match('/^cofrac_mymar_ro_[a-f0-9]{8}$/D', $username)) {
            throw new RuntimeException('mymar.invalid_generated_account');
        }
    }
}
