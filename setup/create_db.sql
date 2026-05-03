-- ============================================================
-- apple_login — Full database setup script (fresh install)
-- Run this in phpMyAdmin, MAMP MySQL console, or CLI:
--   mysql -u root -proot < create_db.sql
-- ============================================================

-- 1. Create & select the database
CREATE DATABASE IF NOT EXISTS `apple_login`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `apple_login`;

-- 2. Users table (full schema)
CREATE TABLE IF NOT EXISTS `users` (
    `id`                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `username`               VARCHAR(60)      NOT NULL,
    `email`                  VARCHAR(254)     NOT NULL,
    `password_hash`          VARCHAR(255)     NOT NULL,
    `is_active`              TINYINT(1)       NOT NULL DEFAULT 1,
    `role`                   ENUM('owner','admin','support','supplier','user') NOT NULL DEFAULT 'supplier',
    `failed_attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until`           DATETIME         NULL     DEFAULT NULL,
    `first_login`            TINYINT(1)       NOT NULL DEFAULT 1,
    `preferred_language`     VARCHAR(10)      NOT NULL DEFAULT 'es',
    -- Información General
    `full_name`              VARCHAR(200)     NULL     DEFAULT NULL,
    `company_name`           VARCHAR(200)     NULL     DEFAULT NULL,
    -- Información Legal
    `legal_rep_name`         VARCHAR(200)     NULL     DEFAULT NULL,
    `tax_id`                 VARCHAR(50)      NULL     DEFAULT NULL,
    `legal_rep_id`           VARCHAR(50)      NULL     DEFAULT NULL,
    -- Teléfonos (código país separado del número)
    `company_phone_code`     VARCHAR(10)      NULL     DEFAULT NULL,
    `company_phone_number`   VARCHAR(30)      NULL     DEFAULT NULL,
    `legal_rep_phone_code`   VARCHAR(10)      NULL     DEFAULT NULL,
    `legal_rep_phone_number` VARCHAR(30)      NULL     DEFAULT NULL,
    `phone`                  VARCHAR(30)      NULL     DEFAULT NULL,  -- legacy
    -- Dirección Oficina Principal
    `addr_street`            VARCHAR(300)     NULL     DEFAULT NULL,
    `addr_city`              VARCHAR(100)     NULL     DEFAULT NULL,
    `addr_state`             VARCHAR(100)     NULL     DEFAULT NULL,
    `addr_zip`               VARCHAR(20)      NULL     DEFAULT NULL,
    `addr_country_id`        SMALLINT UNSIGNED NULL    DEFAULT NULL,
    -- Dirección Fábrica
    `factory_street`         VARCHAR(300)     NULL     DEFAULT NULL,
    `factory_city`           VARCHAR(100)     NULL     DEFAULT NULL,
    `factory_state`          VARCHAR(100)     NULL     DEFAULT NULL,
    `factory_zip`            VARCHAR(20)      NULL     DEFAULT NULL,
    `factory_country_id`     SMALLINT UNSIGNED NULL    DEFAULT NULL,
    -- Auditoría
    `created_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email`    (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3. Countries catalog
CREATE TABLE IF NOT EXISTS `countries` (
    `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       CHAR(2)           NOT NULL COMMENT 'ISO 3166-1 alpha-2',
    `phone_code` VARCHAR(8)        NOT NULL DEFAULT '+1',
    `name_es`    VARCHAR(100)      NOT NULL,
    `name_en`    VARCHAR(100)      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Password-request queue
CREATE TABLE IF NOT EXISTS `password_requests` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_name` VARCHAR(200)  NOT NULL,
    `email`        VARCHAR(254)  NOT NULL,
    `username`     VARCHAR(60)   NULL DEFAULT NULL,
    `notes`        TEXT          NULL DEFAULT NULL,
    `status`       ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
    `requested_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`  DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_email`  (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 5. Supplier contacts
CREATE TABLE IF NOT EXISTS `supplier_contacts` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `supplier_id`  INT UNSIGNED  NOT NULL,
    `name`         VARCHAR(200)  NOT NULL,
    `role`         VARCHAR(100)  NULL DEFAULT NULL,
    `email`        VARCHAR(254)  NULL DEFAULT NULL,
    `phone_code`   VARCHAR(8)    NULL DEFAULT NULL,
    `phone_number` VARCHAR(30)   NULL DEFAULT NULL,
    `is_primary`   TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_supplier` (`supplier_id`),
    CONSTRAINT `fk_contacts_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Organizations (multi-org / multi-tenant)
CREATE TABLE IF NOT EXISTS `organizations` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`        VARCHAR(60)       NOT NULL COMMENT 'URL-safe identifier',
    `name`        VARCHAR(200)      NOT NULL COMMENT 'Display name',
    `description` VARCHAR(500)      NULL     DEFAULT NULL,
    `is_active`   TINYINT(1)        NOT NULL DEFAULT 1,
    `created_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. User ↔ Organization memberships
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

-- 8. Supplier enrollment invitations
--    Only the SHA-256 hash of the plain bearer token is stored.
CREATE TABLE IF NOT EXISTS `supplier_invitations` (
    `id`                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `token_hash`          CHAR(64)          NOT NULL COMMENT 'SHA-256 of the plain bearer token',
    `org_id`              SMALLINT UNSIGNED NOT NULL,
    `extra_org_ids`       JSON              NULL DEFAULT NULL COMMENT 'Additional org IDs for admin multi-org invitations',
    `role`                ENUM('owner','admin','support','supplier') NOT NULL DEFAULT 'supplier',
    `invited_email`       VARCHAR(254)      NULL DEFAULT NULL,
    `status`              ENUM('pending','used','expired','revoked') NOT NULL DEFAULT 'pending',
    `expires_at`          DATETIME          NOT NULL,
    `created_by_user_id`  INT UNSIGNED      NOT NULL,
    `used_by_user_id`     INT UNSIGNED      NULL DEFAULT NULL,
    `used_at`             DATETIME          NULL DEFAULT NULL,
    `revoked_at`          DATETIME          NULL DEFAULT NULL,
    `created_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash`   (`token_hash`),
    KEY `idx_status`             (`status`),
    KEY `idx_org`                (`org_id`),
    KEY `idx_created_by`         (`created_by_user_id`),
    KEY `idx_used_by`            (`used_by_user_id`),
    CONSTRAINT `fk_inv_org`
        FOREIGN KEY (`org_id`) REFERENCES `organizations`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inv_created_by`
        FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inv_used_by`
        FOREIGN KEY (`used_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Demo admin user  (password: Demo123!)
INSERT INTO `users` (`username`, `email`, `password_hash`, `is_active`, `role`, `first_login`)
VALUES (
    'demo',
    'demo@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    'admin',
    0
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- 5. Demo supplier user  (password: Demo123!)
INSERT INTO `users` (`username`, `email`, `password_hash`, `is_active`, `role`, `first_login`)
VALUES (
    'supplier',
    'supplier@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    'supplier',
    1
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- Done!
SELECT CONCAT('DB ready.  Admin: demo@local / Demo123!  |  Supplier: supplier@local / Demo123!') AS status;

