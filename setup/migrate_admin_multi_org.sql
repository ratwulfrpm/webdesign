-- ============================================================
-- migrate_admin_multi_org.sql
-- Enables multi-org assignment when inviting admins.
--
-- Changes:
--   1. Adds extra_org_ids JSON column to supplier_invitations
--      (stores additional org IDs beyond the primary org_id).
--   2. Updates supplier_invitations.role ENUM to include 'support'
--      (if not already done by migrate_support_role.sql).
--
-- Safe to re-run: ADD COLUMN IF NOT EXISTS (MySQL 8.0+).
-- For MySQL 5.7 / MariaDB: run only once.
-- ============================================================

USE `apple_login`;

-- 1. Add extra_org_ids column to supplier_invitations
ALTER TABLE `supplier_invitations`
    ADD COLUMN IF NOT EXISTS `extra_org_ids`
        JSON NULL DEFAULT NULL
        COMMENT 'Additional org IDs for admin multi-org invitations'
        AFTER `org_id`;

-- 2. Ensure 'support' is in supplier_invitations.role enum
--    (no-op if already present)
ALTER TABLE `supplier_invitations`
    MODIFY `role`
        ENUM('owner','admin','support','supplier')
        NOT NULL DEFAULT 'supplier';

SELECT 'Admin multi-org migration complete.' AS status;
