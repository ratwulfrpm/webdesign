-- ============================================================
-- rollback_remove_user_role.sql
-- Purpose:
--   Restore data backed up by migrate_remove_user_role.sql
-- ============================================================

USE `apple_login`;

START TRANSACTION;

-- Ensure backup tables exist (no-op if already present)
CREATE TABLE IF NOT EXISTS `backup_user_role_users` LIKE `users`;
CREATE TABLE IF NOT EXISTS `backup_user_role_org_members` LIKE `org_members`;
CREATE TABLE IF NOT EXISTS `backup_user_role_invitations` LIKE `supplier_invitations`;

-- 1) Restore users first (required by FKs)
INSERT IGNORE INTO `users`
SELECT * FROM `backup_user_role_users`;

-- 2) Restore org memberships
INSERT IGNORE INTO `org_members`
SELECT * FROM `backup_user_role_org_members`;

-- 3) Restore invitations rows if missing
INSERT IGNORE INTO `supplier_invitations`
SELECT * FROM `backup_user_role_invitations`;

-- 4) Restore invitation status fields for backed-up role='user' rows
UPDATE `supplier_invitations` si
JOIN `backup_user_role_invitations` b ON b.`id` = si.`id`
   SET si.`status` = b.`status`,
       si.`revoked_at` = b.`revoked_at`,
       si.`updated_at` = NOW()
 WHERE b.`role` = 'user';

COMMIT;

-- Verification snapshot
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
