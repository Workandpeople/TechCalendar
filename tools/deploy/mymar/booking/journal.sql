-- Integration-owned schema only. This is NOT a migration of the MYMAR application.
CREATE DATABASE cofrac_mymar_integration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE cofrac_mymar_integration.booking_commands (
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_dossier_id BIGINT UNSIGNED NOT NULL,
    cofrac_actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result JSON NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL
) ENGINE=InnoDB;
