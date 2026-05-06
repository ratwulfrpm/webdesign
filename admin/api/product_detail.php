<?php
/**
 * /login/admin/api/product_detail.php
 * Returns full product details for a single product — admin/owner only.
 *
 * GET param: id (int, required)
 *
 * Returns JSON: {success:true, data:{product:{...}}}
 *
 * Security:
 *  - Session auth (admin/owner) via requireApiAuth().
 *  - Accepts only integer product ID.
 *  - FOB/CIF prices included — admin-only.
 *  - supplier_product_code intentionally omitted from response.
 *  - Responses use standard envelope: {success, data} / {success, error:{code,message}}.
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/org_scope.php';
require_once __DIR__ . '/../../includes/storage.php';
require_once __DIR__ . '/../../api/v1/_helpers.php';

// ── RBAC ─────────────────────────────────────────────────────
$auth = requireApiAuth(['admin', 'owner']);

// ── Validate input ───────────────────────────────────────────
$productId = (int) ($_GET['id'] ?? 0);
if ($productId <= 0) {
    jsonError('Invalid product ID', 400);
}

$pdo = getDB();
$allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);

if (empty($allowedOrgIds)) {
    jsonError('Product not found', 404);
}

$orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));

// ── Load product ─────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT p.id,
            p.product_name,
            p.internal_product_code,
            p.technical_description,
            p.price_fob,
            p.price_cif,
            p.active,
            u.username     AS supplier_username,
            u.company_name AS supplier_company,
            o.name         AS org_name
       FROM supplier_products p
       JOIN users u ON u.id = p.supplier_id
  LEFT JOIN organizations o ON o.id = p.org_id
      WHERE p.id = ? AND p.active = 1 AND p.org_id IN (' . $orgPlaceholders . ')
      LIMIT 1'
);
$stmt->execute(array_merge([$productId], $allowedOrgIds));
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    jsonError('Product not found', 404);
}

// ── Load images ──────────────────────────────────────────────
$imgStmt = $pdo->prepare(
    "SELECT image_slot, file_path
       FROM supplier_product_images
      WHERE product_id = ?
      ORDER BY FIELD(image_slot, 'front', 'back', 'left', 'right', 'aerial', 'bottom')"
);
$imgStmt->execute([$productId]);
$images = [];
foreach ($imgStmt->fetchAll(PDO::FETCH_ASSOC) as $img) {
    $images[$img['image_slot']] = Storage::imageUrl((string) $img['file_path']);
}

// ── Load keywords ────────────────────────────────────────────
$kwStmt = $pdo->prepare(
    'SELECT keyword FROM product_keywords WHERE product_id = ? ORDER BY keyword ASC'
);
$kwStmt->execute([$productId]);
$keywords = $kwStmt->fetchAll(PDO::FETCH_COLUMN);

jsonOk([
    'product' => [
        'id'                    => (int)    $product['id'],
        'product_name'          => (string) $product['product_name'],
        'internal_product_code' => (string) ($product['internal_product_code'] ?? ''),
        'technical_description' => (string) ($product['technical_description'] ?? ''),
        'price_fob'             => $product['price_fob'] !== null ? (float) $product['price_fob'] : null,
        'price_cif'             => $product['price_cif'] !== null ? (float) $product['price_cif'] : null,
        'supplier_username'     => (string) $product['supplier_username'],
        'supplier_company'      => (string) ($product['supplier_company'] ?? ''),
        'org_name'              => $product['org_name'] ? (string) $product['org_name'] : null,
        'active'                => (bool)   $product['active'],
        'images'                => $images,
        'keywords'              => array_values($keywords),
    ],
]);
