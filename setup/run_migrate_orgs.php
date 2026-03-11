<?php
/**
 * setup/run_migrate_orgs.php
 * Run once from browser or CLI to apply the multi-org migration.
 * DELETE THIS FILE after running in production.
 *
 * Local URL: http://localhost/login/setup/run_migrate_orgs.php
 */

// Block if accessed from anywhere other than localhost
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../config/db.php';

$pdo     = getDB();
$sqlFile = __DIR__ . '/migrate_orgs.sql';

if (!file_exists($sqlFile)) {
    die('migrate_orgs.sql not found.');
}

$sql = file_get_contents($sqlFile);

// Split on semicolons (skip empty statements)
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== '' && !preg_match('/^--/m', $s) || strlen(trim($s)) > 2
);

$results = [];
$errors  = [];

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || $stmt === '--') continue;
    // Skip pure comment lines
    if (preg_match('/^--.*$/s', $stmt)) continue;

    try {
        $st  = $pdo->query($stmt);
        if ($st && $st->columnCount() > 0) {
            $results[] = ['sql' => substr($stmt, 0, 80), 'rows' => $st->fetchAll()];
        } else {
            $results[] = ['sql' => substr($stmt, 0, 80), 'rows' => []];
        }
    } catch (PDOException $e) {
        $errors[] = ['sql' => substr($stmt, 0, 80), 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Migrate Orgs</title>
    <style>
        body { font-family: monospace; padding: 24px; background: #f5f5f7; }
        h1   { color: #1d1d1f; }
        .ok  { color: #34c759; }
        .err { color: #ff3b30; background: #fff8f8; padding: 8px; border-radius: 6px; }
        table { border-collapse: collapse; margin: 8px 0 20px; }
        th, td { border: 1px solid #ddd; padding: 6px 12px; text-align: left; }
        th { background: #e8e8ed; }
        pre { background: #1d1d1f; color: #f5f5f7; padding: 12px; border-radius: 8px; }
    </style>
</head>
<body>
    <h1>Migration: multi-org RBAC</h1>

    <?php if ($errors): ?>
        <h2 class="err">⚠ Errors (<?= count($errors) ?>)</h2>
        <?php foreach ($errors as $e): ?>
            <div class="err">
                <strong>SQL:</strong> <code><?= htmlspecialchars($e['sql']) ?></code><br>
                <strong>Error:</strong> <?= htmlspecialchars($e['error']) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="ok">✔ Statements executed: <?= count($results) ?></h2>

    <?php foreach ($results as $r): ?>
        <?php if (!empty($r['rows'])): ?>
            <p><strong><?= htmlspecialchars($r['sql']) ?>…</strong></p>
            <table>
                <thead><tr><?php foreach (array_keys($r['rows'][0]) as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($r['rows'] as $row): ?>
                    <tr><?php foreach ($row as $v): ?><td><?= htmlspecialchars((string)$v) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>

    <p><em>Migration complete. <strong>Delete this file</strong> when done.</em></p>
</body>
</html>
