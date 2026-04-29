-- migrate_contact_email_verify.sql
-- Adds email verification columns to supplier_contacts table.
-- Run once:  mysql -u root -proot apple_login < migrate_contact_email_verify.sql

USE `apple_login`;

-- Note: MySQL 5.7 does not support ADD COLUMN IF NOT EXISTS.
-- Only run this once. If columns already exist, skip this file.
ALTER TABLE `supplier_contacts`
  ADD COLUMN `email_pending`        VARCHAR(254) NULL DEFAULT NULL AFTER `email`,
  ADD COLUMN `email_verify_code`    VARCHAR(6)   NULL DEFAULT NULL,
  ADD COLUMN `email_verify_expires` DATETIME     NULL DEFAULT NULL;
