-- ============================================================
-- apple_login — Migration script (run on existing installations)
-- Adds all new columns required by the full business requirement.
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE).
-- ============================================================

USE `apple_login`;

-- ── 1. Add new columns to users ──────────────────────────────

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `is_active`           TINYINT(1)                          NOT NULL DEFAULT 1          AFTER `email`,
    ADD COLUMN IF NOT EXISTS `role`                ENUM('admin','supplier')             NOT NULL DEFAULT 'supplier'  AFTER `is_active`,
    ADD COLUMN IF NOT EXISTS `failed_attempts`     TINYINT UNSIGNED                    NOT NULL DEFAULT 0           AFTER `role`,
    ADD COLUMN IF NOT EXISTS `locked_until`        DATETIME                            NULL     DEFAULT NULL        AFTER `failed_attempts`,
    ADD COLUMN IF NOT EXISTS `first_login`         TINYINT(1)                          NOT NULL DEFAULT 1           AFTER `locked_until`,
    ADD COLUMN IF NOT EXISTS `preferred_language`  VARCHAR(10)                         NOT NULL DEFAULT 'es'        AFTER `first_login`,
    ADD COLUMN IF NOT EXISTS `full_name`           VARCHAR(200)                        NULL     DEFAULT NULL        AFTER `preferred_language`,
    ADD COLUMN IF NOT EXISTS `company_name`        VARCHAR(200)                        NULL     DEFAULT NULL        AFTER `full_name`,
    ADD COLUMN IF NOT EXISTS `phone`               VARCHAR(30)                         NULL     DEFAULT NULL        AFTER `company_name`;

-- ── 2. Set the demo user as admin with first_login = 0 ──────
UPDATE `users`
   SET `role`        = 'admin',
       `is_active`   = 1,
       `first_login` = 0
 WHERE `username` = 'demo';

-- ── 3. Insert a demo supplier ────────────────────────────────
--    Password: Supplier1!
--    Run setup/generate_hash.php or generate with:
--    password_hash('Supplier1!', PASSWORD_BCRYPT, ['cost'=>12])
INSERT IGNORE INTO `users`
    (`username`, `email`, `password_hash`, `is_active`, `role`, `first_login`)
VALUES (
    'supplier',
    'supplier@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    'supplier',
    1
);

-- ── 4. Create password_requests table ───────────────────────
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

-- Done
SELECT CONCAT('Migration complete. Users table updated, password_requests table ready.') AS status;
