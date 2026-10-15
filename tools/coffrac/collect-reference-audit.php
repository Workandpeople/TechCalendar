<?php

declare(strict_types=1);

// Load from Coffrac Tinker and pass its PDO connection. No models, writes or API calls.
return static function (PDO $connection, array $workflowConfiguration = []): array {
    $queries = [
        'tables' => <<<'SQL'
            SELECT TABLE_NAME AS table_name, TABLE_TYPE AS table_type,
                   ENGINE AS engine, TABLE_COLLATION AS table_collation,
                   TABLE_ROWS AS estimated_rows
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME
            SQL,
        'statuses' => 'SELECT id, name, autorisation, status, orders, has_mail FROM status ORDER BY orders, id',
        'roles' => 'SELECT id, name, autorisation, autorisation_types, status FROM user_type ORDER BY id',
        'service_forms' => <<<'SQL'
            SELECT id, name, price, status, showDetailChantier, showEntreprise, is_audit,
                   CHAR_LENGTH(rapport) AS form_characters
            FROM fiche_type ORDER BY id
            SQL,
        'migrations' => 'SELECT migration, batch FROM migrations ORDER BY id',
        'user_counts' => 'SELECT type, actif, is_archived, COUNT(*) AS total FROM users GROUP BY type, actif, is_archived',
        'dossier_status_counts' => 'SELECT status_id, COUNT(*) AS total FROM dossiers GROUP BY status_id',
        'operation_status_counts' => 'SELECT status_id, COUNT(*) AS total FROM dossier_fiches GROUP BY status_id',
        'operation_conclusions' => 'SELECT is_favorable, COUNT(*) AS total FROM dossier_fiches GROUP BY is_favorable',
        'dossier_quality' => <<<'SQL'
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN TRIM(COALESCE(adress, '')) = '' THEN 1 ELSE 0 END), 0) AS missing_address,
                   COALESCE(SUM(CASE WHEN TRIM(COALESCE(ville, '')) = '' THEN 1 ELSE 0 END), 0) AS missing_city,
                   COALESCE(SUM(CASE WHEN TRIM(COALESCE(dossier_unique, '')) = '' THEN 1 ELSE 0 END), 0) AS missing_reference,
                   MAX(CHAR_LENGTH(dossier_unique)) AS longest_reference,
                   MAX(CHAR_LENGTH(client_name)) AS longest_client_name,
                   MAX(CHAR_LENGTH(ville)) AS longest_city,
                   MAX(CHAR_LENGTH(zip)) AS longest_postal_code
            FROM dossiers
            SQL,
        'duplicate_dossier_references' => <<<'SQL'
            SELECT COUNT(*) AS duplicate_groups FROM (
                SELECT dossier_unique FROM dossiers
                WHERE TRIM(COALESCE(dossier_unique, '')) <> ''
                GROUP BY dossier_unique HAVING COUNT(*) > 1
            ) AS duplicates
            SQL,
        'duplicate_normalized_emails' => <<<'SQL'
            SELECT COUNT(*) AS duplicate_groups FROM (
                SELECT LOWER(TRIM(email)) FROM users
                WHERE TRIM(COALESCE(email, '')) <> ''
                GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1
            ) AS duplicates
            SQL,
        'operation_multiplicity' => <<<'SQL'
            SELECT operation_count, COUNT(*) AS dossiers FROM (
                SELECT d.id, COUNT(f.id) AS operation_count
                FROM dossiers d LEFT JOIN dossier_fiches f ON f.dossier_id = d.id
                GROUP BY d.id
            ) AS counts GROUP BY operation_count ORDER BY operation_count
            SQL,
        'appointment_multiplicity' => <<<'SQL'
            SELECT appointment_count, COUNT(*) AS operations FROM (
                SELECT f.id, COUNT(r.id) AS appointment_count
                FROM dossier_fiches f LEFT JOIN dossier_fiche_rdv r ON r.dossier_fiche = f.id
                GROUP BY f.id
            ) AS counts GROUP BY appointment_count ORDER BY appointment_count
            SQL,
        'appointment_quality' => <<<'SQL'
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN r.date_rdv IS NULL OR r.heure IS NULL THEN 1 ELSE 0 END), 0) AS missing_schedule,
                   COALESCE(SUM(CASE WHEN f.id IS NULL THEN 1 ELSE 0 END), 0) AS missing_operation,
                   COALESCE(SUM(CASE WHEN f.id IS NOT NULL AND r.dossier_id <> f.dossier_id THEN 1 ELSE 0 END), 0) AS conflicting_dossier,
                   COALESCE(SUM(CASE WHEN r.rdv_duree IS NULL OR r.rdv_duree <= 0 THEN 1 ELSE 0 END), 0) AS invalid_duration
            FROM dossier_fiche_rdv r LEFT JOIN dossier_fiches f ON f.id = r.dossier_fiche
            SQL,
        'appointment_durations' => 'SELECT rdv_duree, COUNT(*) AS total FROM dossier_fiche_rdv GROUP BY rdv_duree ORDER BY rdv_duree',
        'document_visibility' => 'SELECT is_private, is_delegataire, COUNT(*) AS total FROM dossier_document GROUP BY is_private, is_delegataire',
        'comment_visibility' => 'SELECT is_private, COUNT(*) AS total FROM dossier_comment GROUP BY is_private',
        'missing_operation_references' => <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM dossier_fiches f WHERE NOT EXISTS (SELECT 1 FROM dossiers d WHERE d.id = f.dossier_id)) AS dossiers,
                (SELECT COUNT(*) FROM dossier_fiches f WHERE NOT EXISTS (SELECT 1 FROM fiche_type t WHERE t.id = f.fiche_id)) AS service_forms,
                (SELECT COUNT(*) FROM dossier_fiches f WHERE NOT EXISTS (SELECT 1 FROM status s WHERE s.id = f.status_id)) AS statuses
            SQL,
        'missing_dossier_actors' => <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM dossiers d WHERE d.technicien_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = d.technicien_id)) AS technicians,
                (SELECT COUNT(*) FROM dossiers d WHERE d.delegataire_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = d.delegataire_id)) AS delegataires,
                (SELECT COUNT(*) FROM dossiers d WHERE d.client_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = d.client_id)) AS client_accounts,
                (SELECT COUNT(*) FROM dossiers d WHERE d.entreprise_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM entreprise e WHERE e.id = d.entreprise_id)) AS installers
            SQL,
        'missing_document_actors' => <<<'SQL'
            SELECT COUNT(*) AS missing_authors
            FROM dossier_document d WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.id = d.author_id)
            SQL,
        'business_counts' => <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM dossier_historique) AS history,
                (SELECT COUNT(*) FROM dossier_fiche_facturation) AS invoices,
                (SELECT COUNT(*) FROM dossier_letige) AS disputes,
                (SELECT COUNT(*) FROM rdv_extern) AS external_appointments,
                (SELECT COUNT(*) FROM planings) AS planning_blocks,
                (SELECT COUNT(*) FROM lot_dossier) AS legacy_lots,
                (SELECT COUNT(*) FROM entreprise) AS installers,
                (SELECT COUNT(*) FROM fiche_document) AS form_documents,
                (SELECT COUNT(*) FROM media) AS media,
                (SELECT COUNT(*) FROM clients) AS legacy_clients
            SQL,
    ];

    $report = [
        'format_version' => 1,
        'generated_at_utc' => gmdate(DATE_ATOM),
        'scope' => 'Reference data and aggregate counts only. No customer records, credentials, report answers or file paths.',
        'consistent_snapshot' => false,
        'query_timeout_ms' => 10000,
        'workflow_configuration' => array_intersect_key($workflowConfiguration, array_flip([
            'rapport_avis', 'user_type_column_by_status', 'user_type_btns_by_status',
        ])),
        'sections' => [],
        'complete' => true,
    ];

    foreach ($queries as $name => $sql) {
        // MySQL bounds each SELECT; it is an ordinary comment for SQLite tests.
        $sql = preg_replace('/\ASELECT\s/i', 'SELECT /*+ MAX_EXECUTION_TIME(10000) */ ', $sql, 1);

        try {
            $statement = $connection->query($sql);

            if ($statement === false) {
                throw new RuntimeException('Query failed.');
            }

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            $report['sections'][$name] = ['status' => 'ok', 'rows' => $rows];
        } catch (Throwable $exception) {
            $report['complete'] = false;
            // SQL errors may contain data: keep only the SQLSTATE, never the message.
            $report['sections'][$name] = [
                'status' => 'error',
                'sqlstate' => $exception instanceof PDOException ? ($exception->errorInfo[0] ?? null) : null,
            ];
        }
    }

    return $report;
};
