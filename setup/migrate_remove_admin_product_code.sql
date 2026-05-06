-- ============================================================
-- migrate_remove_admin_product_code.sql
-- Removes the admin_product_code column from supplier_products.
--
-- Background:
--   The field was originally designed to allow admins to assign
--   a globally unique alternative code to products. In practice
--   the column was always NULL (never populated via the UI or
--   API). All API responses intentionally omitted the field.
--   The internal_product_code (auto-generated, APP-PRD-XXXXXXXX)
--   fulfils the role of the platform's internal identifier.
--
-- Run once on existing installations:
--   mysql -u root -p apple_login < migrate_remove_admin_product_code.sql
--
-- Safe to inspect first:
--   SELECT COUNT(*) FROM supplier_products
--     WHERE admin_product_code IS NOT NULL;
--   -- If result is 0, safe to proceed immediately.
--
-- Note: Compatible with MySQL 5.7+ (no IF EXISTS on DDL).
-- ============================================================

USE `apple_login`;

-- 1. Drop the unique index before dropping the column
ALTER TABLE supplier_products DROP INDEX `uq_admin_code`;

-- 2. Drop the column
ALTER TABLE supplier_products DROP COLUMN `admin_product_code`;

SELECT CONCAT('admin_product_code column removed from supplier_products.') AS status;
