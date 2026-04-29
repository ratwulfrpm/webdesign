<?php
/**
 * api/v1/resources/assignments.php — Quote assignments resource handler.
 *
 * Routes:
 *   GET    /api/v1/assignments              list recent assignments (excl. deleted)
 *   POST   /api/v1/assignments              create multi-product quote
 *   GET    /api/v1/assignments/:id          assignment detail + line items
 *   DELETE /api/v1/assignments/:id          soft-delete
 *   POST   /api/v1/assignments/:id/revoke   revoke active assignment
 *   POST   /api/v1/assignments/:id/clone    clone (regen link with new token)
 *
 * RBAC: admin/owner only.
 *
 * Security:
 *   - Token: bin2hex(random_bytes(32)) — stored as SHA-256 hash only.
 *   - Plain token returned ONCE in create/clone response.
 *   - All product IDs validated server-side against DB.
 *   - FOB/CIF prices never returned in public-facing responses.
 *   - IDOR: all queries filtered by org_id from session.
 *   - SQL injection: all params bound via PDO prepared statements.
 */

function handleAssignments(string $method, ?int $id, string $action): void
{
    $auth = requireAuth(['admin', 'owner']);
    $pdo  = getDB();

    match (true) {
        $method === 'GET'    && $id === null                               => _listAssignments($auth, $pdo),
        $method === 'POST'   && $id === null                               => _createAssignment($auth, $pdo),
        $method === 'GET'    && $id !== null && $action === ''             => _getAssignment($id, $auth, $pdo),
        $method === 'DELETE' && $id !== null && $action === ''             => _deleteAssignment($id, $auth, $pdo),
        $method === 'POST'   && $id !== null && $action === 'revoke'       => _revokeAssignment($id, $auth, $pdo),
        $method === 'POST'   && $id !== null && $action === 'clone'        => _cloneAssignment($id, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

// ── Helpers ───────────────────────────────────────────────────

function _buildQuoteUrl(string $plainToken): string
{
    $scheme  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base    = rtrim(
        dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))),
        '/\\'
    );
    return $scheme . '://' . $host . $base . '/quote.php?t=' . $plainToken;
}

// ── LIST ─────────────────────────────────────────────────────

function _listAssignments(array $auth, PDO $pdo): void
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 30;
    $offset  = ($page - 1) * $perPage;

    // Optional status filter
    $status = strField($_GET['status'] ?? '', 20);
    $where  = ["qa.status != 'deleted'", 'qa.org_id = ?'];
    $params = [$auth['org_id']];

    if (in_array($status, ['active', 'expired', 'revoked'], true)) {
        $where[]  = 'qa.status = ?';
        $params[] = $status;
    }

    $wSql = implode(' AND ', $where);

    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM quote_assignments qa WHERE {$wSql}");
    $cntSt->execute($params);
    $total = (int) $cntSt->fetchColumn();

    $st = $pdo->prepare(
        "SELECT qa.id, qa.assigned_customer_name, qa.company_name, qa.status,
                qa.expires_at, qa.view_count, qa.created_at,
                qa.discount_percentage,
                COUNT(DISTINCT qai.product_id)       AS item_count,
                SUM(qai.final_unit_price)            AS items_total,
                uc.username                          AS created_by_username
           FROM quote_assignments qa
           LEFT JOIN quote_assignment_items qai ON qai.quote_assignment_id = qa.id
           LEFT JOIN users uc ON uc.id = qa.created_by_user_id
          WHERE {$wSql}
          GROUP BY qa.id
          ORDER BY qa.created_at DESC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    $items = array_map(fn($r) => [
        'id'                    => (int)    $r['id'],
        'assigned_customer_name'=> (string) $r['assigned_customer_name'],
        'company_name'          => (string) ($r['company_name'] ?? ''),
        'status'                => (string) $r['status'],
        'item_count'            => (int)    $r['item_count'],
        'items_total'           => $r['items_total'] !== null ? (float) $r['items_total'] : null,
        'discount_percentage'   => $r['discount_percentage'] !== null ? (float) $r['discount_percentage'] : null,
        'view_count'            => (int)    $r['view_count'],
        'expires_at'            => $r['expires_at'],
        'created_by_username'   => (string) ($r['created_by_username'] ?? ''),
        'created_at'            => $r['created_at'],
    ], $rows);

    jsonOk([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'pages'    => (int) ceil($total / max(1, $perPage)),
        'per_page' => $perPage,
    ]);
}

// ── DETAIL ───────────────────────────────────────────────────

function _getAssignment(int $id, array $auth, PDO $pdo): void
{
    $st = $pdo->prepare(
        "SELECT qa.id, qa.org_id, qa.assigned_customer_name, qa.company_name,
                qa.special_conditions, qa.discount_percentage, qa.status,
                qa.valid_from, qa.expires_at, qa.view_count, qa.viewed_at,
                qa.last_viewed_at, qa.created_at, qa.parent_quote_id,
                uc.username AS created_by_username
           FROM quote_assignments qa
           LEFT JOIN users uc ON uc.id = qa.created_by_user_id
          WHERE qa.id = ? AND qa.org_id = ?"
    );
    $st->execute([$id, $auth['org_id']]);
    $qa = $st->fetch();

    if (!$qa) {
        jsonError('Assignment not found', 404);
    }

    // Line items — include internal pricing for admin/owner
    $itemSt = $pdo->prepare(
        "SELECT qai.id, qai.product_id,
                sp.product_name, sp.internal_product_code,
                qai.price_base_type, qai.price_base_amount,
                qai.profit_percentage, qai.final_unit_price
           FROM quote_assignment_items qai
           JOIN supplier_products sp ON sp.id = qai.product_id
          WHERE qai.quote_assignment_id = ?
          ORDER BY qai.id ASC"
    );
    $itemSt->execute([$id]);
    $lineItems = $itemSt->fetchAll();

    $subtotal     = array_sum(array_column($lineItems, 'final_unit_price'));
    $discountPct  = $qa['discount_percentage'] !== null ? (float) $qa['discount_percentage'] : 0.0;
    $discountAmt  = round($subtotal * $discountPct / 100, 2);
    $grandTotal   = round($subtotal - $discountAmt, 2);

    $items = array_map(fn($r) => [
        'id'                    => (int)    $r['id'],
        'product_id'            => (int)    $r['product_id'],
        'product_name'          => (string) $r['product_name'],
        'internal_product_code' => (string) ($r['internal_product_code'] ?? ''),
        'price_base_type'       => (string) $r['price_base_type'],
        'price_base_amount'     => (float)  $r['price_base_amount'],
        'profit_percentage'     => (float)  $r['profit_percentage'],
        'final_unit_price'      => (float)  $r['final_unit_price'],
    ], $lineItems);

    jsonOk([
        'assignment' => [
            'id'                    => (int)    $qa['id'],
            'org_id'                => (int)    $qa['org_id'],
            'assigned_customer_name'=> (string) $qa['assigned_customer_name'],
            'company_name'          => (string) ($qa['company_name'] ?? ''),
            'special_conditions'    => (string) ($qa['special_conditions'] ?? ''),
            'status'                => (string) $qa['status'],
            'discount_percentage'   => $qa['discount_percentage'] !== null ? (float) $qa['discount_percentage'] : null,
            'valid_from'            => $qa['valid_from'],
            'expires_at'            => $qa['expires_at'],
            'view_count'            => (int)    $qa['view_count'],
            'viewed_at'             => $qa['viewed_at'],
            'last_viewed_at'        => $qa['last_viewed_at'],
            'parent_quote_id'       => $qa['parent_quote_id'] ? (int) $qa['parent_quote_id'] : null,
            'created_by_username'   => (string) ($qa['created_by_username'] ?? ''),
            'created_at'            => $qa['created_at'],
            'items'                 => $items,
            'totals' => [
                'subtotal'         => round($subtotal, 2),
                'discount_percent' => $discountPct,
                'discount_amount'  => $discountAmt,
                'grand_total'      => $grandTotal,
            ],
        ],
    ]);
}

// ── CREATE ────────────────────────────────────────────────────

function _createAssignment(array $auth, PDO $pdo): void
{
    $body = parseBody();

    $customerName = strField($body['assigned_customer_name'] ?? '', 200);
    $companyName  = strField($body['company_name']           ?? '', 200);
    $conditions   = strField($body['special_conditions']     ?? '', 5000);
    $baseType     = strField($body['price_base_type']        ?? '', 3);
    $profitPct    = isset($body['profit_percentage']) ? (float) $body['profit_percentage'] : null;
    $discountPct  = isset($body['discount_percentage']) && $body['discount_percentage'] !== ''
                    ? (float) $body['discount_percentage'] : null;
    $productIds   = array_map('intval', (array) ($body['product_ids'] ?? []));

    // Validation
    if ($customerName === '') {
        jsonError('assigned_customer_name is required');
    }
    if (!in_array($baseType, ['fob', 'cif'], true)) {
        jsonError('price_base_type must be "fob" or "cif"');
    }
    if ($profitPct === null || $profitPct < 0 || $profitPct > 999) {
        jsonError('profit_percentage must be a number between 0 and 999');
    }
    if ($discountPct !== null && ($discountPct < 0 || $discountPct > 100)) {
        jsonError('discount_percentage must be between 0 and 100');
    }
    $productIds = array_values(array_unique(array_filter($productIds, fn($v) => $v > 0)));
    if (empty($productIds)) {
        jsonError('product_ids array is required and must contain at least one valid product ID');
    }

    // Load and validate products (one query, no N+1)
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $pSt = $pdo->prepare(
        "SELECT id, product_name, price_fob, price_cif, active
           FROM supplier_products
          WHERE id IN ({$placeholders})"
    );
    $pSt->execute($productIds);
    $productMap = [];
    foreach ($pSt->fetchAll() as $p) {
        $productMap[(int) $p['id']] = $p;
    }

    // Validate all requested products exist and have required price
    foreach ($productIds as $pid) {
        if (!isset($productMap[$pid])) {
            jsonError("Product ID {$pid} not found");
        }
        $p = $productMap[$pid];
        if (!(bool) $p['active']) {
            jsonError("Product ID {$pid} is inactive");
        }
        if ($baseType === 'fob' && ($p['price_fob'] === null || (float)$p['price_fob'] <= 0)) {
            jsonError("Product ID {$pid} does not have an FOB price");
        }
        if ($baseType === 'cif' && ($p['price_cif'] === null || (float)$p['price_cif'] <= 0)) {
            jsonError("Product ID {$pid} does not have a CIF price");
        }
    }

    // Generate token
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $plainToken);
    $expiresAt  = date('Y-m-d H:i:s', strtotime('+7 days'));

    try {
        $pdo->beginTransaction();

        // Insert master quote record
        $qIns = $pdo->prepare(
            'INSERT INTO quote_assignments
             (org_id, assigned_customer_name, company_name, special_conditions,
              discount_percentage, token_hash, expires_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $qIns->execute([
            $auth['org_id'],
            $customerName,
            $companyName !== '' ? $companyName : null,
            $conditions  !== '' ? $conditions  : null,
            $discountPct,
            $tokenHash,
            $expiresAt,
            $auth['user_id'],
        ]);
        $quoteId = (int) $pdo->lastInsertId();

        // Insert line items
        $iIns = $pdo->prepare(
            'INSERT INTO quote_assignment_items
             (quote_assignment_id, product_id, price_base_type,
              price_base_amount, profit_percentage, final_unit_price)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($productIds as $pid) {
            $p         = $productMap[$pid];
            $baseAmt   = (float) ($baseType === 'fob' ? $p['price_fob'] : $p['price_cif']);
            $finalPrice = round($baseAmt * (1 + $profitPct / 100), 2);
            $iIns->execute([$quoteId, $pid, $baseType, $baseAmt, $profitPct, $finalPrice]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('_createAssignment error: ' . $e->getMessage());
        jsonError('Save failed', 500);
    }

    jsonOk([
        'id'         => $quoteId,
        'token'      => $plainToken,          // returned ONCE
        'quote_url'  => _buildQuoteUrl($plainToken),
        'expires_at' => $expiresAt,
    ], 201);
}

// ── REVOKE ────────────────────────────────────────────────────

function _revokeAssignment(int $id, array $auth, PDO $pdo): void
{
    $st = $pdo->prepare(
        "UPDATE quote_assignments
            SET status = 'revoked',
                revoked_at = NOW(),
                revoked_by_user_id = ?
          WHERE id = ? AND org_id = ? AND status = 'active'"
    );
    $st->execute([$auth['user_id'], $id, $auth['org_id']]);

    if ($st->rowCount() === 0) {
        jsonError('Assignment not found, not in this org, or not active', 404);
    }

    jsonOk(['id' => $id, 'status' => 'revoked']);
}

// ── DELETE (soft) ─────────────────────────────────────────────

function _deleteAssignment(int $id, array $auth, PDO $pdo): void
{
    $st = $pdo->prepare(
        "UPDATE quote_assignments
            SET status = 'deleted',
                deleted_at = NOW(),
                deleted_by_user_id = ?
          WHERE id = ? AND org_id = ? AND status IN ('active','expired','revoked')"
    );
    $st->execute([$auth['user_id'], $id, $auth['org_id']]);

    if ($st->rowCount() === 0) {
        jsonError('Assignment not found, not in this org, or already deleted', 404);
    }

    jsonOk(['id' => $id, 'status' => 'deleted']);
}

// ── CLONE (regenerate link) ───────────────────────────────────

function _cloneAssignment(int $id, array $auth, PDO $pdo): void
{
    // Load parent
    $st = $pdo->prepare(
        "SELECT * FROM quote_assignments WHERE id = ? AND org_id = ? AND status != 'deleted'"
    );
    $st->execute([$id, $auth['org_id']]);
    $parent = $st->fetch();
    if (!$parent) {
        jsonError('Assignment not found or already deleted', 404);
    }

    // Load parent items
    $iSt = $pdo->prepare(
        'SELECT product_id, price_base_type, price_base_amount,
                profit_percentage, final_unit_price
           FROM quote_assignment_items
          WHERE quote_assignment_id = ?'
    );
    $iSt->execute([$id]);
    $items = $iSt->fetchAll();

    if (empty($items)) {
        jsonError('Parent assignment has no items', 422);
    }

    // Allow optional customer name override
    $body         = parseBody();
    $customerName = strField($body['assigned_customer_name'] ?? '', 200);
    if ($customerName === '') {
        $customerName = $parent['assigned_customer_name'];
    }

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $plainToken);
    $expiresAt  = date('Y-m-d H:i:s', strtotime('+7 days'));

    try {
        $pdo->beginTransaction();

        $qIns = $pdo->prepare(
            'INSERT INTO quote_assignments
             (org_id, assigned_customer_name, company_name, special_conditions,
              discount_percentage, token_hash, expires_at, created_by_user_id, parent_quote_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $qIns->execute([
            $parent['org_id'],
            $customerName,
            $parent['company_name'],
            $parent['special_conditions'],
            $parent['discount_percentage'],
            $tokenHash,
            $expiresAt,
            $auth['user_id'],
            $id,    // parent_quote_id
        ]);
        $newId = (int) $pdo->lastInsertId();

        $iIns = $pdo->prepare(
            'INSERT INTO quote_assignment_items
             (quote_assignment_id, product_id, price_base_type,
              price_base_amount, profit_percentage, final_unit_price)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $iIns->execute([
                $newId,
                $item['product_id'],
                $item['price_base_type'],
                $item['price_base_amount'],
                $item['profit_percentage'],
                $item['final_unit_price'],
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('_cloneAssignment error: ' . $e->getMessage());
        jsonError('Save failed', 500);
    }

    jsonOk([
        'id'              => $newId,
        'parent_quote_id' => $id,
        'token'           => $plainToken,
        'quote_url'       => _buildQuoteUrl($plainToken),
        'expires_at'      => $expiresAt,
    ], 201);
}
