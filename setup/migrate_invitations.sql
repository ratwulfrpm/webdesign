-- ============================================================
-- migrate_invitations.sql — Supplier enrollment via invitation
-- Run once against the apple_login database.
--
--   mysql -u root -proot apple_login < setup/migrate_invitations.sql
--
-- Requires: organizations table already created (migrate_orgs.sql)
-- ============================================================

USE `apple_login`;

-- ── supplier_invitations ──────────────────────────────────────
-- Stores one row per generated invitation.
-- Only the SHA-256 hash of the plain bearer token is stored here.
-- The plain token travels in the enroll link URL only.
CREATE TABLE IF NOT EXISTS `supplier_invitations` (
    `id`                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,

    -- Cryptographic token: NEVER store plain token.
    -- Plain token = random_bytes(32) -> bin2hex (64 chars)
    -- Stored hash  = hash('sha256', plain_token)          (64 chars)
    `token_hash`          CHAR(64)          NOT NULL COMMENT 'SHA-256 of the plain bearer token',

    -- Which organization is this invitation for
    `org_id`              SMALLINT UNSIGNED NOT NULL,

    -- Role the new user will receive in that organization
    `role`                ENUM('owner','admin','supplier','user') NOT NULL DEFAULT 'supplier',

    -- Optional: pre-fill email on enrollment form (NULL = any email allowed)
    `invited_email`       VARCHAR(254)      NULL DEFAULT NULL,

    -- Lifecycle status
    `status`              ENUM('pending','used','expired','revoked') NOT NULL DEFAULT 'pending',

    -- When the token stops being valid
    `expires_at`          DATETIME          NOT NULL,

    -- Audit: who created it
    `created_by_user_id`  INT UNSIGNED      NOT NULL,

    -- Audit: who used it (populated on successful enrollment)
    `used_by_user_id`     INT UNSIGNED      NULL DEFAULT NULL,
    `used_at`             DATETIME          NULL DEFAULT NULL,

    -- Audit: when it was revoked
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
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ── Done ──────────────────────────────────────────────────────
SELECT 'supplier_invitations table ready.' AS status;
