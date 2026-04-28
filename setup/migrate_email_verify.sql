-- ============================================================
-- migrate_email_verify.sql
-- Adds email-change verification support to the users table.
--
-- Compatible with MySQL 5.7+
-- Run once via phpMyAdmin SQL tab, or:
--   Get-Content migrate_email_verify.sql | mysql -u root -proot apple_login
-- ============================================================

USE `apple_login`;

-- Procedure-based approach for MySQL 5.7 compatibility
DROP PROCEDURE IF EXISTS `_add_email_verify_columns`;
DELIMITER //
CREATE PROCEDURE `_add_email_verify_columns`()
BEGIN
    -- email_pending
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND COLUMN_NAME  = 'email_pending'
    ) THEN
        ALTER TABLE `users`
          ADD COLUMN `email_pending`
            VARCHAR(254) NULL DEFAULT NULL
            COMMENT 'New email awaiting verification (not yet active)'
            AFTER `email`;
    END IF;

    -- email_verify_code
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND COLUMN_NAME  = 'email_verify_code'
    ) THEN
        ALTER TABLE `users`
          ADD COLUMN `email_verify_code`
            CHAR(6) NULL DEFAULT NULL
            COMMENT '6-digit one-time code sent to email_pending'
            AFTER `email_pending`;
    END IF;

    -- email_verify_expires
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND COLUMN_NAME  = 'email_verify_expires'
    ) THEN
        ALTER TABLE `users`
          ADD COLUMN `email_verify_expires`
            DATETIME NULL DEFAULT NULL
            COMMENT 'Timestamp when the verification code expires (2-hour window)'
            AFTER `email_verify_code`;
    END IF;

    -- index on email_pending
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND INDEX_NAME   = 'idx_email_pending'
    ) THEN
        ALTER TABLE `users`
          ADD INDEX `idx_email_pending` (`email_pending`(20));
    END IF;
END //
DELIMITER ;

CALL `_add_email_verify_columns`();
DROP PROCEDURE IF EXISTS `_add_email_verify_columns`;

SELECT 'Migration complete: email verification columns added.' AS status;
