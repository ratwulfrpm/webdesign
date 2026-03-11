<?php
/**
 * run_migration2.php — New schema for "Perfil del proveedor" (profiling feature)
 *
 * MySQL 5.7 compatible: does NOT use ADD COLUMN IF NOT EXISTS.
 * Checks INFORMATION_SCHEMA before each ALTER.
 *
 * Run ONCE via browser: http://localhost/login/setup/run_migration2.php
 * Delete this file from the server after running.
 */

// ── Bootstrap ────────────────────────────────────────────────
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$db  = 'apple_login';
$log = [];
$ok  = true;

function runStep(PDO $pdo, array &$log, bool &$ok, string $label, string $sql): void
{
    try {
        $pdo->exec($sql);
        $log[] = "  [OK]  $label";
    } catch (PDOException $e) {
        $log[] = "  [ERR] $label — " . $e->getMessage();
        $ok = false;
    }
}

function columnExists(PDO $pdo, string $db, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$db, $table, $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $db, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$db, $table]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "=== Migration 2 — Perfil del Proveedor ===\n\n";

// ── 1. Add new columns to users ──────────────────────────────
$log[] = '── users table — new columns ──';

$newCols = [
    'legal_rep_name'         => "VARCHAR(200) NULL DEFAULT NULL AFTER company_name",
    'tax_id'                 => "VARCHAR(50)  NULL DEFAULT NULL AFTER legal_rep_name",
    'legal_rep_id'           => "VARCHAR(50)  NULL DEFAULT NULL AFTER tax_id",
    'company_phone_code'     => "VARCHAR(10)  NULL DEFAULT NULL AFTER legal_rep_id",
    'company_phone_number'   => "VARCHAR(30)  NULL DEFAULT NULL AFTER company_phone_code",
    'legal_rep_phone_code'   => "VARCHAR(10)  NULL DEFAULT NULL AFTER company_phone_number",
    'legal_rep_phone_number' => "VARCHAR(30)  NULL DEFAULT NULL AFTER legal_rep_phone_code",
    'addr_street'            => "VARCHAR(300) NULL DEFAULT NULL AFTER legal_rep_phone_number",
    'addr_city'              => "VARCHAR(100) NULL DEFAULT NULL AFTER addr_street",
    'addr_state'             => "VARCHAR(100) NULL DEFAULT NULL AFTER addr_city",
    'addr_zip'               => "VARCHAR(20)  NULL DEFAULT NULL AFTER addr_state",
    'addr_country_id'        => "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER addr_zip",
    'factory_street'         => "VARCHAR(300) NULL DEFAULT NULL AFTER addr_country_id",
    'factory_city'           => "VARCHAR(100) NULL DEFAULT NULL AFTER factory_street",
    'factory_state'          => "VARCHAR(100) NULL DEFAULT NULL AFTER factory_city",
    'factory_zip'            => "VARCHAR(20)  NULL DEFAULT NULL AFTER factory_state",
    'factory_country_id'     => "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER factory_zip",
];

foreach ($newCols as $col => $definition) {
    if (!columnExists($pdo, $db, 'users', $col)) {
        runStep($pdo, $log, $ok, "ADD COLUMN users.$col",
            "ALTER TABLE `$db`.`users` ADD COLUMN `$col` $definition");
    } else {
        $log[] = "  [--]  users.$col already exists — skipped";
    }
}

// ── 2. Create countries table ────────────────────────────────
$log[] = '';
$log[] = '── countries table ──';

if (!tableExists($pdo, $db, 'countries')) {
    runStep($pdo, $log, $ok, 'CREATE TABLE countries',
        "CREATE TABLE `$db`.`countries` (
            `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code`       CHAR(2)           NOT NULL COMMENT 'ISO 3166-1 alpha-2',
            `phone_code` VARCHAR(8)        NOT NULL DEFAULT '+1',
            `name_es`    VARCHAR(100)      NOT NULL,
            `name_en`    VARCHAR(100)      NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Seed countries
    $countrySeed = [
        // code, phone_code, name_es, name_en
        ['AR', '+54',  'Argentina',             'Argentina'],
        ['BO', '+591', 'Bolivia',                'Bolivia'],
        ['BR', '+55',  'Brasil',                 'Brazil'],
        ['CA', '+1',   'Canadá',                 'Canada'],
        ['CL', '+56',  'Chile',                  'Chile'],
        ['CN', '+86',  'China',                  'China'],
        ['CO', '+57',  'Colombia',               'Colombia'],
        ['CR', '+506', 'Costa Rica',             'Costa Rica'],
        ['CU', '+53',  'Cuba',                   'Cuba'],
        ['DE', '+49',  'Alemania',               'Germany'],
        ['DO', '+1',   'República Dominicana',   'Dominican Republic'],
        ['EC', '+593', 'Ecuador',                'Ecuador'],
        ['ES', '+34',  'España',                 'Spain'],
        ['FR', '+33',  'Francia',                'France'],
        ['GB', '+44',  'Reino Unido',            'United Kingdom'],
        ['GT', '+502', 'Guatemala',              'Guatemala'],
        ['HN', '+504', 'Honduras',               'Honduras'],
        ['IN', '+91',  'India',                  'India'],
        ['IT', '+39',  'Italia',                 'Italy'],
        ['JP', '+81',  'Japón',                  'Japan'],
        ['KR', '+82',  'Corea del Sur',          'South Korea'],
        ['MX', '+52',  'México',                 'Mexico'],
        ['NI', '+505', 'Nicaragua',              'Nicaragua'],
        ['PA', '+507', 'Panamá',                 'Panama'],
        ['PE', '+51',  'Perú',                   'Peru'],
        ['PR', '+1',   'Puerto Rico',            'Puerto Rico'],
        ['PT', '+351', 'Portugal',               'Portugal'],
        ['PY', '+595', 'Paraguay',               'Paraguay'],
        ['SV', '+503', 'El Salvador',            'El Salvador'],
        ['TW', '+886', 'Taiwán',                 'Taiwan'],
        ['US', '+1',   'Estados Unidos',         'United States'],
        ['UY', '+598', 'Uruguay',                'Uruguay'],
        ['VE', '+58',  'Venezuela',              'Venezuela'],
        ['VN', '+84',  'Vietnam',                'Vietnam'],
        ['ZA', '+27',  'Sudáfrica',              'South Africa'],
    ];

    $ins = $pdo->prepare(
        "INSERT INTO `$db`.`countries` (code, phone_code, name_es, name_en) VALUES (?,?,?,?)"
    );
    foreach ($countrySeed as $row) {
        try {
            $ins->execute($row);
        } catch (PDOException $e) {
            $log[] = "  [ERR] Insert country {$row[0]}: " . $e->getMessage();
            $ok = false;
        }
    }
    $log[] = '  [OK]  Seeded ' . count($countrySeed) . ' countries';
} else {
    $log[] = '  [--]  countries table already exists — skipped';
}

// ── 3. Create supplier_contacts table ───────────────────────
$log[] = '';
$log[] = '── supplier_contacts table ──';

if (!tableExists($pdo, $db, 'supplier_contacts')) {
    runStep($pdo, $log, $ok, 'CREATE TABLE supplier_contacts',
        "CREATE TABLE `$db`.`supplier_contacts` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `supplier_id`  INT UNSIGNED  NOT NULL,
            `name`         VARCHAR(200)  NOT NULL,
            `role`         VARCHAR(100)  NULL DEFAULT NULL,
            `email`        VARCHAR(254)  NULL DEFAULT NULL,
            `phone_code`   VARCHAR(8)    NULL DEFAULT NULL,
            `phone_number` VARCHAR(30)   NULL DEFAULT NULL,
            `is_primary`   TINYINT(1)    NOT NULL DEFAULT 0,
            `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_supplier` (`supplier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    $log[] = '  [--]  supplier_contacts table already exists — skipped';
}

// ── 4. Add FKs if not present (best-effort, ignore if fail) ─
$log[] = '';
$log[] = '── foreign keys (best-effort) ──';

try {
    $pdo->exec(
        "ALTER TABLE `$db`.`users`
            ADD CONSTRAINT `fk_users_addr_country`
                FOREIGN KEY (`addr_country_id`) REFERENCES `$db`.`countries`(`id`)
                ON DELETE SET NULL"
    );
    $log[] = '  [OK]  FK users.addr_country_id → countries.id';
} catch (PDOException $e) {
    $log[] = '  [--]  FK addr_country_id already exists or skipped: ' . $e->getMessage();
}

try {
    $pdo->exec(
        "ALTER TABLE `$db`.`users`
            ADD CONSTRAINT `fk_users_factory_country`
                FOREIGN KEY (`factory_country_id`) REFERENCES `$db`.`countries`(`id`)
                ON DELETE SET NULL"
    );
    $log[] = '  [OK]  FK users.factory_country_id → countries.id';
} catch (PDOException $e) {
    $log[] = '  [--]  FK factory_country_id already exists or skipped: ' . $e->getMessage();
}

try {
    $pdo->exec(
        "ALTER TABLE `$db`.`supplier_contacts`
            ADD CONSTRAINT `fk_contacts_supplier`
                FOREIGN KEY (`supplier_id`) REFERENCES `$db`.`users`(`id`)
                ON DELETE CASCADE"
    );
    $log[] = '  [OK]  FK supplier_contacts.supplier_id → users.id';
} catch (PDOException $e) {
    $log[] = '  [--]  FK supplier_id already exists or skipped: ' . $e->getMessage();
}

// ── Done ─────────────────────────────────────────────────────
echo implode("\n", $log) . "\n\n";

if ($ok) {
    echo "=== Migration completed successfully ===\n";
    echo "NEXT STEP: Delete this file from the server.\n";
} else {
    echo "=== Migration completed WITH ERRORS — review output above ===\n";
}
