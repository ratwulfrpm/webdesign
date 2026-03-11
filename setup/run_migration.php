<?php
/**
 * setup/run_migration.php
 * Open in browser: http://localhost/jshop/setup/run_migration.php
 * Applies schema changes (MySQL 5.7 compatible) and creates admin user.
 * DELETE this file after running.
 */

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('Only accessible from localhost.');
}

$DB_HOST = 'localhost';
$DB_PORT = 3306;
$DB_NAME = 'apple_login';
$DB_USER = 'root';
$DB_PASS = 'root';

$log = [];

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $log[] = ['ok', 'Conectado a la base de datos.'];
} catch (PDOException $e) {
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

// Get existing columns
$existingCols = $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN);
$log[] = ['ok', 'Columnas actuales: ' . implode(', ', $existingCols)];

// Columns to add (MySQL 5.7 — no IF NOT EXISTS support for columns)
$columnsToAdd = [
    ['is_active',          "TINYINT(1) NOT NULL DEFAULT 1",          'password_hash'],
    ['role',               "ENUM('admin','supplier') NOT NULL DEFAULT 'supplier'", 'is_active'],
    ['failed_attempts',    "TINYINT UNSIGNED NOT NULL DEFAULT 0",     'role'],
    ['locked_until',       "DATETIME NULL DEFAULT NULL",              'failed_attempts'],
    ['first_login',        "TINYINT(1) NOT NULL DEFAULT 1",           'locked_until'],
    ['preferred_language', "VARCHAR(10) NOT NULL DEFAULT 'es'",       'first_login'],
    ['full_name',          "VARCHAR(200) NULL DEFAULT NULL",          'preferred_language'],
    ['company_name',       "VARCHAR(200) NULL DEFAULT NULL",          'full_name'],
    ['phone',              "VARCHAR(30) NULL DEFAULT NULL",           'company_name'],
];

foreach ($columnsToAdd as [$col, $def, $after]) {
    if (!in_array($col, $existingCols, true)) {
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$def} AFTER `{$after}`");
            $log[] = ['ok', "Columna creada: {$col}"];
        } catch (PDOException $e) {
            $log[] = ['err', "Error en {$col}: " . $e->getMessage()];
        }
    } else {
        $log[] = ['ok', "Columna ya existe (omitida): {$col}"];
    }
}

// Create password_requests table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_requests` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `company_name` VARCHAR(200) NOT NULL,
        `email`        VARCHAR(254) NOT NULL,
        `username`     VARCHAR(60)  NULL DEFAULT NULL,
        `notes`        TEXT         NULL DEFAULT NULL,
        `status`       ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
        `requested_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `resolved_at`  DATETIME     NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`),
        KEY `idx_email`  (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = ['ok', 'Tabla password_requests lista.'];
} catch (PDOException $e) {
    $log[] = ['err', 'Error password_requests: ' . $e->getMessage()];
}

// Create / update the admin user (username: admin, password: admin)
$adminHash = password_hash('admin', PASSWORD_BCRYPT, ['cost' => 12]);
try {
    $exists = $pdo->prepare("SELECT id FROM `users` WHERE `username` = 'admin' LIMIT 1");
    $exists->execute();
    if ($exists->fetch()) {
        $pdo->prepare("UPDATE `users` SET `password_hash`=?, `role`='admin', `is_active`=1, `first_login`=0 WHERE `username`='admin'")
            ->execute([$adminHash]);
        $log[] = ['ok', 'Usuario admin actualizado — clave: admin'];
    } else {
        $pdo->prepare("INSERT INTO `users` (`username`,`email`,`password_hash`,`is_active`,`role`,`first_login`) VALUES ('admin','admin@local',?,1,'admin',0)")
            ->execute([$adminHash]);
        $log[] = ['ok', 'Usuario admin creado — clave: admin'];
    }
} catch (PDOException $e) {
    $log[] = ['err', 'Error usuario admin: ' . $e->getMessage()];
}

// Mark demo user as supplier
try {
    $pdo->exec("UPDATE `users` SET `role`='supplier', `is_active`=1 WHERE `username`='demo'");
    $log[] = ['ok', "Usuario 'demo' marcado como supplier."];
} catch (PDOException $e) {
    $log[] = ['err', 'Error actualizando demo: ' . $e->getMessage()];
}

// Final column list
$finalCols = $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN);
$log[] = ['ok', 'Columnas finales: ' . implode(', ', $finalCols)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Migracion completada</title>
<style>
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
       background:#f5f5f7;display:flex;align-items:center;justify-content:center;
       min-height:100vh;margin:0;padding:24px}
  .box{background:#fff;border-radius:18px;padding:36px 44px;max-width:660px;
       width:100%;box-shadow:0 4px 40px rgba(0,0,0,.08)}
  h1{font-size:1.5rem;font-weight:700;margin:0 0 20px;letter-spacing:-.03em}
  ul{list-style:none;padding:0;margin:0 0 24px;font-size:.875rem;line-height:2.1}
  .ok::before{content:'✓  ';color:#1a7a3a;font-weight:700}
  .err{color:#c0392b}.err::before{content:'✗  ';font-weight:700}
  .creds{background:#f0fff4;border:1px solid #a8e6bc;border-radius:10px;
         padding:16px 20px;margin-bottom:20px;font-size:.9rem;line-height:2}
  code{background:#f0f0f5;padding:2px 7px;border-radius:6px;font-size:.85rem}
  a.btn{display:inline-block;padding:12px 28px;background:#0071e3;color:#fff;
        border-radius:12px;text-decoration:none;font-weight:600;font-size:.95rem}
  a.btn:hover{background:#0077ed}
  .warnbox{margin-top:20px;padding:14px;background:#fff0f0;border-radius:10px;
            font-size:.8rem;color:#c0392b;line-height:1.6}
</style>
</head>
<body>
<div class="box">
  <h1>Migracion completada</h1>
  <ul>
    <?php foreach ($log as [$type, $msg]): ?>
    <li class="<?= $type ?>"><?= htmlspecialchars($msg) ?></li>
    <?php endforeach; ?>
  </ul>
  <div class="creds">
    <strong>Credenciales de administrador</strong><br>
    Usuario: <code>admin</code>&nbsp;&nbsp;|&nbsp;&nbsp;Clave: <code>admin</code>
  </div>
  <a class="btn" href="/jshop/index.php">Ir al login &rarr;</a>
  <div class="warnbox">
    Elimine este archivo despues de usarlo:<br>
    <code>jshop/setup/run_migration.php</code>
  </div>
</div>
</body>
</html>
