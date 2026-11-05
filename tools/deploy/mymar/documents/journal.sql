-- Isolated integration database only. Never migrate or alter the MYMAR schema.
CREATE TABLE cofrac_mymar_integration.document_commands (
    request_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_dossier_id bigint unsigned NOT NULL,
    cofrac_actor_id bigint unsigned NOT NULL,
    result json NULL,
    created_at timestamp NOT NULL,
    completed_at timestamp NULL
) ENGINE=InnoDB;
