-- ============================================================
-- migrate_assignments_fees_validity.sql
-- Enhance quote_assignments with dynamic fees and validity
-- 
-- Adds support for:
--   - Multiple fee types (profit, transport, tax)
--   - % or fixed amount for each fee
--   - Dynamic validity (hours/days, up to 7 days)
--
-- Backward compatible: existing quotes retain existing behavior
--
-- Run once:
--   Get-Content setup/migrate_assignments_fees_validity.sql | mysql -u root -proot apple_login
-- ============================================================

USE `apple_login`;

-- ── 1. Enhance quote_assignments table ───────────────────────
ALTER TABLE `quote_assignments`
ADD COLUMN `profit_calculation_type` ENUM('percentage', 'fixed_amount') NOT NULL DEFAULT 'percentage'
  COMMENT 'How profit is calculated' AFTER `discount_percentage`,
ADD COLUMN `profit_fixed_amount` DECIMAL(12,2) NULL DEFAULT NULL
  COMMENT 'Fixed profit amount if calculation_type = fixed_amount' AFTER `profit_calculation_type`,
ADD COLUMN `transport_calculation_type` ENUM('percentage', 'fixed_amount') NULL DEFAULT NULL
  COMMENT 'How transport is calculated (null if not used)' AFTER `profit_fixed_amount`,
ADD COLUMN `transport_percentage` DECIMAL(6,2) NULL DEFAULT NULL
  COMMENT '0.00–100.00 percent applied to subtotal' AFTER `transport_calculation_type`,
ADD COLUMN `transport_fixed_amount` DECIMAL(12,2) NULL DEFAULT NULL
  COMMENT 'Fixed transport amount if calculation_type = fixed_amount' AFTER `transport_percentage`,
ADD COLUMN `tax_calculation_type` ENUM('percentage', 'fixed_amount') NULL DEFAULT NULL
  COMMENT 'How tax is calculated (null if not used)' AFTER `transport_fixed_amount`,
ADD COLUMN `tax_percentage` DECIMAL(6,2) NULL DEFAULT NULL
  COMMENT '0.00–100.00 percent applied to subtotal + transport' AFTER `tax_calculation_type`,
ADD COLUMN `tax_fixed_amount` DECIMAL(12,2) NULL DEFAULT NULL
  COMMENT 'Fixed tax amount if calculation_type = fixed_amount' AFTER `tax_percentage`,
ADD COLUMN `validity_amount` INT UNSIGNED NOT NULL DEFAULT 7
  COMMENT 'Duration amount (number of hours or days)' AFTER `tax_fixed_amount`,
ADD COLUMN `validity_unit` ENUM('hours', 'days') NOT NULL DEFAULT 'days'
  COMMENT 'Unit of validity duration' AFTER `validity_amount`;

-- ── 2. Enhance quote_assignment_items for per-item profit type ──
ALTER TABLE `quote_assignment_items`
ADD COLUMN `profit_calculation_type` ENUM('percentage', 'fixed_amount') NOT NULL DEFAULT 'percentage'
  COMMENT 'Per-item profit calculation type' AFTER `profit_percentage`,
ADD COLUMN `profit_fixed_amount` DECIMAL(12,2) NULL DEFAULT NULL
  COMMENT 'Fixed profit amount for this item' AFTER `profit_calculation_type`;

-- ── 3. Add indexes for new searches ───────────────────────────
ALTER TABLE `quote_assignments`
ADD INDEX `idx_qa_validity` (`validity_unit`, `validity_amount`);

-- ── Done ──────────────────────────────────────────────────────
SELECT 'Assignments fees and validity migration complete.' AS status;
