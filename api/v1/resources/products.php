<?php
/**
 * api/v1/resources/products.php — Products resource handler.
 *
 * Routes dispatched from index.php:
 *   GET    /api/v1/products                   list products
 *   POST   /api/v1/products                   create product
 *   GET    /api/v1/products/:id               product detail
 *   PATCH  /api/v1/products/:id               update product fields
 *   DELETE /api/v1/products/:id               soft-delete (active = 0)
 *   GET    /api/v1/products/:id/images        list images
 *   DELETE /api/v1/products/:id/images/:slot  remove image
 *   GET    /api/v1/products/:id/keywords      list keywords
 *   POST   /api/v1/products/:id/keywords      add keyword
 *   DELETE /api/v1/products/:id/keywords/:kw  remove keyword
 *
 * RBAC:
 *   admin/owner : full access to all products
 *   supplier    : own products only (IDOR enforced by supplier_id check)
 *
 * Security:
 *   - All parameters are bound via PDO prepared statements.
 *   - Supplier product code never returned on list/detail.
 *   - FOB/CIF prices only returned to admin/owner roles.
 *   - IDOR: supplier role cannot access another supplier's products.
 */

function handleProducts(string $method, ?int $id, string $sub, string $subId): void
{
    $auth = requireAuth(['admin', 'owner', 'supplier']);
    $pdo  = getDB();

    // Sub-resource dispatch when id is present
    if ($id !== null && $sub !== '') {
        match ($sub) {
            'images'   => _productImages($method, $id, $subId, $auth, $pdo),
            'keywords' => _productKeywords($method, $id, $subId, $auth, $pdo),
            default    => jsonError('Sub-resource not found', 404),
        };
        return;
    }

    match (true) {
        $method === 'GET'    && $id === null => _listProducts($auth, $pdo),
        $method === 'POST'   && $id === null => _createProduct($auth, $pdo),
        $method === 'GET'    && $id !== null => _getProduct($id, $auth, $pdo),
        $method === 'PATCH'  && $id !== null => _updateProduct($id, $auth, $pdo),
        $method === 'DELETE' && $id !== null => _deleteProduct($id, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

// ── LIST ─────────────────────────────────────────────────────

function _listProducts(array $auth, PDO $pdo): void
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $offset  = ($page - 1) * $perPage;

    // TENANT ISOLATION: always scope to session org_id
    $where  = ['p.active = 1', 'p.org_id = ?'];
    $params = [$auth['org_id']];

    // Suppliers only see their own products within the org
    if ($auth['role'] === 'supplier') {
        $where[]  = 'p.supplier_id = ?';
        $params[] = $auth['user_id'];
    }

    // Optional filter: ?name=
    if (($name = strField($_GET['name'] ?? '', 100)) !== '') {
        $where[]  = 'p.product_name LIKE ?';
        $params[] = likeWrap($name);
    }

    $wSql = implode(' AND ', $where);

    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM supplier_products p WHERE {$wSql}");
    $cntSt->execute($params);
    $total = (int) $cntSt->fetchColumn();

    $isPrivileged = in_array($auth['role'], ['admin', 'owner'], true);

    $st = $pdo->prepare(
        "SELECT p.id, p.product_name, p.internal_product_code,
                p.active, p.price_fob, p.price_cif, p.created_at,
                u.username     AS supplier_username,
                u.company_name AS supplier_company
           FROM supplier_products p
           JOIN users u ON u.id = p.supplier_id
          WHERE {$wSql}
          ORDER BY p.product_name ASC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    $items = array_map(function ($r) use ($isPrivileged) {
        return [
            'id'                    => (int)    $r['id'],
            'product_name'          => (string) $r['product_name'],
            'internal_product_code' => (string) ($r['internal_product_code'] ?? ''),
            'active'                => (bool)   $r['active'],
            'price_fob'             => $isPrivileged && $r['price_fob'] !== null
                                           ? (float) $r['price_fob'] : null,
            'price_cif'             => $isPrivileged && $r['price_cif'] !== null
                                           ? (float) $r['price_cif'] : null,
            'supplier_username'     => (string) $r['supplier_username'],
            'supplier_company'      => (string) ($r['supplier_company'] ?? ''),
            'created_at'            => $r['created_at'],
        ];
    }, $rows);

    jsonOk([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'pages'    => (int) ceil($total / max(1, $perPage)),
        'per_page' => $perPage,
    ]);
}

// ── DETAIL ───────────────────────────────────────────────────

function _getProduct(int $id, array $auth, PDO $pdo): void
{
    $st = $pdo->prepare(
        "SELECT p.id, p.product_name, p.internal_product_code,
                p.technical_description, p.price_fob, p.price_cif,
                p.active, p.supplier_id, p.org_id, p.created_at, p.updated_at,
                u.username     AS supplier_username,
                u.company_name AS supplier_company
           FROM supplier_products p
           JOIN users u ON u.id = p.supplier_id
          WHERE p.id = ? AND p.org_id = ?"
    );
    $st->execute([$id, $auth['org_id']]);
    $p = $st->fetch();

    if (!$p) {
        jsonError('Product not found', 404);
    }

    // IDOR: supplier can only view own products within the org
    if ($auth['role'] === 'supplier' && (int) $p['supplier_id'] !== $auth['user_id']) {
        jsonError('Forbidden', 403);
    }

    // Images keyed by slot
    $imgSt = $pdo->prepare(
        'SELECT image_slot, file_path FROM supplier_product_images WHERE product_id = ?'
    );
    $imgSt->execute([$id]);
    $images = [];
    foreach ($imgSt->fetchAll() as $img) {
        $images[$img['image_slot']] = $img['file_path'];
    }

    // Keywords
    $kwSt = $pdo->prepare(
        'SELECT keyword FROM product_keywords WHERE product_id = ? ORDER BY keyword'
    );
    $kwSt->execute([$id]);
    $keywords = array_column($kwSt->fetchAll(), 'keyword');

    $isPrivileged = in_array($auth['role'], ['admin', 'owner'], true);

    jsonOk([
        'product' => [
            'id'                    => (int)    $p['id'],
            'product_name'          => (string) $p['product_name'],
            'internal_product_code' => (string) ($p['internal_product_code'] ?? ''),
            'technical_description' => (string) ($p['technical_description'] ?? ''),
            'active'                => (bool)   $p['active'],
            'price_fob'             => $isPrivileged && $p['price_fob'] !== null
                                           ? (float) $p['price_fob'] : null,
            'price_cif'             => $isPrivileged && $p['price_cif'] !== null
                                           ? (float) $p['price_cif'] : null,
            'supplier_username'     => (string) $p['supplier_username'],
            'supplier_company'      => (string) ($p['supplier_company'] ?? ''),
            'images'                => $images,
            'keywords'              => $keywords,
            'created_at'            => $p['created_at'],
            'updated_at'            => $p['updated_at'],
        ],
    ]);
}

// ── CREATE ────────────────────────────────────────────────────

function _createProduct(array $auth, PDO $pdo): void
{
    $body = parseBody();

    $productName  = strField($body['product_name'] ?? '', 300);
    $supplierCode = strField($body['supplier_product_code'] ?? '', 100);
    $description  = strField($body['technical_description'] ?? '', 10000);
    $priceFob     = isset($body['price_fob'])  && $body['price_fob']  !== '' ? (float) $body['price_fob']  : null;
    $priceCif     = isset($body['price_cif'])  && $body['price_cif']  !== '' ? (float) $body['price_cif']  : null;

    if ($productName === '') {
        jsonError('product_name is required');
    }
    if ($supplierCode === '') {
        jsonError('supplier_product_code is required');
    }

    // Determine supplier — org_id ALWAYS comes from session, never from request body
    $orgId      = $auth['org_id'];
    $supplierId = $auth['role'] === 'supplier'
        ? $auth['user_id']
        : (int) ($body['supplier_id'] ?? 0);

    if ($supplierId <= 0) {
        jsonError('supplier_id is required for admin/owner role');
    }

    // Verify supplier exists, is active, AND belongs to the current org
    $st = $pdo->prepare(
        'SELECT u.id FROM users u
          JOIN org_members om ON om.user_id = u.id
         WHERE u.id = ? AND u.is_active = 1
           AND om.org_id = ? AND om.is_active = 1
           AND om.role = "supplier"
         LIMIT 1'
    );
    $st->execute([$supplierId, $orgId]);
    if (!$st->fetch()) {
        jsonError('Supplier not found, inactive, or not a member of this business unit', 422);
    }

    try {
        $ins = $pdo->prepare(
            'INSERT INTO supplier_products
             (supplier_id, org_id, supplier_product_code, product_name,
              technical_description, price_fob, price_cif, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $supplierId, $orgId, $supplierCode, $productName,
            $description !== '' ? $description : null,
            $priceFob, $priceCif, $auth['user_id'],
        ]);
        $newId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('A product with this supplier_product_code already exists for this supplier', 422);
        }
        error_log('_createProduct PDO error: ' . $e->getMessage());
        jsonError('Save failed', 500);
    }

    jsonOk(['id' => $newId], 201);
}

// ── UPDATE ────────────────────────────────────────────────────

function _updateProduct(int $id, array $auth, PDO $pdo): void
{
    // Load for IDOR + tenant isolation check
    $st = $pdo->prepare(
        'SELECT id, supplier_id, org_id FROM supplier_products WHERE id = ? AND org_id = ?'
    );
    $st->execute([$id, $auth['org_id']]);
    $existing = $st->fetch();
    if (!$existing) {
        jsonError('Product not found', 404);
    }
    if ($auth['role'] === 'supplier' && (int) $existing['supplier_id'] !== $auth['user_id']) {
        jsonError('Forbidden', 403);
    }

    $body = parseBody();

    // Fields updatable by all roles
    $updatable = ['product_name', 'technical_description', 'price_fob', 'price_cif'];
    // Admin/owner may also update the supplier code
    if (in_array($auth['role'], ['admin', 'owner'], true)) {
        $updatable[] = 'supplier_product_code';
    }

    $sets   = [];
    $params = [];

    foreach ($updatable as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $val = $body[$field];
        if (in_array($field, ['price_fob', 'price_cif'], true)) {
            $params[] = ($val !== null && $val !== '') ? (float) $val : null;
        } else {
            $maxLen   = match ($field) {
                'product_name', 'supplier_product_code' => 300,
                default => 10000,
            };
            $params[] = strField((string) $val, $maxLen);
        }
        $sets[] = "`{$field}` = ?";
    }

    if (empty($sets)) {
        jsonError('No updatable fields provided in request body');
    }

    $params[] = $id;
    try {
        $pdo->prepare('UPDATE supplier_products SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('Duplicate supplier_product_code for this supplier', 422);
        }
        error_log('_updateProduct PDO error: ' . $e->getMessage());
        jsonError('Update failed', 500);
    }

    jsonOk(['id' => $id]);
}

// ── DELETE (soft) ─────────────────────────────────────────────

function _deleteProduct(int $id, array $auth, PDO $pdo): void
{
    if (!in_array($auth['role'], ['admin', 'owner'], true)) {
        jsonError('Forbidden — only admin/owner can deactivate products', 403);
    }

    // TENANT ISOLATION: verify product belongs to session org before delete
    $st = $pdo->prepare(
        'UPDATE supplier_products SET active = 0 WHERE id = ? AND org_id = ? AND active = 1'
    );
    $st->execute([$id, $auth['org_id']]);

    if ($st->rowCount() === 0) {
        jsonError('Product not found or already inactive', 404);
    }

    jsonOk(['id' => $id, 'active' => false]);
}

// ── IMAGES sub-resource ───────────────────────────────────────

function _productImages(string $method, int $productId, string $slot, array $auth, PDO $pdo): void
{
    // Verify product + IDOR + tenant isolation
    $st = $pdo->prepare(
        'SELECT supplier_id FROM supplier_products WHERE id = ? AND org_id = ? AND active = 1'
    );
    $st->execute([$productId, $auth['org_id']]);
    $p = $st->fetch();
    if (!$p) {
        jsonError('Product not found', 404);
    }
    if ($auth['role'] === 'supplier' && (int) $p['supplier_id'] !== $auth['user_id']) {
        jsonError('Forbidden', 403);
    }

    $validSlots = ['front', 'back', 'left', 'right', 'aerial', 'bottom'];

    if ($method === 'GET') {
        $imgSt = $pdo->prepare(
            'SELECT image_slot, file_path, original_name, file_size, created_at
               FROM supplier_product_images
              WHERE product_id = ?
              ORDER BY FIELD(image_slot, "front","back","left","right","aerial","bottom")'
        );
        $imgSt->execute([$productId]);
        jsonOk(['images' => $imgSt->fetchAll()]);
    }

    if ($method === 'DELETE') {
        if ($slot === '' || !in_array($slot, $validSlots, true)) {
            jsonError('Valid image slot required: ' . implode(', ', $validSlots));
        }
        $del = $pdo->prepare(
            'DELETE FROM supplier_product_images WHERE product_id = ? AND image_slot = ?'
        );
        $del->execute([$productId, $slot]);
        if ($del->rowCount() === 0) {
            jsonError('Image not found for this slot', 404);
        }
        jsonOk(['product_id' => $productId, 'slot' => $slot, 'deleted' => true]);
    }

    // POST — multipart image upload not yet implemented via REST; use web UI
    if ($method === 'POST') {
        jsonError('Image upload via REST not yet implemented. Use the web interface.', 501);
    }

    jsonError('Method Not Allowed', 405);
}

// ── KEYWORDS sub-resource ─────────────────────────────────────

function _productKeywords(string $method, int $productId, string $kw, array $auth, PDO $pdo): void
{
    // Verify product + IDOR + tenant isolation
    $st = $pdo->prepare(
        'SELECT supplier_id FROM supplier_products WHERE id = ? AND org_id = ? AND active = 1'
    );
    $st->execute([$productId, $auth['org_id']]);
    $p = $st->fetch();
    if (!$p) {
        jsonError('Product not found', 404);
    }
    if ($auth['role'] === 'supplier' && (int) $p['supplier_id'] !== $auth['user_id']) {
        jsonError('Forbidden', 403);
    }

    if ($method === 'GET') {
        $kwSt = $pdo->prepare(
            'SELECT keyword FROM product_keywords WHERE product_id = ? ORDER BY keyword'
        );
        $kwSt->execute([$productId]);
        jsonOk(['keywords' => array_column($kwSt->fetchAll(), 'keyword')]);
    }

    if ($method === 'POST') {
        $body    = parseBody();
        $keyword = strField($body['keyword'] ?? '', 100);
        if ($keyword === '') {
            jsonError('keyword field is required');
        }
        try {
            $pdo->prepare(
                'INSERT IGNORE INTO product_keywords (product_id, keyword) VALUES (?, ?)'
            )->execute([$productId, $keyword]);
        } catch (PDOException $e) {
            error_log('_productKeywords insert error: ' . $e->getMessage());
            jsonError('Save failed', 500);
        }
        jsonOk(['product_id' => $productId, 'keyword' => $keyword], 201);
    }

    if ($method === 'DELETE') {
        $keyword = strField(rawurldecode($kw), 100);
        if ($keyword === '') {
            jsonError('Keyword required in URL path');
        }
        $del = $pdo->prepare(
            'DELETE FROM product_keywords WHERE product_id = ? AND keyword = ?'
        );
        $del->execute([$productId, $keyword]);
        if ($del->rowCount() === 0) {
            jsonError('Keyword not found for this product', 404);
        }
        jsonOk(['product_id' => $productId, 'keyword' => $keyword, 'deleted' => true]);
    }

    jsonError('Method Not Allowed', 405);
}
