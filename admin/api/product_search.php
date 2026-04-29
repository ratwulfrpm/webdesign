<?php
/**
 * /login/admin/api/product_search.php
 * Paginated product search — admin/owner only.
 *
 * GET params:
 *   q           — general keyword (FULLTEXT + keywords table + LIKE fallback)
 *   supplier    — supplier username or company_name LIKE
 *   description — technical_description LIKE
 *   name        — product_name LIKE
 *   page        — int (default 1)
 *
 * Returns JSON: {success, total, page, pages, per_page, items:[...]}
 *
 * Security:
 *  - Session auth required (admin/owner role).
 *  - All filters sanitized / parameterized — never concatenated into SQL.
 *  - FOB/CIF prices included — admin-only endpoint.
 *  - No CSRF required: GET read-only, auth enforced by session.
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

// ── Input sanitization ───────────────────────────────────────
$clean      = fn(string $v): string => mb_substr(trim($v), 0, 100);
$q          = $clean($_GET['q']           ?? '');
$supplierF  = $clean($_GET['supplier']    ?? '');
$descF      = $clean($_GET['description'] ?? '');
$nameF      = $clean($_GET['name']        ?? '');
$page       = max(1, (int) ($_GET['page'] ?? 1));

define('PER_PAGE', 25);
$offset = ($page - 1) * PER_PAGE;

$pdo = getDB();

// ── Build WHERE clause ───────────────────────────────────────
$where      = ['p.active = 1'];
$bindParams = [];

if ($q !== '') {
    $like  = '%' . $q . '%';
    $words = array_filter(
        preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $q)),
        fn($w) => mb_strlen($w) >= 3
    );
    if (!empty($words)) {
        // FULLTEXT BOOLEAN MODE — prefix-match each qualifying word
        $ftQuery = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
        $where[] = '(MATCH(p.product_name, p.technical_description) AGAINST(? IN BOOLEAN MODE)
                     OR EXISTS(SELECT 1 FROM product_keywords _kw
                                WHERE _kw.product_id = p.id AND _kw.keyword LIKE ?)
                     OR p.product_name LIKE ?)';
        $bindParams[] = $ftQuery;
        $bindParams[] = $like;
        $bindParams[] = $like;
    } else {
        $where[] = '(p.product_name LIKE ?
                     OR p.technical_description LIKE ?
                     OR EXISTS(SELECT 1 FROM product_keywords _kw
                                WHERE _kw.product_id = p.id AND _kw.keyword LIKE ?))';
        $bindParams[] = $like;
        $bindParams[] = $like;
        $bindParams[] = $like;
    }
}

if ($supplierF !== '') {
    $like = '%' . $supplierF . '%';
    $where[] = '(u.username LIKE ? OR u.company_name LIKE ?)';
    $bindParams[] = $like;
    $bindParams[] = $like;
}

if ($descF !== '') {
    $bindParams[] = '%' . $descF . '%';
    $where[] = 'p.technical_description LIKE ?';
}

if ($nameF !== '') {
    $bindParams[] = '%' . $nameF . '%';
    $where[] = 'p.product_name LIKE ?';
}

$whereSQL = implode(' AND ', $where);

// ── COUNT query ──────────────────────────────────────────────
$cntStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT p.id)
       FROM supplier_products p
       JOIN users u ON u.id = p.supplier_id
      WHERE {$whereSQL}"
);
$cntStmt->execute($bindParams);
$total = (int) $cntStmt->fetchColumn();

// ── Main SELECT — single query, no N+1 ──────────────────────
$sql = "SELECT
            p.id,
            p.product_name,
            p.internal_product_code,
            p.price_fob,
            p.price_cif,
            u.username     AS supplier_username,
            u.company_name AS supplier_company,
            MAX(o.name)    AS org_name,
            spi.file_path  AS front_img_path,
            (SELECT GROUP_CONCAT(DISTINCT pk.keyword ORDER BY pk.keyword SEPARATOR ', ')
               FROM product_keywords pk
              WHERE pk.product_id = p.id) AS keywords_csv
        FROM supplier_products p
        JOIN users u ON u.id = p.supplier_id
        LEFT JOIN org_members om  ON om.user_id = u.id AND om.is_active = 1
        LEFT JOIN organizations o ON o.id = om.org_id
        LEFT JOIN supplier_product_images spi
               ON spi.product_id = p.id AND spi.image_slot = 'front'
        WHERE {$whereSQL}
        GROUP BY p.id, p.product_name, p.internal_product_code,
                 p.price_fob, p.price_cif,
                 u.username, u.company_name, spi.file_path
        ORDER BY p.product_name ASC
        LIMIT " . PER_PAGE . " OFFSET " . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($bindParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Build response ───────────────────────────────────────────
// FOB/CIF included — admin-only endpoint (RBAC verified above).
// supplier_product_code and admin_product_code are intentionally omitted.
$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'                   => (int)    $r['id'],
        'product_name'         => (string) $r['product_name'],
        'internal_product_code'=> (string) ($r['internal_product_code'] ?? ''),
        'price_fob'            => $r['price_fob'] !== null ? (float) $r['price_fob'] : null,
        'price_cif'            => $r['price_cif'] !== null ? (float) $r['price_cif'] : null,
        'supplier_username'    => (string) $r['supplier_username'],
        'supplier_company'     => (string) ($r['supplier_company'] ?? ''),
        'org_name'             => $r['org_name'] ? (string) $r['org_name'] : null,
        'front_img_path'       => $r['front_img_path'] ? (string) $r['front_img_path'] : null,
        'keywords_csv'         => $r['keywords_csv'] ? (string) $r['keywords_csv'] : null,
    ];
}

echo json_encode([
    'success'  => true,
    'total'    => $total,
    'page'     => $page,
    'pages'    => (int) ceil($total / max(1, PER_PAGE)),
    'per_page' => PER_PAGE,
    'items'    => $items,
], JSON_UNESCAPED_UNICODE);
