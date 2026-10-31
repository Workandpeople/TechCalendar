<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** Isolated integration adapter, not installed and not called by any application route. */
final class MymarBookingSource
{
    private const RECORD = 'd.id,d.client_id,d.status_id,d.technicien_id,d.updated_at,d.created_at,d.mar_type,d.sub_statut,d.commentaire_status,d.date_rappel,'
        .'c.nom,c.prenom,c.adress,c.zip,c.ville,c.phone,c.mobile,c.email,c.latitude,c.longitude,c.updated_at AS client_updated_at';

    private const REVISION_FIELDS = ['id', 'client_id', 'status_id', 'technicien_id', 'updated_at', 'created_at', 'mar_type',
        'sub_statut', 'commentaire_status', 'date_rappel', 'nom', 'prenom', 'adress', 'zip', 'ville', 'phone', 'mobile',
        'email', 'latitude', 'longitude', 'client_updated_at'];

    public function __construct(
        private PDO $database,
        private int $technicalAuthorId,
        private array $technicianIds,
        private bool $enabled = false,
        private bool $writesEnabled = false,
    ) {
        if ($technicalAuthorId < 1 || ! array_is_list($technicianIds) || $technicianIds === [] || count($technicianIds) > 200
            || count(array_unique($technicianIds, SORT_REGULAR)) !== count($technicianIds) || in_array($technicalAuthorId, $technicianIds, true)) {
            throw new InvalidArgumentException('mymar.invalid_booking_scope');
        }
        foreach ($technicianIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('mymar.invalid_booking_scope');
            }
        }
    }

    public function pending(int $after = 0, int $limit = 50): array
    {
        if ($after < 0 || $limit < 1 || $limit > 100) {
            throw new MymarBookingFailure('invalid_booking_page', 422);
        }
        $this->begin(readOnly: true);
        try {
            $records = $this->rows('SELECT /*+ MAX_EXECUTION_TIME(1500) */ '.self::RECORD
                .' FROM MYMAR.dossiers d JOIN MYMAR.clients c ON c.id=d.client_id'
                .' WHERE d.status_id IN (1,14) AND d.id>? ORDER BY d.id LIMIT '.($limit + 1), [$after]);
            $more = count($records) > $limit;
            $records = array_slice($records, 0, $limit);
            $rdvs = $records === [] ? [] : $this->rows('SELECT id,dossier_id,date_rdv,duree,is_old,updated_at FROM MYMAR.dossier_rdv'
                .' WHERE is_old=0 AND dossier_id IN ('.implode(',', array_fill(0, count($records), '?')).') ORDER BY id LIMIT 2001', array_column($records, 'id'));
            if (count($rdvs) > 2000) {
                throw new MymarBookingFailure('booking_source_limit', 503);
            }
            $grouped = [];
            foreach ($rdvs as $rdv) {
                $grouped[(int) $rdv['dossier_id']][] = $rdv;
            }
            $items = [];
            foreach ($records as $record) {
                $id = (int) $record['id'];
                $latitude = filter_var($record['latitude'], FILTER_VALIDATE_FLOAT);
                $longitude = filter_var($record['longitude'], FILTER_VALIDATE_FLOAT);
                $validCoordinates = $latitude !== false && $longitude !== false && abs($latitude) <= 90 && abs($longitude) <= 180;
                $items[] = ['dossier_id' => $id, 'status_id' => (int) $record['status_id'],
                    'phase' => (int) $record['status_id'] === 14 ? 'after_works' : 'initial',
                    'expected_revision' => self::revision($record, $grouped[$id] ?? []),
                    'first_name' => (string) ($record['prenom'] ?? ''), 'last_name' => (string) $record['nom'],
                    'address' => trim(implode(', ', array_filter([$record['adress'], trim(($record['zip'] ?? '').' '.($record['ville'] ?? ''))]))),
                    'postal_code' => $record['zip'], 'city' => $record['ville'],
                    'phone' => trim((string) ($record['mobile'] ?: $record['phone'])), 'email' => $record['email'],
                    'latitude' => $validCoordinates ? $latitude : null, 'longitude' => $validCoordinates ? $longitude : null];
            }

            return ['format' => 'cofrac.mymar-pending-bookings', 'version' => 1, 'items' => $items,
                'next_after' => $more ? (int) end($records)['id'] : null];
        } catch (Throwable $error) {
            throw $this->failure($error);
        } finally {
            $this->rollback();
        }
    }

    public function execute(MymarBookingCommand $command, DateTimeImmutable $now): array
    {
        if (! $this->writesEnabled) {
            throw new MymarBookingFailure('booking_disabled', 503);
        }
        $data = $command->payload;
        if ($data['action'] === 'place' && ! in_array($data['technician_id'], $this->technicianIds, true)) {
            throw new MymarBookingFailure('booking_technician_outside_scope', 403);
        }
        $this->begin(readOnly: false);
        $technicianLock = null;
        try {
            $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            try {
                $this->statement('INSERT INTO cofrac_mymar_integration.booking_commands'
                    .' (request_id,fingerprint,source_dossier_id,cofrac_actor_id,action,created_at) VALUES (?,?,?,?,?,?)',
                    [$data['request_id'], $command->fingerprint(), $data['dossier_id'], $data['cofrac_actor_id'], $data['action'], $stamp]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) {
                    throw $error;
                }
                $receipt = $this->rows('SELECT fingerprint,result FROM cofrac_mymar_integration.booking_commands WHERE request_id=? FOR UPDATE', [$data['request_id']])[0] ?? null;
                if ($receipt === null || ! hash_equals($receipt['fingerprint'], $command->fingerprint())) {
                    throw new MymarBookingFailure('booking_idempotency_conflict');
                }
                $result = self::receipt($receipt['result'], $data['request_id']);
                $this->database->commit();

                return $result;
            }
            $ids = [$this->technicalAuthorId];
            if ($data['action'] === 'place') {
                $ids[] = $data['technician_id'];
                $technicianLock = 'cofrac:mymar:booking:tech:'.$data['technician_id'];
                if ((int) $this->statement('SELECT GET_LOCK(?,2)', [$technicianLock])->fetchColumn() !== 1) {
                    throw new MymarBookingFailure('booking_technician_busy', 503);
                }
            }
            sort($ids);
            $users = $this->rows('SELECT id,enabled FROM MYMAR.users WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY id FOR SHARE', $ids);
            $enabled = array_column($users, 'enabled', 'id');
            if (! array_key_exists($this->technicalAuthorId, $enabled) || (int) $enabled[$this->technicalAuthorId] !== 0) {
                throw new MymarBookingFailure('booking_technical_author_unavailable', 503);
            }
            if ($data['action'] === 'place' && ((int) ($enabled[$data['technician_id']] ?? 0) !== 1
                || $this->rows("SELECT 1 FROM MYMAR.model_has_roles m JOIN MYMAR.roles r ON r.id=m.role_id WHERE m.model_id=? AND r.name='Technicien Terrain' AND m.model_type IN (?,?) LIMIT 1 FOR SHARE",
                    [$data['technician_id'], 'App\\Models\\User', 'App\\User']) === [])) {
                throw new MymarBookingFailure('booking_technician_unavailable');
            }
            $locked = $this->rows('SELECT id,client_id FROM MYMAR.dossiers WHERE id=? FOR UPDATE', [$data['dossier_id']])[0] ?? null;
            if ($locked === null || $this->rows('SELECT id FROM MYMAR.clients WHERE id=? FOR SHARE', [$locked['client_id']]) === []) {
                throw new MymarBookingFailure('booking_dossier_not_found', 404);
            }
            $record = $this->rows('SELECT '.self::RECORD.' FROM MYMAR.dossiers d JOIN MYMAR.clients c ON c.id=d.client_id WHERE d.id=?', [$data['dossier_id']])[0];
            $rdvs = $this->rows('SELECT id,dossier_id,date_rdv,duree,is_old,updated_at FROM MYMAR.dossier_rdv WHERE dossier_id=? AND is_old=0 ORDER BY id LIMIT 2001 FOR UPDATE', [$data['dossier_id']]);
            if (count($rdvs) > 2000) {
                throw new MymarBookingFailure('booking_source_limit', 503);
            }
            if (! hash_equals(self::revision($record, $rdvs), $data['expected_revision'])) {
                throw new MymarBookingFailure('booking_source_changed');
            }
            try {
                $targetStatus = MymarBookingCommand::targetStatus((int) $record['status_id'], $data['action']);
            } catch (InvalidArgumentException) {
                throw new MymarBookingFailure('booking_not_pending');
            }
            if (count($this->rows('SELECT id FROM MYMAR.status WHERE id IN (?,?) AND deleted_at IS NULL', [(int) $record['status_id'], $targetStatus])) !== 2) {
                throw new MymarBookingFailure('booking_status_unavailable');
            }
            $rdvId = null;
            if ($data['action'] === 'place') {
                $start = MymarBookingCommand::instant($data['starts_at']);
                $end = $start->modify('+'.$data['duration_minutes'].' minutes');
                $this->assertSourceSlot($data['technician_id'], $data['dossier_id'], $start, $end, $now);
                $this->statement('UPDATE MYMAR.dossier_rdv SET is_old=1,updated_at=? WHERE dossier_id=? AND is_old=0', [$stamp, $data['dossier_id']]);
                $this->statement('INSERT INTO MYMAR.dossier_rdv (dossier_id,author_id,date_rdv,duree,is_old,created_at,updated_at) VALUES (?,?,?,?,0,?,?)',
                    [$data['dossier_id'], $this->technicalAuthorId, $start->format('Y-m-d H:i:s'), intdiv($data['duration_minutes'], 60), $stamp, $stamp]);
                $rdvId = (int) $this->database->lastInsertId();
                $this->statement('UPDATE MYMAR.dossiers SET status_id=?,technicien_id=?,updated_at=? WHERE id=?', [$targetStatus, $data['technician_id'], $stamp, $data['dossier_id']]);
                $description = "RDV MAR plac\u{00E9} le ".$start->format('d/m/Y H:i').', '.$data['duration_minutes'].' min, technicien MYMAR #'.$data['technician_id'];
            } else {
                $recall = $data['recall_at'] === null ? null : MymarBookingCommand::instant($data['recall_at'])->format('Y-m-d H:i:s');
                $this->statement('UPDATE MYMAR.dossiers SET status_id=?,sub_statut=?,commentaire_status=?,date_rappel=?,updated_at=? WHERE id=?',
                    [$targetStatus, $data['reason'], $data['comment'], $recall, $stamp, $data['dossier_id']]);
                $description = "Probl\u{00E8}me RDV MAR signal\u{00E9}, motif conserv\u{00E9} dans le dossier";
            }
            $history = $description.' via Cofrac, compte #'.$data['cofrac_actor_id'].', demande '.$data['request_id'];
            $this->statement('INSERT INTO MYMAR.dossier_historiques (author_id,dossier_id,status_id,text,created_at,updated_at) VALUES (?,?,?,?,?,?)',
                [$this->technicalAuthorId, $data['dossier_id'], $targetStatus, $history, $stamp, $stamp]);
            $result = ['format' => 'cofrac.mymar-booking-result', 'version' => 1, 'request_id' => $data['request_id'],
                'action' => $data['action'], 'dossier_id' => $data['dossier_id'], 'status_id' => $targetStatus, 'rdv_id' => $rdvId,
                'starts_at' => $data['action'] === 'place' ? $data['starts_at'] : null,
                'duration_minutes' => $data['action'] === 'place' ? $data['duration_minutes'] : null,
                'source_committed' => true];
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            $this->statement('UPDATE cofrac_mymar_integration.booking_commands SET result=?,completed_at=? WHERE request_id=?', [$json, $stamp, $data['request_id']]);
            $this->database->commit();

            return $result;
        } catch (Throwable $error) {
            throw $this->failure($error);
        } finally {
            try {
                $this->rollback();
            } finally {
                if ($technicianLock !== null) {
                    try {
                        $this->statement('SELECT RELEASE_LOCK(?)', [$technicianLock]);
                    } catch (Throwable) {
                        // Connection-scoped advisory locks disappear when the connection closes.
                        throw new MymarBookingFailure('booking_storage_unavailable', 503);
                    }
                }
            }
        }
    }

    public function result(string $requestId): ?array
    {
        if (! MymarBookingCommand::isRequestId($requestId)) {
            throw new MymarBookingFailure('invalid_booking_request_id', 422);
        }
        $this->begin(readOnly: true);
        try {
            $row = $this->rows('SELECT result FROM cofrac_mymar_integration.booking_commands WHERE request_id=?', [$requestId])[0] ?? null;

            return $row === null ? null : self::receipt($row['result'], $requestId);
        } catch (Throwable $error) {
            throw $this->failure($error);
        } finally {
            $this->rollback();
        }
    }

    public static function revision(array $record, array $rdvs): string
    {
        $values = [];
        foreach (self::REVISION_FIELDS as $field) {
            $values[$field] = isset($record[$field]) ? (string) $record[$field] : null;
        }
        $appointments = [];
        foreach ($rdvs as $rdv) {
            $item = [];
            foreach (['id', 'dossier_id', 'date_rdv', 'duree', 'is_old', 'updated_at'] as $field) {
                $item[$field] = isset($rdv[$field]) ? (string) $rdv[$field] : null;
            }
            $appointments[] = $item;
        }
        usort($appointments, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

        return hash('sha256', json_encode([$values, $appointments], JSON_THROW_ON_ERROR));
    }

    private function begin(bool $readOnly): void
    {
        if (! $this->enabled) {
            throw new MymarBookingFailure('booking_disabled', 503);
        }
        if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $this->database->inTransaction()) {
            throw new MymarBookingFailure('booking_connection_refused', 503);
        }
        try {
            $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->database->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->database->exec("SET SESSION time_zone='+00:00'");
            $this->database->exec('SET SESSION max_execution_time=1500');
            $this->database->exec('SET SESSION lock_wait_timeout=2');
            $this->database->exec('SET SESSION innodb_lock_wait_timeout=2');
            if ($this->database->query('SELECT CURRENT_ROLE()')->fetchColumn() !== 'NONE') {
                throw new MymarBookingFailure('booking_connection_refused', 503);
            }
            MymarBookingSqlAccess::validateGrants($this->database->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertSchema();
            $this->database->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->database->exec($readOnly ? 'START TRANSACTION READ ONLY' : 'START TRANSACTION READ WRITE');
        } catch (Throwable $error) {
            throw $this->failure($error);
        }
    }

    private function assertSchema(): void
    {
        $tables = $this->database->query('SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE'
            ." (TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('dossiers','clients','dossier_rdv','dossier_historiques','users','roles','model_has_roles','status'))"
            ." OR (TABLE_SCHEMA='cofrac_mymar_integration' AND TABLE_NAME='booking_commands')")->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) !== 9 || array_filter($tables, static fn ($row) => $row['ENGINE'] !== 'InnoDB')) {
            throw new MymarBookingFailure('booking_schema_refused', 503);
        }
        // Trigger absence must be checked by the privileged preparation audit. The runtime
        // account deliberately has no TRIGGER privilege and cannot prove their absence.
        $lengths = $this->database->query("SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR'"
            ." AND ((TABLE_NAME='dossiers' AND COLUMN_NAME IN ('sub_statut','commentaire_status')) OR (TABLE_NAME='dossier_historiques' AND COLUMN_NAME='text'))")->fetchAll(PDO::FETCH_ASSOC);
        $expected = ['dossiers.sub_statut' => 100, 'dossiers.commentaire_status' => 255, 'dossier_historiques.text' => 255];
        foreach ($lengths as $row) {
            $key = $row['TABLE_NAME'].'.'.$row['COLUMN_NAME'];
            if (($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0) < $expected[$key]) {
                throw new MymarBookingFailure('booking_schema_refused', 503);
            }
            unset($expected[$key]);
        }
        if ($expected !== []) {
            throw new MymarBookingFailure('booking_schema_refused', 503);
        }
    }

    private function assertSourceSlot(int $technicianId, int $dossierId, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): void
    {
        $from = $start->modify('-24 hours');
        $to = $end->modify('+24 hours');
        $rows = $this->rows('SELECT /*+ MAX_EXECUTION_TIME(1500) */ r.id AS rdv_id,r.dossier_id,d.technicien_id AS technician_id,d.status_id,r.is_old,r.date_rdv AS stored_start,r.duree AS raw_duration'
            .' FROM MYMAR.dossier_rdv r JOIN MYMAR.dossiers d ON d.id=r.dossier_id'
            .' WHERE d.technicien_id=? AND d.id<>? AND d.status_id IN (3,16) AND r.is_old=0'
            ." AND ((r.date_rdv>=? AND r.date_rdv<?) OR r.date_rdv<'1000-01-01' OR r.duree IS NULL OR r.duree<1 OR r.duree>24) ORDER BY r.id LIMIT 2001",
            [$technicianId, $dossierId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        if (count($rows) > 2000) {
            throw new MymarBookingFailure('booking_source_limit', 503);
        }
        $report = (new MymarObservation)->inspect(['format' => MymarObservation::FORMAT, 'version' => 1,
            'generated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'complete' => true,
            'source_timezone' => 'Europe/Paris', 'duration_unit' => 'hours', 'window_start' => $from->format(DATE_ATOM),
            'window_end' => $to->format(DATE_ATOM), 'technician_ids' => [$technicianId], 'status_ids' => [3, 16], 'appointments' => $rows],
            new MymarObservationPolicy(timezoneConfirmed: true, durationUnitConfirmed: true, statusScopeConfirmed: true), $now, [$technicianId => $technicianId]);
        if ($report['issues'] !== []) {
            throw new MymarBookingFailure('booking_source_availability_unknown');
        }
        foreach ($report['candidates'] as $candidate) {
            if (new DateTimeImmutable($candidate['starts_at']) < $end && new DateTimeImmutable($candidate['ends_at']) > $start) {
                throw new MymarBookingFailure('booking_source_slot_occupied');
            }
        }
    }

    private static function receipt(mixed $json, string $requestId): array
    {
        if (! is_string($json) || strlen($json) > 4096) {
            throw new MymarBookingFailure('booking_receipt_unavailable', 503);
        }
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $fields = ['format', 'version', 'request_id', 'action', 'dossier_id', 'status_id', 'rdv_id', 'starts_at', 'duration_minutes', 'source_committed'];
        if (! is_array($data) || ($data['format'] ?? null) !== 'cofrac.mymar-booking-result'
            || ($data['version'] ?? null) !== 1 || ($data['request_id'] ?? null) !== $requestId
            || ($data['source_committed'] ?? null) !== true || array_diff(array_keys($data), $fields) || array_diff($fields, array_keys($data))
            || ! is_int($data['dossier_id']) || $data['dossier_id'] < 1
            || ! in_array($data['action'], ['place', 'problem'], true)
            || ! in_array($data['status_id'], $data['action'] === 'place' ? [3, 16] : [2, 15], true)) {
            throw new MymarBookingFailure('booking_receipt_unavailable', 503);
        }
        if ($data['action'] === 'place') {
            if (! is_int($data['rdv_id']) || $data['rdv_id'] < 1 || ! is_int($data['duration_minutes'])
                || $data['duration_minutes'] < 60 || $data['duration_minutes'] > 480 || $data['duration_minutes'] % 60 !== 0) {
                throw new MymarBookingFailure('booking_receipt_unavailable', 503);
            }
            MymarBookingCommand::instant($data['starts_at']);
        } elseif ($data['rdv_id'] !== null || $data['starts_at'] !== null || $data['duration_minutes'] !== null) {
            throw new MymarBookingFailure('booking_receipt_unavailable', 503);
        }

        return array_replace(array_fill_keys($fields, null), $data);
    }

    private function statement(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    private function rows(string $sql, array $parameters): array
    {
        return $this->statement($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rollback(): void
    {
        try {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
        } catch (Throwable) {
            throw new MymarBookingFailure('booking_storage_unavailable', 503);
        }
    }

    private function failure(Throwable $error): MymarBookingFailure
    {
        return $error instanceof MymarBookingFailure ? $error : new MymarBookingFailure('booking_storage_unavailable', 503,
            ['type' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    }
}
