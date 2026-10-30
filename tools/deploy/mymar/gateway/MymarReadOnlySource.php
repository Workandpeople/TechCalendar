<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarReadWindow;
use PDO;
use RuntimeException;

final class MymarReadOnlySource
{
    public function __construct(private readonly PDO $database) {}

    public static function validateGrants(array $grants): void
    {
        $allowed = ['dossiers' => ['id', 'status_id', 'technicien_id'],
            'dossier_rdv' => ['date_rdv', 'dossier_id', 'duree', 'id', 'is_old']];
        $seen = [];
        foreach ($grants as $grant) {
            if (! is_string($grant)) {
                throw new RuntimeException('mymar.unsafe_sql_grants');
            }
            if (preg_match('/^GRANT USAGE ON \*\.\* TO `[^`]+`@`[^`]+`$/D', $grant)) {
                continue;
            }
            if (! preg_match('/^GRANT SELECT \(([^)]+)\) ON `MYMAR`\.`(dossiers|dossier_rdv)` TO `[^`]+`@`[^`]+`$/D', $grant, $match)) {
                throw new RuntimeException('mymar.unsafe_sql_grants');
            }
            $columns = array_map(static fn (string $column): string => trim($column, ' `'), explode(',', $match[1]));
            sort($columns);
            if ($columns !== $allowed[$match[2]] || isset($seen[$match[2]])) {
                throw new RuntimeException('mymar.unsafe_sql_grants');
            }
            $seen[$match[2]] = true;
        }
        if (count($seen) !== 2) {
            throw new RuntimeException('mymar.missing_sql_grants');
        }
    }

    public static function query(MymarReadWindow $window, MymarObservationPolicy $policy): array
    {
        [$from, $to] = $window->sourceBounds($policy->sourceTimezone);
        $technicians = implode(',', array_fill(0, count($window->technicianIds), '?'));
        $statuses = implode(',', array_fill(0, count($policy->statusIds), '?'));
        // Invalid dates/durations are returned for review even outside the date envelope, never hidden as free time.
        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(1500) */ r.id AS rdv_id,r.dossier_id,d.technicien_id AS technician_id,'
            .'d.status_id,r.is_old,r.date_rdv AS stored_start,r.duree AS raw_duration '
            .'FROM MYMAR.dossier_rdv r JOIN MYMAR.dossiers d ON d.id=r.dossier_id '
            ."WHERE r.is_old=0 AND d.technicien_id IN ($technicians) AND d.status_id IN ($statuses) "
            .'AND ((r.date_rdv>=? AND r.date_rdv<?) OR r.date_rdv IS NULL OR r.date_rdv<\'1000-01-01\' '
            .'OR r.duree IS NULL OR r.duree<1 OR r.duree>?) ORDER BY r.id LIMIT '.(MymarObservation::MAXIMUM_ROWS + 1);

        return [$sql, [...$window->technicianIds, ...$policy->statusIds, $from, $to, $policy->durationUnit === 'hours' ? 24 : 1440]];
    }

    public function fetch(MymarReadWindow $window, MymarObservationPolicy $policy): array
    {
        if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $this->database->inTransaction()) {
            throw new RuntimeException('mymar.invalid_sql_connection');
        }
        $this->database->exec('SET SESSION max_execution_time=1500');
        $this->database->exec('SET SESSION lock_wait_timeout=2');
        $this->database->exec('SET SESSION innodb_lock_wait_timeout=2');
        self::validateGrants($this->database->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
        $tables = $this->database->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('dossiers','dossier_rdv')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($tables) !== 2 || array_unique(array_values($tables)) !== ['InnoDB']) {
            throw new RuntimeException('mymar.transactional_source_required');
        }
        $this->database->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->database->exec('SET SESSION TRANSACTION READ ONLY');
        $this->database->exec('START TRANSACTION READ ONLY');
        try {
            if ((int) $this->database->query('SELECT @@transaction_read_only')->fetchColumn() !== 1) {
                throw new RuntimeException('mymar.read_only_transaction_required');
            }
            [$sql, $parameters] = self::query($window, $policy);
            $statement = $this->database->prepare($sql);
            $statement->execute($parameters);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > MymarObservation::MAXIMUM_ROWS) {
                throw new RuntimeException('mymar.snapshot_too_large');
            }

            return $rows;
        } finally {
            $this->database->rollBack();
        }
    }
}
