-- ============================================================
-- migrate_product_assignments.sql
-- Creates the product_assignments table for private quotation
-- links (secret links) assigned by admin/owner to customer clients.
--
-- Run once against the apple_login database:
--   mysql -u root -proot apple_login < setup/migrate_product_assignments.sql
--
-- Requires: supplier_products and users tables already exist.
-- ============================================================

USE `apple_login`;

CREATE TABLE IF NOT EXISTS `product_assignments` (
    `id`                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,

    -- The product being shared
    `product_id`            INT UNSIGNED      NOT NULL,

    -- Organization context of the admin/owner who created this
    `org_id`                SMALLINT UNSIGNED NOT NULL,

    -- Customer reference name (not a system user)
    `assigned_customer_name` VARCHAR(200)     NOT NULL,

    -- Cryptographic token: NEVER store plain token.
    -- plain_token = bin2hex(random_bytes(32))  [64 hex chars]
    -- token_hash  = hash('sha256', plain_token) [64 hex chars]
    -- The plain token travels in the quote URL only; never stored.
    `token_hash`            CHAR(64)          NOT NULL
                            COMMENT 'SHA-256 hex of the plain bearer token',

    -- Lifecycle status
    `status`                ENUM('active','expired','revoked')
                            NOT NULL DEFAULT 'active',

    -- Which price was used as base (FOB or CIF)
    `price_base_type`       ENUM('fob','cif') NOT NULL,

    -- Snapshot of the base price at creation time (historical record)
    `price_base_amount`     DECIMAL(12,2)     NOT NULL,

    -- Profit margin applied (0.00 – 999.00)
    `profit_percentage`     DECIMAL(6,2)      NOT NULL,

    -- Final price shown to client (server-calculated, immutable snapshot)
    -- final_price = price_base_amount * (1 + profit_percentage / 100)
    `final_price`           DECIMAL(12,2)     NOT NULL,

    -- Validity window
    `valid_from`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`            DATETIME          NOT NULL,

    -- Audit: who created this assignment
    `created_by_user_id`    INT UNSIGNED      NOT NULL,

    -- Optional revocation
    `revoked_at`            DATETIME          NULL DEFAULT NULL,

    -- Access tracking
    `viewed_at`             DATETIME          NULL DEFAULT NULL
                            COMMENT 'First successful access',
    `last_viewed_at`        DATETIME          NULL DEFAULT NULL
                            COMMENT 'Most recent successful access',
    `view_count`            INT UNSIGNED      NOT NULL DEFAULT 0,

    `created_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_token_hash`  (`token_hash`),
    KEY         `idx_status`     (`status`),
    KEY         `idx_product`    (`product_id`),
    KEY         `idx_org`        (`org_id`),
    KEY         `idx_expires`    (`expires_at`),
    KEY         `idx_created_by` (`created_by_user_id`),

    CONSTRAINT `fk_pa_product`
        FOREIGN KEY (`product_id`)
        REFERENCES `supplier_products`(`id`) ON DELETE CASCADE,

    CONSTRAINT `fk_pa_created_by`
        FOREIGN KEY (`created_by_user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Private product quotation links for customer clients';

-- ── Done ──────────────────────────────────────────────────────
SELECT 'product_assignments table ready.' AS status;
