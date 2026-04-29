-- ============================================================
-- apple_login — Supplier contracts migration
-- Immutable history of signed contracts between platform and suppliers.
--
-- Run once:
--   mysql -u root -proot apple_login < migrate_contracts.sql
-- Safe to run multiple times (IF NOT EXISTS).
--
-- Design notes:
--   - No hard delete: no DELETE operations should ever target this table.
--   - No soft delete: no is_active / is_deleted column by design.
--   - Only one contract can be is_primary=1 per supplier at a time.
--   - Max file size enforced at application layer: CONTRACT_MAX_BYTES = 10 MB.
--   - Allowed MIME types: application/pdf, image/jpeg, image/png.
-- ============================================================

USE `apple_login`;

CREATE TABLE IF NOT EXISTS `supplier_contracts` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `supplier_id`          INT UNSIGNED  NOT NULL
                                         COMMENT 'FK → users.id (role=supplier)',
    `storage_path`         VARCHAR(500)  NOT NULL
                                         COMMENT 'Relative path from project root, forward slashes. E.g. uploads/contracts/7/a1b2c3d4.pdf',
    `original_filename`    VARCHAR(255)  NOT NULL
                                         COMMENT 'Original name as provided by the uploader',
    `mime_type`            VARCHAR(100)  NOT NULL
                                         COMMENT 'Validated MIME: application/pdf, image/jpeg, image/png',
    `file_size`            INT UNSIGNED  NOT NULL
                                         COMMENT 'File size in bytes',
    `file_hash`            VARCHAR(64)   NULL DEFAULT NULL
                                         COMMENT 'SHA-256 hex digest of the stored file; NULL if not computed',
    `signed_date`          DATE          NULL DEFAULT NULL
                                         COMMENT 'Date the contract was signed by both parties',
    `effective_start_date` DATE          NULL DEFAULT NULL
                                         COMMENT 'Date from which the contract takes effect',
    `effective_end_date`   DATE          NULL DEFAULT NULL
                                         COMMENT 'Date on which the contract expires; NULL = open-ended',
    `notes`                TEXT          NULL DEFAULT NULL
                                         COMMENT 'Optional free-text observations',
    `is_primary`           TINYINT(1)    NOT NULL DEFAULT 0
                                         COMMENT '1 = current/active contract; max one per supplier',
    `uploaded_by_user_id`  INT UNSIGNED  NOT NULL
                                         COMMENT 'FK → users.id — who uploaded this contract',
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_sc_supplier`  (`supplier_id`),
    KEY `idx_sc_primary`   (`supplier_id`, `is_primary`),
    KEY `idx_sc_signed`    (`supplier_id`, `signed_date`),

    -- Cascade RESTRICT to prevent orphaned files when a user is deleted.
    -- If you ever need to deactivate a supplier, set users.is_active = 0;
    -- DO NOT DELETE the user row while contracts exist.
    CONSTRAINT `fk_sc_supplier`
        FOREIGN KEY (`supplier_id`)
        REFERENCES `users`(`id`)
        ON DELETE RESTRICT,

    CONSTRAINT `fk_sc_uploader`
        FOREIGN KEY (`uploaded_by_user_id`)
        REFERENCES `users`(`id`)
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Signed contracts between platform admin and suppliers — immutable; no deletes allowed';

-- ── Done ─────────────────────────────────────────────────────
SELECT CONCAT('supplier_contracts table ready.') AS status;
