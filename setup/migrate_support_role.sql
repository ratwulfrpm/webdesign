-- ============================================================
-- migrate_support_role.sql
-- Adds the 'support' sub-role to the RBAC system.
--
-- Design note: `org_members` is the authoritative source for
-- user ↔ business-unit assignments. The VIEW `user_business_units`
-- exposes support assignments without duplicating data.
-- Run with:
--   mysql -u root -proot apple_login < setup/migrate_support_role.sql
-- ============================================================

USE `apple_login`;

-- 1. Extend org_members.role ENUM to include 'support'
ALTER TABLE `org_members`
    MODIFY `role`
        ENUM('owner','admin','support','supplier','user')
        NOT NULL DEFAULT 'user';

-- 2. Extend supplier_invitations.role ENUM to include 'support'
ALTER TABLE `supplier_invitations`
    MODIFY `role`
        ENUM('owner','admin','support','supplier')
        NOT NULL DEFAULT 'supplier';

-- 3. Extend users.role ENUM to include 'support'
--    (users.role is a legacy discriminator; per-org role lives in org_members)
ALTER TABLE `users`
    MODIFY `role`
        ENUM('admin','support','supplier')
        NOT NULL DEFAULT 'supplier';

-- 4. Compatibility VIEW: user_business_units
--    Exposes (user_id, business_unit_id, created_at) for the support role,
--    using org_members as the single source of truth.
CREATE OR REPLACE VIEW `user_business_units` AS
SELECT
    om.user_id,
    om.org_id  AS business_unit_id,
    om.created_at
FROM `org_members` om
WHERE om.role = 'support'
  AND om.is_active = 1;

-- Done
SELECT 'Support role migration complete.' AS status;
