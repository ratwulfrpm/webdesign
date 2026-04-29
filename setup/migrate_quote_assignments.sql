-- ============================================================
-- migrate_quote_assignments.sql  (MySQL 5.7 compatible)
-- Multi-product quotation system (replaces single-product product_assignments
-- for new quotes while keeping old table for backward compatibility).
--
-- Run once:
--   Get-Content setup/migrate_quote_assignments.sql | mysql -u root -proot apple_login
-- ============================================================

USE `apple_login`;

-- ── 1. Master quotation table ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `quote_assignments` (
    `id`                     BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `org_id`                 SMALLINT UNSIGNED NOT NULL,
    `assigned_customer_name` VARCHAR(200)      NOT NULL,
    `company_name`           VARCHAR(200)      NULL DEFAULT NULL,
    `special_conditions`     TEXT              NULL DEFAULT NULL,
    `discount_percentage`    DECIMAL(6,2)      NULL DEFAULT NULL
                             COMMENT '0.00–100.00 percent applied to total',
    `token_hash`             CHAR(64)          NOT NULL
                             COMMENT 'SHA-256 hex of plain bearer token',
    `status`                 ENUM('active','expired','revoked','deleted')
                             NOT NULL DEFAULT 'active',
    `valid_from`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`             DATETIME          NOT NULL,
    `created_by_user_id`     INT UNSIGNED      NOT NULL,
    `revoked_at`             DATETIME          NULL DEFAULT NULL,
    `revoked_by_user_id`     INT UNSIGNED      NULL DEFAULT NULL,
    `deleted_at`             DATETIME          NULL DEFAULT NULL,
    `deleted_by_user_id`     INT UNSIGNED      NULL DEFAULT NULL,
    `parent_quote_id`        BIGINT UNSIGNED   NULL DEFAULT NULL
                             COMMENT 'Set when regenerating a link from an existing quote',
    `viewed_at`              DATETIME          NULL DEFAULT NULL
                             COMMENT 'First successful access',
    `last_viewed_at`         DATETIME          NULL DEFAULT NULL
                             COMMENT 'Most recent access',
    `view_count`             INT UNSIGNED      NOT NULL DEFAULT 0,
    `created_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                               ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_qa_token`     (`token_hash`),
    KEY         `idx_qa_status`   (`status`),
    KEY         `idx_qa_org`      (`org_id`),
    KEY         `idx_qa_expires`  (`expires_at`),
    KEY         `idx_qa_creator`  (`created_by_user_id`),
    KEY         `idx_qa_parent`   (`parent_quote_id`),

    CONSTRAINT `fk_qa_creator`
        FOREIGN KEY (`created_by_user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Master multi-product quotation links';

-- ── 2. Line items per quotation ───────────────────────────────
CREATE TABLE IF NOT EXISTS `quote_assignment_items` (
    `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `quote_assignment_id` BIGINT UNSIGNED NOT NULL,
    `product_id`          INT UNSIGNED  NOT NULL,
    `price_base_type`     ENUM('fob','cif') NOT NULL,
    `price_base_amount`   DECIMAL(12,2) NOT NULL
                          COMMENT 'Snapshot of base price at creation',
    `profit_percentage`   DECIMAL(6,2)  NOT NULL,
    `final_unit_price`    DECIMAL(12,2) NOT NULL
                          COMMENT 'Server-calculated; never recalculated',
    `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_qai_quote`   (`quote_assignment_id`),
    KEY `idx_qai_product` (`product_id`),

    CONSTRAINT `fk_qai_quote`
        FOREIGN KEY (`quote_assignment_id`)
        REFERENCES `quote_assignments`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qai_product`
        FOREIGN KEY (`product_id`)
        REFERENCES `supplier_products`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Line items: one row per product per quotation';

-- ── 3. Performance indexes on supplier_products ───────────────
-- These will produce a warning/error if already exist; safe to ignore on re-run.

-- Product name prefix index
ALTER TABLE `supplier_products`
    ADD INDEX `idx_sp_product_name` (`product_name`(100));

-- FULLTEXT index (InnoDB FULLTEXT supported since MySQL 5.6)
ALTER TABLE `supplier_products`
    ADD FULLTEXT INDEX `ft_sp_search` (`product_name`, `technical_description`);

-- ── Done ──────────────────────────────────────────────────────
SELECT 'quote_assignments migration complete.' AS status;
