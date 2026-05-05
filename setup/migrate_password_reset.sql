-- ============================================================
-- migrate_password_reset.sql
-- Adds must_change_password and temporary password expiry
-- fields to the users table.
--
-- Run once:
--   mysql -u root -proot apple_login < setup/migrate_password_reset.sql
-- ============================================================

USE `apple_login`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `must_change_password`             TINYINT(1)  NOT NULL DEFAULT 0
        COMMENT 'Forces user to change password on next login (0=no, 1=yes)'
        AFTER `first_login`,

    ADD COLUMN IF NOT EXISTS `temporary_password_created_at`    DATETIME    NULL DEFAULT NULL
        COMMENT 'When the temporary password was generated (admin/owner reset)'
        AFTER `must_change_password`,

    ADD COLUMN IF NOT EXISTS `temporary_password_expires_at`    DATETIME    NULL DEFAULT NULL
        COMMENT 'Temporary password expiry (NULL = no temp pwd active)'
        AFTER `temporary_password_created_at`;

-- Index for efficient expiry checks on login
ALTER TABLE `users`
    ADD INDEX IF NOT EXISTS `idx_must_change_password` (`must_change_password`);

SELECT CONCAT(
    'Migration complete. ',
    'Columns added: must_change_password, temporary_password_created_at, ',
    'temporary_password_expires_at'
) AS status;
