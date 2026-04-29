-- ── Migration: Assignment management actions ─────────────────────────────────
-- Adds:  'deleted' status,  revoked_by_user_id,  deleted_at,
--        deleted_by_user_id,  parent_assignment_id  to product_assignments.
-- Run once against apple_login.

-- 1. Extend the status ENUM to include 'deleted'
ALTER TABLE product_assignments
    MODIFY COLUMN status
        ENUM('active','expired','revoked','deleted')
        NOT NULL DEFAULT 'active';

-- 2. Add audit/lineage columns
ALTER TABLE product_assignments
    ADD COLUMN revoked_by_user_id   INT UNSIGNED NULL DEFAULT NULL
        AFTER revoked_at,
    ADD COLUMN deleted_at           DATETIME     NULL DEFAULT NULL
        AFTER revoked_by_user_id,
    ADD COLUMN deleted_by_user_id   INT UNSIGNED NULL DEFAULT NULL
        AFTER deleted_at,
    ADD COLUMN parent_assignment_id INT UNSIGNED NULL DEFAULT NULL
        AFTER deleted_by_user_id;

-- 3. Index for parent relationship (quick lineage lookup)
ALTER TABLE product_assignments
    ADD INDEX idx_pa_parent (parent_assignment_id);
