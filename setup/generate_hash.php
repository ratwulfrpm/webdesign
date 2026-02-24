<?php
/**
 * setup/generate_hash.php
 *
 * Run once in the browser: http://localhost/apple-login/setup/generate_hash.php
 *
 * This script:
 *   1. Generates a fresh bcrypt hash for the demo password
 *   2. Creates the database & table if they don't exist
 *   3. Inserts (or updates) the demo user
 *
 * DELETE or move this file after setup is complete.
 */

// ── Minimal security: only allow from localhost ───────────────
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteIp, ['127.0.0.1', '::1', 'localhost'], true)) {
    http_response_code(403);
    die('Setup script can only be run from localhost.');
}

// ── Config ────────────────────────────────────────────────────
$DB_HOST    = 'localhost';
$DB_PORT    = 3306;          // Change if your MAMP MySQL uses a different port
$DB_ROOT    = 'root';
$DB_PASS    = 'root';
$DB_NAME    = 'apple_login';
$DEMO_USER  = 'demo';
$DEMO_EMAIL = 'demo@local';
$DEMO_PASS  = 'Demo123!';
$BCRYPT_COST = 12;

// ── Generate hash ─────────────────────────────────────────────
$hash = password_hash($DEMO_PASS, PASSWORD_BCRYPT, ['cost' => $BCRYPT_COST]);

// ── Connect (no DB selected yet so we can CREATE DATABASE) ────
try {
    $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_ROOT, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('<b>Connection failed:</b> ' . htmlspecialchars($e->getMessage()) .
        '<br><br>Check that MAMP MySQL is running and credentials are correct in this file.');
}

// ── Create DB ─────────────────────────────────────────────────
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$DB_NAME}`");

// ── Create table ──────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `users` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`      VARCHAR(60)  NOT NULL,
        `email`         VARCHAR(254) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_username` (`username`),
        UNIQUE KEY `uq_email`    (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Upsert demo user ──────────────────────────────────────────
$stmt = $pdo->prepare("
    INSERT INTO `users` (`username`, `email`, `password_hash`)
    VALUES (:username, :email, :hash)
    ON DUPLICATE KEY UPDATE
        `password_hash` = VALUES(`password_hash`),
        `updated_at`    = CURRENT_TIMESTAMP
");
$stmt->execute([
    ':username' => $DEMO_USER,
    ':email'    => $DEMO_EMAIL,
    ':hash'     => $hash,
]);

// ── Confirm ───────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup — apple-login</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
         background:#f5f5f7; display:flex; align-items:center; justify-content:center;
         min-height:100vh; margin:0; padding:24px; }
  .box { background:#fff; border-radius:18px; padding:40px 44px; max-width:480px; width:100%;
         box-shadow:0 4px 40px rgba(0,0,0,.08); }
  h1 { font-size:1.5rem; font-weight:700; margin:0 0 20px; letter-spacing:-.03em; }
  table { border-collapse:collapse; width:100%; font-size:.9rem; margin:16px 0 24px; }
  td { padding:8px 4px; border-bottom:1px solid #f0f0f5; }
  td:first-child { font-weight:600; color:#555; width:40%; }
  code { background:#f0f0f5; padding:2px 7px; border-radius:6px; font-size:.85rem; }
  .ok  { color:#1a7a3a; font-weight:700; }
  .warn { color:#c0392b; margin-top:20px; padding:14px; background:#fff0f0;
          border-radius:10px; font-size:.85rem; line-height:1.6; }
  a.btn { display:inline-block; margin-top:20px; padding:12px 28px; background:#0071e3;
          color:#fff; border-radius:12px; text-decoration:none; font-weight:600; font-size:.95rem; }
  a.btn:hover { background:#0077ed; }
</style>
</head>
<body>
<div class="box">
  <h1>✓ Setup complete</h1>
  <p>The database, table, and demo user have been created successfully.</p>

  <table>
    <tr><td>Database</td><td><code><?= htmlspecialchars($DB_NAME) ?></code></td></tr>
    <tr><td>Demo login</td><td><code><?= htmlspecialchars($DEMO_EMAIL) ?></code></td></tr>
    <tr><td>Demo password</td><td><code><?= htmlspecialchars($DEMO_PASS) ?></code></td></tr>
    <tr><td>Hash algorithm</td><td><code>bcrypt (cost=<?= $BCRYPT_COST ?>)</code></td></tr>
    <tr><td>Generated hash</td><td style="word-break:break-all;font-size:.75rem"><?= htmlspecialchars($hash) ?></td></tr>
    <tr><td>Status</td><td class="ok">Ready</td></tr>
  </table>

  <a class="btn" href="/apple-login/">Go to login &rarr;</a>

  <div class="warn">
    ⚠️ <strong>Delete or rename this file after setup.</strong><br>
    <code>C:\MAMP\htdocs\apple-login\setup\generate_hash.php</code><br>
    Leaving setup scripts publicly accessible is a security risk.
  </div>
</div>
</body>
</html>
