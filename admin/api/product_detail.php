<?php
/**
 * /login/admin/api/product_detail.php
 * Returns full product details for a single product — admin/owner only.
 *
 * GET param: id (int, required)
 *
 * Returns JSON: {success, product: {..., images: {slot:path}, keywords: [...]}}
 *
 * Security:
 *  - Session auth (admin/owner).
 *  - Accepts only integer product ID.
 *  - FOB/CIF prices included — admin-only.
 *  - supplier_product_code and admin_product_code intentionally omitted from response.
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: application/json; charset=utf-8');

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => false,
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/org_scope.php';

// ── RBAC ────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// ── Validate input ───────────────────────────────────────────
$productId = (int) ($_GET['id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid product ID']);
    exit;
}

$pdo = getDB();
$allowedOrgIds = loadAccessibleOrgIds($pdo, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? ''));

if (empty($allowedOrgIds)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
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
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Product not found']);
    exit;
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
    $images[$img['image_slot']] = (string) $img['file_path'];
}

// ── Load keywords ────────────────────────────────────────────
$kwStmt = $pdo->prepare(
    'SELECT keyword FROM product_keywords WHERE product_id = ? ORDER BY keyword ASC'
);
$kwStmt->execute([$productId]);
$keywords = $kwStmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
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
], JSON_UNESCAPED_UNICODE);
