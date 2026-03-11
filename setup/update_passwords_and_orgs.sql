-- update_passwords_and_orgs.sql
-- 1. Cada usuario tiene como clave su mismo nombre de usuario
-- 2. Elimina admin_jbusiness
-- 3. Agrega al usuario "admin" a jbusiness como admin

USE apple_login;

-- ── 1. Actualizar passwords ───────────────────────────────────
UPDATE users SET password_hash = '$2y$12$lvL/KJLQowrdj0P65A3afu0MPJajuQ49twyxNiWDUFW7G3mhYCOEi' WHERE username = 'admin';
UPDATE users SET password_hash = '$2y$12$Y4crTlQh6jkSA1BbmE1vQeWs7R0YqXEDNHSjUPtkiarwywUGPHFzu' WHERE username = 'demo';
UPDATE users SET password_hash = '$2y$12$ytqgZQzt6ZM7G4sQhDWWvOgq5JI3tF6C.HaEsaDLDh3BFCnnCLv/O' WHERE username = 'jbiz_user';
UPDATE users SET password_hash = '$2y$12$QG83B9UX6Rd6zfskfRuFZOIDPssrE.eOK4F0brtbtSeSRcQ1IxP3O' WHERE username = 'multiorg';
UPDATE users SET password_hash = '$2y$12$Ih1/7OdM7OX//pK59lu.ceaVeTOJEh6YWrbjM9o6PxC/uVxMwt1sW' WHERE username = 'owner';
UPDATE users SET password_hash = '$2y$12$ibQ9BIH9CP.Q82vL9ly4werA/hvOX0ZfjJHQOSNOFQ/7FCLf11nbK' WHERE username = 'proveedor';
UPDATE users SET password_hash = '$2y$12$re9qKO/XYqHD9R4/e.tCn./yRYRh46UjUoqgRlceaoaxtnO1wSBzS' WHERE username = 'usuario';
UPDATE users SET password_hash = '$2y$12$26VCZyCO3v.16LMNsb/7weXkRKOJ6yroLseXApTrEzXdvAbdniEcK' WHERE username = 'usuario1';

-- ── 2. Eliminar admin_jbusiness ───────────────────────────────
-- org_members tiene ON DELETE CASCADE, se borra solo
DELETE FROM users WHERE username = 'admin_jbusiness';

-- ── 3. Agregar "admin" a jbusiness como admin ─────────────────
INSERT INTO org_members (user_id, org_id, role)
SELECT u.id, o.id, 'admin'
FROM users u, organizations o
WHERE u.username = 'admin' AND o.slug = 'jbusiness'
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ── Resultado ─────────────────────────────────────────────────
SELECT u.username, o.slug AS org, om.role
FROM users u
JOIN org_members om ON u.id = om.user_id
JOIN organizations o ON o.id = om.org_id
ORDER BY u.username, o.slug;
