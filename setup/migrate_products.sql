-- ============================================================
-- apple_login — Products table migration
-- Run once in phpMyAdmin or via CLI:
--   mysql -u root -proot apple_login < migrate_products.sql
--
-- Safe to run multiple times (IF NOT EXISTS).
-- ============================================================

USE `apple_login`;

-- ── 1. Create supplier_products table ────────────────────────
CREATE TABLE IF NOT EXISTS `supplier_products` (
    `id`                     INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `supplier_id`            INT UNSIGNED    NOT NULL  COMMENT 'FK → users.id (role=supplier)',
    `supplier_product_code`  VARCHAR(100)    NOT NULL  COMMENT 'Unique within a supplier',
    `product_name`           VARCHAR(300)    NOT NULL,
    `technical_description`  TEXT            NULL DEFAULT NULL,
    `price_fob`              DECIMAL(15,2)   NULL DEFAULT NULL,
    `price_cif`              DECIMAL(15,2)   NULL DEFAULT NULL,
    `active`                 TINYINT(1)      NOT NULL DEFAULT 1,
    `created_by`             INT UNSIGNED    NULL DEFAULT NULL COMMENT 'user_id who created the record',
    `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Each supplier can only have one product with a given code
    UNIQUE KEY `uq_supplier_code` (`supplier_id`, `supplier_product_code`),

    KEY `idx_supplier`    (`supplier_id`),
    KEY `idx_active`      (`active`),

    CONSTRAINT `fk_products_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `users`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Products offered by suppliers to the platform admin';

-- ── Done ─────────────────────────────────────────────────────
SELECT CONCAT('supplier_products table ready.') AS status;
