<?php
/**
 * run_migration3.php — RBAC: 4-role system
 *
 * Changes:
 *  1. ALTER users.role ENUM to include 'owner', 'admin', 'supplier', 'user'
 *  2. INSERT/UPDATE seed users: owner/owner, admin/admin, proveedor/proveedor, usuario/usuario
 *
 * MySQL 5.7 compatible.
 * Run ONCE via browser: http://localhost/jshop/setup/run_migration3.php
 * Delete this file after running.
 */

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$log = [];
$ok  = true;

function step(PDO $pdo, array &$log, bool &$ok, string $label, string $sql): void
{
    try {
        $pdo->exec($sql);
        $log[] = "  [OK]  $label";
    } catch (PDOException $e) {
        $log[] = "  [ERR] $label — " . $e->getMessage();
        $ok    = false;
    }
}

echo "=== Migration 3 — RBAC 4-role system ===\n\n";

// ── 1. Expand ENUM ────────────────────────────────────────────
$log[] = '── Alter users.role ENUM ──';
step($pdo, $log, $ok, 'Expand role ENUM to owner/admin/supplier/user',
    "ALTER TABLE `apple_login`.`users`
     MODIFY COLUMN `role` ENUM('owner','admin','supplier','user')
         NOT NULL DEFAULT 'supplier'"
);

// ── 2. Seed / upsert users ────────────────────────────────────
$log[] = '';
$log[] = '── Seed users ──';

$seeds = [
    // username, email, plain_password, role, first_login
    ['owner',    'owner@local',    'owner',    'owner',    0],
    ['admin',    'admin@local',    'admin',    'admin',    0],
    ['proveedor','proveedor@local','proveedor','supplier', 1],
    ['usuario',  'usuario@local',  'usuario',  'user',     0],
];

foreach ($seeds as [$uname, $email, $plain, $role, $fl]) {
    $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO `apple_login`.`users`
                (username, email, password_hash, is_active, role, first_login, failed_attempts)
             VALUES (?, ?, ?, 1, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                password_hash = VALUES(password_hash),
                role          = VALUES(role),
                first_login   = VALUES(first_login),
                is_active     = 1,
                failed_attempts = 0,
                locked_until  = NULL"
        );
        $stmt->execute([$uname, $email, $hash, $role, $fl]);
        $log[] = "  [OK]  Upserted user '$uname' (role=$role)";
    } catch (PDOException $e) {
        $log[] = "  [ERR] Upsert '$uname': " . $e->getMessage();
        $ok    = false;
    }
}

// ── Done ──────────────────────────────────────────────────────
echo implode("\n", $log) . "\n\n";

if ($ok) {
    echo "=== Migration 3 completed successfully ===\n";
    echo "NEXT STEP: Delete this file.\n";
    echo "\nCredentials:\n";
    echo "  owner    / owner    (role: owner)\n";
    echo "  admin    / admin    (role: admin)\n";
    echo "  proveedor/ proveedor(role: supplier)\n";
    echo "  usuario  / usuario  (role: user)\n";
} else {
    echo "=== Migration 3 completed WITH ERRORS ===\n";
}
