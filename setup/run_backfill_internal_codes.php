<?php
/**
 * setup/run_backfill_internal_codes.php
 *
 * One-time backfill script: assigns an internal_product_code to every
 * supplier_product row that does not yet have one.
 *
 * Run once from CLI:
 *   php setup/run_backfill_internal_codes.php
 *
 * Safe to re-run: skips products that already have a code.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/product_code.php';

$pdo = getDB();

// Fetch products that still lack a code
$stmt = $pdo->query(
    'SELECT id FROM supplier_products
      WHERE internal_product_code IS NULL
      ORDER BY id ASC'
);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($ids)) {
    echo "All products already have an internal_product_code. Nothing to do.\n";
    exit(0);
}

echo count($ids) . " product(s) need a code. Generating...\n";

$upd = $pdo->prepare(
    'UPDATE supplier_products
        SET internal_product_code = ?
      WHERE id = ? AND internal_product_code IS NULL'
);

$generated = 0;
$skipped   = 0;

foreach ($ids as $productId) {
    $code = generateInternalProductCode($pdo);
    $upd->execute([$code, $productId]);
    if ($upd->rowCount() > 0) {
        echo "  Product #" . $productId . " → " . $code . "\n";
        $generated++;
    } else {
        echo "  Product #" . $productId . " — skipped (already assigned by concurrent run?)\n";
        $skipped++;
    }
}

echo "\nDone. Generated: $generated | Skipped: $skipped\n";
exit(0);
