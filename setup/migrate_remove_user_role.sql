-- ============================================================
-- migrate_remove_user_role.sql
-- Purpose:
--   1) Backup legacy end-customer data (role='user')
--   2) Revoke pending invitations with role='user'
--   3) Remove org memberships and user records for role='user'
--
-- Notes:
--   - This script is idempotent and safe to run multiple times.
--   - It does NOT alter ENUM schemas; app logic now blocks role='user'.
--   - Rollback script: setup/rollback_remove_user_role.sql
-- ============================================================

USE `apple_login`;

START TRANSACTION;

-- 1) Backup tables (same schema as source tables)
CREATE TABLE IF NOT EXISTS `backup_user_role_users` LIKE `users`;
CREATE TABLE IF NOT EXISTS `backup_user_role_org_members` LIKE `org_members`;
CREATE TABLE IF NOT EXISTS `backup_user_role_invitations` LIKE `supplier_invitations`;

-- 2) Backup current role='user' rows (INSERT IGNORE keeps idempotency)
INSERT IGNORE INTO `backup_user_role_users`
SELECT * FROM `users`
WHERE `role` = 'user';

INSERT IGNORE INTO `backup_user_role_org_members`
SELECT om.*
FROM `org_members` om
JOIN `users` u ON u.`id` = om.`user_id`
WHERE u.`role` = 'user' OR om.`role` = 'user';

INSERT IGNORE INTO `backup_user_role_invitations`
SELECT *
FROM `supplier_invitations`
WHERE `role` = 'user';

-- 3) Revoke pending legacy invitations
UPDATE `supplier_invitations`
   SET `status` = 'revoked',
       `revoked_at` = NOW()
 WHERE `role` = 'user'
   AND `status` = 'pending';

-- 4) Delete legacy memberships and users
DELETE om
FROM `org_members` om
JOIN `users` u ON u.`id` = om.`user_id`
WHERE u.`role` = 'user' OR om.`role` = 'user';

DELETE FROM `users`
WHERE `role` = 'user';

COMMIT;

-- 5) Verification snapshot
SELECT `role`, COUNT(*) AS total
FROM `users`
GROUP BY `role`
ORDER BY `role`;

SELECT `role`, COUNT(*) AS total
FROM `org_members`
GROUP BY `role`
ORDER BY `role`;

SELECT `status`, COUNT(*) AS total
FROM `supplier_invitations`
WHERE `role` = 'user'
GROUP BY `status`
ORDER BY `status`;
