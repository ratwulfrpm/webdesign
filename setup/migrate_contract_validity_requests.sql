-- ============================================================
-- migrate_contract_validity_requests.sql
-- Workflow de revision para cambios de vigencia en contratos historicos
--
-- Run:
--   mysql -u root -proot apple_login < setup/migrate_contract_validity_requests.sql
-- ============================================================

USE `apple_login`;

CREATE TABLE IF NOT EXISTS `supplier_contract_validity_requests` (
    `id`                           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `supplier_id`                  INT UNSIGNED      NOT NULL,
    `org_id`                       SMALLINT UNSIGNED NOT NULL,
    `requested_contract_id`        INT UNSIGNED      NOT NULL,
    `current_primary_contract_id`  INT UNSIGNED      NULL DEFAULT NULL,
    `requested_by_user_id`         INT UNSIGNED      NOT NULL,
    `reviewed_by_user_id`          INT UNSIGNED      NULL DEFAULT NULL,
    `status`                       ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `review_comment`               VARCHAR(1000)     NULL DEFAULT NULL,
    `requested_at`                 DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at`                  DATETIME          NULL DEFAULT NULL,
    `created_at`                   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_scvr_supplier_org` (`supplier_id`, `org_id`),
    KEY `idx_scvr_status_requested_at` (`status`, `requested_at`),
    KEY `idx_scvr_requested_contract` (`requested_contract_id`),
    KEY `idx_scvr_current_primary` (`current_primary_contract_id`),
    KEY `idx_scvr_requested_by` (`requested_by_user_id`),
    KEY `idx_scvr_reviewed_by` (`reviewed_by_user_id`),
    CONSTRAINT `fk_scvr_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_scvr_org`
        FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_scvr_requested_contract`
        FOREIGN KEY (`requested_contract_id`) REFERENCES `supplier_contracts`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_scvr_current_primary_contract`
        FOREIGN KEY (`current_primary_contract_id`) REFERENCES `supplier_contracts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_scvr_requested_by`
        FOREIGN KEY (`requested_by_user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_scvr_reviewed_by`
        FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

SELECT 'supplier_contract_validity_requests table ready.' AS status;
