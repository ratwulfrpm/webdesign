-- ============================================================
-- migrate_business_units.sql â€” Multi-tenant (business unit) migration
-- MySQL 5.7 compatible (no ADD COLUMN IF NOT EXISTS).
-- Run once:
--   mysql -u root -proot apple_login < migrate_business_units.sql
-- ============================================================

USE `apple_login`;

-- â”€â”€ 1. Ensure organizations table is present â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS `organizations` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`        VARCHAR(60)       NOT NULL COMMENT 'URL-safe identifier',
    `name`        VARCHAR(200)      NOT NULL COMMENT 'Display name',
    `description` VARCHAR(500)      NULL     DEFAULT NULL,
    `is_active`   TINYINT(1)        NOT NULL DEFAULT 1,
    `created_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add updated_at if missing (MySQL 5.7 compatible: use INFORMATION_SCHEMA check)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'organizations'
      AND COLUMN_NAME  = 'updated_at'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `organizations` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
    'SELECT "organizations.updated_at already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- â”€â”€ 2. Ensure org_members table is present â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
CREATE TABLE IF NOT EXISTS `org_members` (
    `id`         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED      NOT NULL,
    `org_id`     SMALLINT UNSIGNED NOT NULL,
    `role`       ENUM('owner','admin','support','supplier','user') NOT NULL DEFAULT 'user',
    `is_active`  TINYINT(1)        NOT NULL DEFAULT 1,
    `joined_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_org` (`user_id`, `org_id`),
    CONSTRAINT `fk_om_user` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_om_org`  FOREIGN KEY (`org_id`)
        REFERENCES `organizations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- â”€â”€ 3. Seed JShop & JBusiness if not already present â”€â”€â”€â”€â”€â”€â”€â”€â”€
INSERT INTO `organizations` (`slug`, `name`, `description`) VALUES
    ('jshop',     'JShop',     'Plataforma de proveedores JShop'),
    ('jbusiness', 'JBusiness', 'Portal de socios JBusiness')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- â”€â”€ 4. Migrate existing users to jshop if not in any org â”€â”€â”€â”€â”€
INSERT INTO `org_members` (`user_id`, `org_id`, `role`)
SELECT u.id,
       (SELECT id FROM organizations WHERE slug = 'jshop') AS org_id,
       u.role
FROM users u
WHERE NOT EXISTS (
    SELECT 1 FROM org_members om WHERE om.user_id = u.id
)
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

-- â”€â”€ 5. Add org_id to supplier_products (nullable first) â”€â”€â”€â”€â”€â”€
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'supplier_products'
      AND COLUMN_NAME  = 'org_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `supplier_products` ADD COLUMN `org_id` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT \'FK â†’ organizations.id\' AFTER `supplier_id`',
    'SELECT "supplier_products.org_id already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- â”€â”€ 6. Back-fill org_id for existing products â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
UPDATE `supplier_products` sp
    JOIN (
        SELECT om.user_id,
               COALESCE(
                   MIN(CASE WHEN o.slug = 'jshop' THEN om.org_id END),
                   MIN(om.org_id)
               ) AS preferred_org_id
          FROM org_members om
          JOIN organizations o ON o.id = om.org_id
         WHERE om.is_active = 1
         GROUP BY om.user_id
    ) preferred ON preferred.user_id = sp.supplier_id
SET sp.org_id = preferred.preferred_org_id
WHERE sp.org_id IS NULL;

-- Fall-back: suppliers with no org membership â†’ assign jshop
UPDATE `supplier_products` sp
SET sp.org_id = (SELECT id FROM organizations WHERE slug = 'jshop' LIMIT 1)
WHERE sp.org_id IS NULL;

-- â”€â”€ 7. Enforce NOT NULL on supplier_products.org_id â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
ALTER TABLE `supplier_products`
    MODIFY COLUMN `org_id` SMALLINT UNSIGNED NOT NULL
        COMMENT 'FK â†’ organizations.id (tenant isolation)';

-- Add FK (ignore error if already exists)
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'supplier_products'
      AND CONSTRAINT_NAME = 'fk_sp_org'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `supplier_products` ADD CONSTRAINT `fk_sp_org` FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE RESTRICT',
    'SELECT "fk_sp_org already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Add index (ignore error if already exists)
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'supplier_products'
      AND INDEX_NAME   = 'idx_sp_org'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `supplier_products` ADD INDEX `idx_sp_org` (`org_id`)',
    'SELECT "idx_sp_org already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- â”€â”€ 8. Add org_id to supplier_contracts (nullable first) â”€â”€â”€â”€â”€
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'supplier_contracts'
      AND COLUMN_NAME  = 'org_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `supplier_contracts` ADD COLUMN `org_id` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT \'FK â†’ organizations.id\' AFTER `supplier_id`',
    'SELECT "supplier_contracts.org_id already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- â”€â”€ 9. Back-fill org_id for existing contracts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
UPDATE `supplier_contracts` sc
    JOIN (
        SELECT om.user_id,
               COALESCE(
                   MIN(CASE WHEN o.slug = 'jshop' THEN om.org_id END),
                   MIN(om.org_id)
               ) AS preferred_org_id
          FROM org_members om
          JOIN organizations o ON o.id = om.org_id
         WHERE om.is_active = 1
         GROUP BY om.user_id
    ) preferred ON preferred.user_id = sc.supplier_id
SET sc.org_id = preferred.preferred_org_id
WHERE sc.org_id IS NULL;

UPDATE `supplier_contracts` sc
SET sc.org_id = (SELECT id FROM organizations WHERE slug = 'jshop' LIMIT 1)
WHERE sc.org_id IS NULL;

-- â”€â”€ 10. Enforce NOT NULL + FK on supplier_contracts.org_id â”€â”€â”€
ALTER TABLE `supplier_contracts`
    MODIFY COLUMN `org_id` SMALLINT UNSIGNED NOT NULL
        COMMENT 'FK â†’ organizations.id (tenant isolation)';

SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'supplier_contracts'
      AND CONSTRAINT_NAME = 'fk_sc_org'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `supplier_contracts` ADD CONSTRAINT `fk_sc_org` FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE RESTRICT',
    'SELECT "fk_sc_org already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'supplier_contracts'
      AND INDEX_NAME   = 'idx_sc_org'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `supplier_contracts` ADD INDEX `idx_sc_org` (`org_id`)',
    'SELECT "idx_sc_org already exists"'
);
PREPARE _stmt FROM @sql; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- â”€â”€ Done â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
SELECT 'Business unit migration complete.' AS status;
SELECT o.slug, o.name, COUNT(DISTINCT om.user_id) AS members
FROM organizations o
LEFT JOIN org_members om ON om.org_id = o.id AND om.is_active = 1
GROUP BY o.id, o.slug, o.name;
