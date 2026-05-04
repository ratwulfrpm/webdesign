<?php
/**
 * api/v1/resources/search.php — Product search resource handler.
 *
 * Routes:
 *   GET /api/v1/search/products    paginated full-text + filter product search
 *
 * Query parameters:
 *   q           — general keyword (FULLTEXT BOOLEAN MODE + LIKE fallback + keywords table)
 *   supplier    — supplier username or company_name (LIKE)
 *   name        — product_name (LIKE)
 *   description — technical_description (LIKE)
 *   page        — page number (default 1)
 *
 * Response: {success, total, page, pages, per_page, items:[...]}
 *
 * RBAC: admin/owner only (FOB/CIF prices included in response).
 *
 * Security:
 *   - All filter values parameterized — no SQL concatenation of user input.
 *   - FULLTEXT search terms sanitized: only word characters allowed.
 *   - supplier_product_code (internal) never exposed.
 *   - Rate limiting: 60 requests per minute per user (soft enforcement via audit log).
 */

define('SEARCH_PER_PAGE', 25);

function handleSearch(string $method, string $sub): void
{
    if ($method !== 'GET') {
        jsonError('Method Not Allowed', 405);
    }

    $auth = requireApiAuth(['admin', 'owner']);

    match ($sub) {
        'products' => _searchProducts($auth, getDB()),
        ''         => jsonError('Specify search type: /search/products', 400),
        default    => jsonError('Unknown search type', 404),
    };
}

// ── SEARCH PRODUCTS ───────────────────────────────────────────

function _searchProducts(array $auth, PDO $pdo): void
{
    // Input sanitization — all values capped and stripped
    $trim100    = fn(string $v): string => mb_substr(trim($v), 0, 100);
    $q          = $trim100($_GET['q']           ?? '');
    $supplierF  = $trim100($_GET['supplier']    ?? '');
    $nameF      = $trim100($_GET['name']        ?? '');
    $descF      = $trim100($_GET['description'] ?? '');
    $page       = max(1, (int) ($_GET['page']   ?? 1));
    $offset     = ($page - 1) * SEARCH_PER_PAGE;

    // TENANT ISOLATION: always scope to session org
    $where      = ['p.active = 1', 'p.org_id = ?'];
    $bindParams = [$auth['org_id']];

    // ── General keyword: FULLTEXT + keywords table + LIKE fallback ────
    if ($q !== '') {
        $like  = likeWrap($q);
        // Build FULLTEXT BOOLEAN query with cleaned words (≥ 3 chars)
        $words = array_filter(
            preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $q)),
            fn($w) => mb_strlen($w) >= 3
        );

        if (!empty($words)) {
            $ftQuery = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
            $where[] = '(MATCH(p.product_name, p.technical_description)
                             AGAINST(? IN BOOLEAN MODE)
                         OR EXISTS(SELECT 1 FROM product_keywords _kw
                                    WHERE _kw.product_id = p.id AND _kw.keyword LIKE ?)
                         OR p.product_name LIKE ?)';
            $bindParams[] = $ftQuery;
            $bindParams[] = $like;
            $bindParams[] = $like;
        } else {
            // Short words: pure LIKE fallback
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
        $lk           = likeWrap($supplierF);
        $where[]      = '(u.username LIKE ? OR u.company_name LIKE ?)';
        $bindParams[] = $lk;
        $bindParams[] = $lk;
    }

    if ($nameF !== '') {
        $bindParams[] = likeWrap($nameF);
        $where[]      = 'p.product_name LIKE ?';
    }

    if ($descF !== '') {
        $bindParams[] = likeWrap($descF);
        $where[]      = 'p.technical_description LIKE ?';
    }

    $wSql = implode(' AND ', $where);

    // ── COUNT ─────────────────────────────────────────────────
    $cntSt = $pdo->prepare(
        "SELECT COUNT(DISTINCT p.id)
           FROM supplier_products p
           JOIN users u ON u.id = p.supplier_id
          WHERE {$wSql}"
    );
    $cntSt->execute($bindParams);
    $total = (int) $cntSt->fetchColumn();

    // ── MAIN SELECT — single query, no N+1 ────────────────────
    $per  = SEARCH_PER_PAGE;
    $st   = $pdo->prepare(
        "SELECT
             p.id,
             p.product_name,
             p.internal_product_code,
             p.price_fob,
             p.price_cif,
             u.username     AS supplier_username,
             u.company_name AS supplier_company,
             spi.file_path  AS front_img_path,
             (SELECT GROUP_CONCAT(DISTINCT pk.keyword ORDER BY pk.keyword SEPARATOR ', ')
                FROM product_keywords pk
               WHERE pk.product_id = p.id)  AS keywords_csv
         FROM supplier_products p
         JOIN users u     ON u.id = p.supplier_id
         LEFT JOIN supplier_product_images spi
                ON spi.product_id = p.id AND spi.image_slot = 'front'
         WHERE {$wSql}
         GROUP BY p.id, p.product_name, p.internal_product_code,
                  p.price_fob, p.price_cif,
                  u.username, u.company_name, spi.file_path
         ORDER BY p.product_name ASC
         LIMIT {$per} OFFSET {$offset}"
    );
    $st->execute($bindParams);
    $rows = $st->fetchAll();

    // ── Build items — FOB/CIF included (admin/owner only endpoint) ──
    $items = array_map(fn($r) => [
        'id'                    => (int)    $r['id'],
        'product_name'          => (string) $r['product_name'],
        'internal_product_code' => (string) ($r['internal_product_code'] ?? ''),
        'price_fob'             => $r['price_fob'] !== null ? (float) $r['price_fob'] : null,
        'price_cif'             => $r['price_cif'] !== null ? (float) $r['price_cif'] : null,
        'supplier_username'     => (string) $r['supplier_username'],
        'supplier_company'      => (string) ($r['supplier_company'] ?? ''),
        'front_img_path'        => $r['front_img_path'] ? (string) $r['front_img_path'] : null,
        'keywords_csv'          => $r['keywords_csv'] ? (string) $r['keywords_csv'] : null,
    ], $rows);

    jsonOk([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'pages'    => (int) ceil($total / max(1, SEARCH_PER_PAGE)),
        'per_page' => SEARCH_PER_PAGE,
    ]);
}
