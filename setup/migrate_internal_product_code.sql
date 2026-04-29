-- =============================================================
-- migrate_internal_product_code.sql
-- Adds the internal_product_code column to supplier_products.
--
-- This code is application-generated, globally unique, and must
-- NEVER be derived from or reveal the supplier's own product code.
--
-- Format : APP-PRD-XXXXXXXX  (8 random chars from an unambiguous
--           base-32 alphabet — no O, 0, I, 1 to avoid confusion)
-- =============================================================

-- 1. Add the nullable column (nullable during backfill phase only)
ALTER TABLE supplier_products
    ADD COLUMN internal_product_code VARCHAR(20) NULL DEFAULT NULL
    AFTER admin_product_code;

-- 2. Add unique index
ALTER TABLE supplier_products
    ADD UNIQUE INDEX uq_internal_product_code (internal_product_code);
