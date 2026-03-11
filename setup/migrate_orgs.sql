-- ============================================================
-- migrate_orgs.sql — Multi-organization RBAC migration
-- Run once in phpMyAdmin or via setup/run_migrate_orgs.php
--
-- What this does:
--   1. Creates `organizations` table
--   2. Creates `org_members` table (user ↔ org with per-org role)
--   3. Seeds jshop and jbusiness orgs
--   4. Migrates existing users: all → jshop with their current role
--   5. Creates demo accounts for multi-org testing
-- ============================================================

USE `apple_login`;

-- ── 1. Organizations ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `organizations` (
    `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`        VARCHAR(60)       NOT NULL COMMENT 'URL-safe identifier',
    `name`        VARCHAR(200)      NOT NULL COMMENT 'Display name',
    `description` VARCHAR(500)      NULL     DEFAULT NULL,
    `is_active`   TINYINT(1)        NOT NULL DEFAULT 1,
    `created_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── 2. User ↔ Organization memberships ───────────────────────
-- Each row = one user's membership in one organization, with a role scoped to that org.
CREATE TABLE IF NOT EXISTS `org_members` (
    `id`         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED      NOT NULL,
    `org_id`     SMALLINT UNSIGNED NOT NULL,
    `role`       ENUM('owner','admin','supplier','user') NOT NULL DEFAULT 'user',
    `is_active`  TINYINT(1)        NOT NULL DEFAULT 1,
    `joined_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_org` (`user_id`, `org_id`),
    CONSTRAINT `fk_om_user` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_om_org`  FOREIGN KEY (`org_id`)
        REFERENCES `organizations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── 3. Seed organizations ─────────────────────────────────────
INSERT INTO `organizations` (`slug`, `name`, `description`) VALUES
    ('jshop',     'JShop',     'Plataforma de proveedores JShop'),
    ('jbusiness', 'JBusiness', 'Portal de socios JBusiness')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ── 4. Migrate existing users to jshop ───────────────────────
-- All current users inherit their global role as their jshop org role.
INSERT INTO `org_members` (`user_id`, `org_id`, `role`)
SELECT u.id,
       (SELECT id FROM organizations WHERE slug = 'jshop') AS org_id,
       u.role
FROM users u
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

-- ── 5. Demo multi-org users ───────────────────────────────────

-- 5a. A user that belongs to BOTH orgs (to test the org picker)
--     Password: Demo123!
INSERT INTO `users`
    (`username`, `email`, `password_hash`, `is_active`, `role`, `first_login`)
VALUES (
    'multiorg',
    'multiorg@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    'admin',
    0
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- Add multiorg user to jshop as admin and jbusiness as owner
INSERT INTO `org_members` (`user_id`, `org_id`, `role`)
SELECT u.id, o.id, 'admin'
FROM users u, organizations o
WHERE u.username = 'multiorg' AND o.slug = 'jshop'
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

INSERT INTO `org_members` (`user_id`, `org_id`, `role`)
SELECT u.id, o.id, 'owner'
FROM users u, organizations o
WHERE u.username = 'multiorg' AND o.slug = 'jbusiness'
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

-- 5b. Demo owner for jshop  (password: Demo123!)
INSERT INTO `users`
    (`username`, `email`, `password_hash`, `is_active`, `role`, `first_login`)
VALUES (
    'owner',
    'owner@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    'owner',
    0
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

INSERT INTO `org_members` (`user_id`, `org_id`, `role`)
SELECT u.id, o.id, 'owner'
FROM users u, organizations o
WHERE u.username = 'owner' AND o.slug = 'jshop'
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

-- 5c. Demo user for jbusiness only  (password: Demo123!)
INSERT INTO `users`
    (`username`, `email`, `password_hash`, `is_active`, `role`, `first_login`)
VALUES (
    'jbiz_user',
    'jbiz@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1,
    'user',
    0
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

INSERT INTO `org_members` (`user_id`, `org_id`, `role`)
SELECT u.id, o.id, 'user'
FROM users u, organizations o
WHERE u.username = 'jbiz_user' AND o.slug = 'jbusiness'
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

-- ── Done ──────────────────────────────────────────────────────
SELECT 'Migration complete.' AS status;
SELECT u.username, o.slug AS org, m.role
FROM users u
JOIN org_members m ON u.id = m.user_id
JOIN organizations o ON o.id = m.org_id
ORDER BY u.username, o.slug;
