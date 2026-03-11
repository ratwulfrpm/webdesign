-- seed_test_users.sql — Usuarios de prueba para testing multi-org
-- Contraseña para todos: Demo123!
-- Hash: $2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi

USE apple_login;

-- ── 1. admin_jbusiness: admin exclusivo de jbusiness ─────────
INSERT INTO users (username, email, password_hash, is_active, role, first_login)
VALUES (
    'admin_jbusiness',
    'admin_jb@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1, 'admin', 0
)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO org_members (user_id, org_id, role)
SELECT u.id, o.id, 'admin'
FROM users u, organizations o
WHERE u.username = 'admin_jbusiness' AND o.slug = 'jbusiness'
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ── 2. usuario1: user en AMBAS orgs (activa el org-picker) ───
INSERT INTO users (username, email, password_hash, is_active, role, first_login)
VALUES (
    'usuario1',
    'usuario1@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1, 'user', 0
)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

INSERT INTO org_members (user_id, org_id, role)
SELECT u.id, o.id, 'user'
FROM users u, organizations o
WHERE u.username = 'usuario1' AND o.slug = 'jshop'
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO org_members (user_id, org_id, role)
SELECT u.id, o.id, 'user'
FROM users u, organizations o
WHERE u.username = 'usuario1' AND o.slug = 'jbusiness'
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ── Resultado ─────────────────────────────────────────────────
SELECT u.username, u.email, o.slug AS org, om.role
FROM users u
JOIN org_members om ON u.id = om.user_id
JOIN organizations o ON o.id = om.org_id
ORDER BY u.username, o.slug;
