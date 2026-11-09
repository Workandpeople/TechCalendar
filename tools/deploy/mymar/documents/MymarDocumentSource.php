<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarVt2Command;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/** Standalone, disabled adapter. No MYMAR bootstrap, events, email or legacy controller. */
final readonly class MymarDocumentSource
{
    public function __construct(
        private PDO $database,
        private MymarDocumentFiles $files,
        private array $technicianIds,
        private int $technicalAuthorId,
        private bool $enabled = false,
        private bool $uploadsEnabled = false,
    ) {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $database->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || ! array_is_list($technicianIds) || $technicianIds === [] || count($technicianIds) > 200
            || count(array_unique($technicianIds, SORT_REGULAR)) !== count($technicianIds) || ($uploadsEnabled && $technicalAuthorId < 1)
            || in_array($technicalAuthorId, $technicianIds, true) || ($uploadsEnabled && ! $enabled)) {
            throw new MymarDocumentFailure('documents_invalid_configuration', 503);
        }
        foreach ($technicianIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new MymarDocumentFailure('documents_invalid_configuration', 503);
            }
        }
    }

    public function catalogue(int $rdvId, int $technicianId): array
    {
        $this->begin();
        try {
            $context = $this->context($rdvId, $technicianId);
            $rows = $this->rows('SELECT /*+ MAX_EXECUTION_TIME(1500) */ id,nom,path FROM MYMAR.dossier_documents WHERE dossier_id=? ORDER BY id DESC LIMIT 201', [$context['dossier_id']]);
            if (count($rows) > 200) {
                throw new MymarDocumentFailure('documents_source_limit', 503);
            }
            $documents = [];
            foreach ($rows as $row) {
                $kind = MymarDocumentPolicy::kind((string) ($row['nom'] ?? ''), $context['customer'], (string) $row['path']);
                if ($kind === null) {
                    continue;
                }
                try {
                    $metadata = $this->files->metadata((string) $row['path']);
                } catch (MymarDocumentFailure $error) {
                    if ($error->httpStatus !== 404) {
                        throw $error;
                    }

                    continue;
                }
                $documents[] = ['id' => (int) $row['id'], 'name' => (string) $row['nom'], 'kind' => $kind, 'bytes' => $metadata['bytes']];
            }

            return ['format' => 'cofrac.mymar-document-catalogue', 'version' => 1,
                'rdv_id' => $rdvId, 'dossier_id' => $context['dossier_id'], 'technician_id' => $technicianId,
                'expected_revision' => $context['revision'], 'documents' => $documents];
        } catch (Throwable $error) {
            throw self::failure($error);
        } finally {
            $this->rollback();
        }
    }

    public function open(int $rdvId, int $technicianId, int $documentId)
    {
        $this->begin();
        try {
            $context = $this->context($rdvId, $technicianId);
            $record = $this->rows('SELECT id,nom,path FROM MYMAR.dossier_documents WHERE dossier_id=? AND id=?', [$context['dossier_id'], $documentId])[0] ?? null;
            if ($documentId < 1 || $record === null || MymarDocumentPolicy::kind((string) ($record['nom'] ?? ''), $context['customer'], (string) $record['path']) === null) {
                throw new MymarDocumentFailure('documents_file_unavailable', 404);
            }

            return $this->files->open((string) $record['path']);
        } catch (Throwable $error) {
            throw self::failure($error);
        } finally {
            $this->rollback();
        }
    }

    /** Only an authenticated office action may invoke this future write boundary. */
    public function deposit(MymarVt2Command $command, DateTimeImmutable $now): array
    {
        if (! $this->uploadsEnabled) {
            throw new MymarDocumentFailure('documents_upload_disabled', 503);
        }
        $this->files->preparePublication($command);
        $this->begin(write: true);
        $data = $command->payload;
        try {
            $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            try {
                $this->statement('INSERT INTO cofrac_mymar_integration.document_commands (request_id,fingerprint,source_dossier_id,cofrac_actor_id,created_at) VALUES (?,?,?,?,?)',
                    [$data['request_id'], $command->fingerprint(), $data['dossier_id'], $data['cofrac_actor_id'], $stamp]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) {
                    throw $error;
                }
                $receipt = $this->rows('SELECT fingerprint,result FROM cofrac_mymar_integration.document_commands WHERE request_id=? FOR UPDATE', [$data['request_id']])[0] ?? null;
                if ($receipt === null || ! hash_equals($receipt['fingerprint'], $command->fingerprint())) {
                    throw new MymarDocumentFailure('documents_idempotency_conflict');
                }
                $result = self::receipt($receipt['result'], $data['request_id']);
                $this->database->commit();

                return $result;
            }
            // Lock the dossier before the appointment, consistently for all document writes.
            $locked = $this->rows('SELECT id FROM MYMAR.dossiers WHERE id=? FOR UPDATE', [$data['dossier_id']]);
            if ($locked === []) {
                throw new MymarDocumentFailure('documents_appointment_not_found', 404);
            }
            $context = $this->context($data['rdv_id'], $data['technician_id'], lock: true);
            if ($context['dossier_id'] !== $data['dossier_id'] || $context['status_id'] !== 16 || ! hash_equals($context['revision'], $data['expected_revision'])) {
                throw new MymarDocumentFailure('documents_source_changed');
            }
            $visitStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $context['date_rdv'], new DateTimeZone('Europe/Paris'));
            if (! $visitStart || $visitStart->format('Y-m-d H:i:s') !== $context['date_rdv'] || $visitStart > $now) {
                throw new MymarDocumentFailure('documents_visit_not_started');
            }
            $author = $this->rows('SELECT enabled FROM MYMAR.users WHERE id=? FOR SHARE', [$this->technicalAuthorId])[0] ?? null;
            $status = $this->rows('SELECT name,deleted_at FROM MYMAR.status WHERE id=19 FOR SHARE')[0] ?? null;
            if ($author === null || (int) $author['enabled'] !== 0 || $status === null || $status['deleted_at'] !== null
                || MymarDocumentPolicy::words($status['name']) !== ['depot', 'solde']) {
                throw new MymarDocumentFailure('documents_workflow_changed', 503);
            }
            $filename = $this->files->publish($command);
            $this->statement('INSERT INTO MYMAR.dossier_documents (dossier_id,author_id,path,nom,description,is_public,is_validated,request_data,created_at,updated_at) VALUES (?,?,?,?,?,0,0,?,?,?)',
                [$data['dossier_id'], $this->technicalAuthorId, $filename, 'VT2 - Visite après travaux', 'VT2 transmise depuis Cofrac.',
                    json_encode(['integration' => 'cofrac.vt2', 'request_id' => $data['request_id'], 'cofrac_actor_id' => $data['cofrac_actor_id'], 'sha256' => $data['sha256'], 'bytes' => $data['bytes']], JSON_THROW_ON_ERROR), $stamp, $stamp]);
            $documentId = (int) $this->database->lastInsertId();
            $this->statement('UPDATE MYMAR.dossiers SET status_id=19,sub_statut=NULL,updated_at=? WHERE id=? AND status_id=16', [$stamp, $data['dossier_id']]);
            $this->statement('INSERT INTO MYMAR.dossier_historiques (author_id,dossier_id,status_id,text,created_at,updated_at) VALUES (?,?,19,?,?,?)',
                [$this->technicalAuthorId, $data['dossier_id'], 'VT2 reçue depuis Cofrac ; compte '.$data['cofrac_actor_id'].' ; référence '.$data['request_id'].'.', $stamp, $stamp]);
            $result = ['format' => 'cofrac.mymar-vt2-result', 'version' => 1, 'request_id' => $data['request_id'],
                'dossier_id' => $data['dossier_id'], 'document_id' => $documentId, 'status_id' => 19, 'sha256' => $data['sha256'], 'source_committed' => true];
            $this->statement('UPDATE cofrac_mymar_integration.document_commands SET result=?,completed_at=? WHERE request_id=?',
                [json_encode($result, JSON_THROW_ON_ERROR), $stamp, $data['request_id']]);
            $this->database->commit();

            return $result;
        } catch (Throwable $error) {
            // A published but unreferenced file is retained. Never delete after an uncertain COMMIT.
            throw self::failure($error);
        } finally {
            $this->rollback();
        }
    }

    /** An absent receipt is unknown, not evidence that a timed-out POST failed. */
    public function result(string $requestId): ?array
    {
        if (! MymarBookingCommand::isRequestId($requestId)) {
            throw new MymarDocumentFailure('documents_invalid_request', 422);
        }
        $this->begin();
        try {
            $record = $this->rows('SELECT result FROM cofrac_mymar_integration.document_commands WHERE request_id=?', [$requestId])[0] ?? null;

            return $record === null ? null : self::receipt($record['result'], $requestId);
        } catch (Throwable $error) {
            throw self::failure($error);
        } finally {
            $this->rollback();
        }
    }

    private function context(int $rdvId, int $technicianId, bool $lock = false): array
    {
        if ($rdvId < 1 || ! in_array($technicianId, $this->technicianIds, true)) {
            throw new MymarDocumentFailure('documents_appointment_not_found', 404);
        }
        $record = $this->rows('SELECT r.id AS rdv_id,r.dossier_id,r.date_rdv,r.updated_at AS rdv_updated_at,d.client_id,d.status_id,d.technicien_id,d.updated_at,c.nom,c.prenom'
            .' FROM MYMAR.dossier_rdv r JOIN MYMAR.dossiers d ON d.id=r.dossier_id JOIN MYMAR.clients c ON c.id=d.client_id'
            .' WHERE r.id=? AND r.is_old=0 AND d.technicien_id=? AND d.status_id IN (16,19)'.($lock ? ' FOR SHARE' : ''), [$rdvId, $technicianId])[0] ?? null;
        $active = $this->rows('SELECT u.id FROM MYMAR.users u WHERE u.id=? AND u.enabled=1 AND EXISTS ('
            .'SELECT 1 FROM MYMAR.model_has_roles b JOIN MYMAR.roles r ON r.id=b.role_id WHERE b.model_id=u.id AND r.name=? AND b.model_type IN (?,?))'.($lock ? ' FOR SHARE' : ''),
            [$technicianId, 'Technicien Terrain', 'App\\Models\\User', 'App\\User']);
        if ($record === null || $active === []) {
            throw new MymarDocumentFailure('documents_appointment_not_found', 404);
        }
        $current = $this->rows('SELECT id FROM MYMAR.dossier_rdv WHERE dossier_id=? AND is_old=0 ORDER BY id LIMIT 2'.($lock ? ' FOR SHARE' : ''), [$record['dossier_id']]);
        if (count($current) !== 1 || (int) $current[0]['id'] !== $rdvId) {
            throw new MymarDocumentFailure('documents_source_changed');
        }

        return ['dossier_id' => (int) $record['dossier_id'], 'status_id' => (int) $record['status_id'], 'date_rdv' => $record['date_rdv'],
            'customer' => trim(($record['prenom'] ?? '').' '.($record['nom'] ?? '')),
            'revision' => hash('sha256', json_encode($record, JSON_THROW_ON_ERROR))];
    }

    private function begin(bool $write = false): void
    {
        if (! $this->enabled) {
            throw new MymarDocumentFailure('documents_disabled', 503);
        }
        if ($this->database->inTransaction()) {
            throw new MymarDocumentFailure('documents_source_busy', 503);
        }
        try {
            $this->database->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->database->exec('SET SESSION max_execution_time=1500');
            $this->database->exec('SET SESSION innodb_lock_wait_timeout=2');
            $this->database->exec('SET SESSION lock_wait_timeout=2');
            $this->database->exec("SET SESSION time_zone='+00:00'");
            if ($this->database->query('SELECT CURRENT_ROLE()')->fetchColumn() !== 'NONE') {
                throw new MymarDocumentFailure('documents_unsafe_grants', 503);
            }
            MymarDocumentSqlAccess::validateGrants($this->database->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN), $this->uploadsEnabled);
            $this->assertSchema();
            $this->database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->database->exec('START TRANSACTION '.($write ? 'READ WRITE' : 'READ ONLY'));
        } catch (Throwable $error) {
            throw self::failure($error);
        }
    }

    private function assertSchema(): void
    {
        $history = $this->uploadsEnabled ? ",'dossier_historiques'" : '';
        $journal = $this->uploadsEnabled ? " OR (TABLE_SCHEMA='cofrac_mymar_integration' AND TABLE_NAME='document_commands')" : '';
        $tables = $this->database->query('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE'
            ." (TABLE_SCHEMA='MYMAR' AND TABLE_NAME IN ('dossiers','clients','dossier_rdv','dossier_documents','users','roles','model_has_roles','status'$history))"
            .$journal)->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) !== ($this->uploadsEnabled ? 10 : 8)
            || array_filter($tables, static fn ($table) => $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB')) {
            throw new MymarDocumentFailure('documents_schema_refused', 503);
        }
        // Trigger absence needs a privileged preflight; limited runtime users cannot prove it.
        if ($this->uploadsEnabled) {
            $columns = $this->database->query("SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='MYMAR'"
                ." AND ((TABLE_NAME='dossier_documents' AND COLUMN_NAME IN ('path','nom','description','request_data')) OR (TABLE_NAME='dossier_historiques' AND COLUMN_NAME='text'))")->fetchAll(PDO::FETCH_ASSOC);
            $expected = ['dossier_documents.path' => 155, 'dossier_documents.nom' => 80, 'dossier_documents.description' => 80,
                'dossier_documents.request_data' => 1024, 'dossier_historiques.text' => 255];
            foreach ($columns as $column) {
                $key = $column['TABLE_NAME'].'.'.$column['COLUMN_NAME'];
                if (! isset($expected[$key]) || (int) $column['CHARACTER_MAXIMUM_LENGTH'] < $expected[$key]) {
                    throw new MymarDocumentFailure('documents_schema_refused', 503);
                }
                unset($expected[$key]);
            }
            if ($expected !== []) {
                throw new MymarDocumentFailure('documents_schema_refused', 503);
            }
        }
    }

    private function rollback(): void
    {
        if ($this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    private function statement(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    private function rows(string $sql, array $parameters = []): array
    {
        return $this->statement($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function receipt(mixed $value, string $requestId): array
    {
        $result = is_string($value) && strlen($value) <= 4096 ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : null;
        if (! is_array($result) || count($result) !== 8 || ($result['format'] ?? null) !== 'cofrac.mymar-vt2-result'
            || ($result['version'] ?? null) !== 1 || ($result['source_committed'] ?? null) !== true || ($result['request_id'] ?? null) !== $requestId
            || ($result['status_id'] ?? null) !== 19 || ! is_int($result['document_id'] ?? null) || $result['document_id'] < 1
            || ! is_int($result['dossier_id'] ?? null) || $result['dossier_id'] < 1
            || ! is_string($result['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $result['sha256'])) {
            throw new MymarDocumentFailure('documents_receipt_unknown', 503);
        }

        return $result;
    }

    private static function failure(Throwable $error): MymarDocumentFailure
    {
        return $error instanceof MymarDocumentFailure ? $error : new MymarDocumentFailure('documents_source_unavailable', 503);
    }
}
