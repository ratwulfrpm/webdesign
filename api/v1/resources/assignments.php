<?php
/**
 * api/v1/resources/assignments.php — Quote assignments resource handler.
 *
 * Routes:
 *   GET    /api/v1/assignments                    list recent assignments (excl. deleted)
 *   POST   /api/v1/assignments                    create multi-product quote
 *   GET    /api/v1/assignments/:id                assignment detail + line items
 *   DELETE /api/v1/assignments/:id                soft-delete
 *   POST   /api/v1/assignments/:id/revoke         revoke active assignment
 *   POST   /api/v1/assignments/:id/clone          clone (regen link with new token) — legacy
 *   POST   /api/v1/quotes/:id/replicate           replicate quote (preferred alias of clone)
 *
 * RBAC: admin/owner only (support: read-only via UI, not via this API).
 *
 * Security:
 *   - Token: bin2hex(random_bytes(32)) — stored as SHA-256 hash only.
 *   - Plain token returned ONCE in create/clone/replicate response.
 *   - All product IDs validated server-side against DB.
 *   - FOB/CIF prices never returned in public-facing responses.
 *   - IDOR: all queries filtered by org_id from session scope.
 *   - SQL injection: all params bound via PDO prepared statements.
 */

require_once __DIR__ . '/../../../includes/org_scope.php';

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
        $method === 'POST'   && $id !== null && $action === 'replicate'    => _replicateAssignment($id, $auth, $pdo),
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
    $filterOrgId = (int) ($_GET['org_id'] ?? 0);
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);

    if (empty($allowedOrgIds)) {
        jsonOk([
            'items' => [],
            'total' => 0,
            'page' => $page,
            'pages' => 0,
            'per_page' => $perPage,
        ]);
    }

    if ($filterOrgId > 0 && !orgScopeContainsOrgId($allowedOrgIds, $filterOrgId)) {
        jsonError('Business unit not accessible', 403);
    }

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $where  = ["qa.status != 'deleted'", "qa.org_id IN ({$orgPlaceholders})"];
    $params = $allowedOrgIds;

    if ($filterOrgId > 0) {
        $where[] = 'qa.org_id = ?';
        $params[] = $filterOrgId;
    }

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
                 qa.org_id,
                 o.name                              AS org_name,
                COUNT(DISTINCT qai.product_id)       AS item_count,
                SUM(qai.final_unit_price)            AS items_total,
                uc.username                          AS created_by_username
           FROM quote_assignments qa
           LEFT JOIN quote_assignment_items qai ON qai.quote_assignment_id = qa.id
           LEFT JOIN users uc ON uc.id = qa.created_by_user_id
             LEFT JOIN organizations o ON o.id = qa.org_id
          WHERE {$wSql}
            GROUP BY qa.id, qa.org_id, o.name
          ORDER BY qa.created_at DESC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    $items = array_map(fn($r) => [
        'id'                    => (int)    $r['id'],
        'org_id'                => (int)    $r['org_id'],
        'org_name'              => (string) ($r['org_name'] ?? ''),
        'business_unit_name'    => (string) ($r['org_name'] ?? ''),
        'business_unit'         => [
            'id' => (int) $r['org_id'],
            'name' => (string) ($r['org_name'] ?? ''),
        ],
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
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    if (empty($allowedOrgIds)) {
        jsonError('Assignment not found', 404);
    }

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $st = $pdo->prepare(
        "SELECT qa.id, qa.org_id, qa.assigned_customer_name, qa.company_name,
                qa.special_conditions, qa.discount_percentage, qa.status,
                qa.valid_from, qa.expires_at, qa.view_count, qa.viewed_at,
                qa.last_viewed_at, qa.created_at, qa.parent_quote_id,
                uc.username AS created_by_username,
                o.name AS org_name
           FROM quote_assignments qa
           LEFT JOIN users uc ON uc.id = qa.created_by_user_id
           LEFT JOIN organizations o ON o.id = qa.org_id
          WHERE qa.id = ? AND qa.org_id IN ({$orgPlaceholders})"
    );
    $st->execute(array_merge([$id], $allowedOrgIds));
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
            'org_name'              => (string) ($qa['org_name'] ?? ''),
            'business_unit_name'    => (string) ($qa['org_name'] ?? ''),
            'business_unit'         => [
                'id' => (int) $qa['org_id'],
                'name' => (string) ($qa['org_name'] ?? ''),
            ],
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
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    $targetOrgId = intParam($body['org_id'] ?? $auth['org_id'], 'org_id');

    if (!orgScopeContainsOrgId($allowedOrgIds, $targetOrgId)) {
        jsonError('Business unit not accessible', 403);
    }

    // Customer & company info
    $customerName = strField($body['assigned_customer_name'] ?? '', 200);
    $companyName  = strField($body['company_name']           ?? '', 200);
    $conditions   = strField($body['special_conditions']     ?? '', 5000);
    $baseType     = strField($body['price_base_type']        ?? '', 3);
    
    // ── Discount (existing) ──
    $discountPct  = isset($body['discount_percentage']) && $body['discount_percentage'] !== ''
                    ? (float) $body['discount_percentage'] : null;
    
    // ── PROFIT: percentage or fixed amount ──
    $profitCalcType = strtolower(strField($body['profit_calculation_type'] ?? '', 20));
    if ($profitCalcType === '' || !in_array($profitCalcType, ['percentage', 'fixed_amount'], true)) {
        $profitCalcType = 'percentage'; // Default to percentage for backward compat
    }
    $profitPct    = null;
    $profitAmount = null;
    if ($profitCalcType === 'percentage') {
        $profitPct = isset($body['profit_percentage']) ? (float) $body['profit_percentage'] : null;
        if ($profitPct === null || $profitPct < 0 || $profitPct > 999) {
            jsonError('profit_percentage must be a number between 0 and 999');
        }
    } else {
        $profitAmount = isset($body['profit_fixed_amount']) ? (float) $body['profit_fixed_amount'] : null;
        if ($profitAmount === null || $profitAmount < 0) {
            jsonError('profit_fixed_amount must be a non-negative number');
        }
    }
    
    // ── TRANSPORT: optional, percentage or fixed amount ──
    $transportCalcType = strtolower(strField($body['transport_calculation_type'] ?? '', 20));
    $transportPct    = null;
    $transportAmount = null;
    if ($transportCalcType !== '' && in_array($transportCalcType, ['percentage', 'fixed_amount'], true)) {
        if ($transportCalcType === 'percentage') {
            $transportPct = isset($body['transport_percentage']) ? (float) $body['transport_percentage'] : 0.0;
            if ($transportPct < 0 || $transportPct > 100) {
                jsonError('transport_percentage must be between 0 and 100');
            }
        } else {
            $transportAmount = isset($body['transport_fixed_amount']) ? (float) $body['transport_fixed_amount'] : 0.0;
            if ($transportAmount < 0) {
                jsonError('transport_fixed_amount must be non-negative');
            }
        }
    } else {
        $transportCalcType = null;
    }
    
    // ── TAX: optional, percentage or fixed amount ──
    $taxCalcType = strtolower(strField($body['tax_calculation_type'] ?? '', 20));
    $taxPct    = null;
    $taxAmount = null;
    if ($taxCalcType !== '' && in_array($taxCalcType, ['percentage', 'fixed_amount'], true)) {
        if ($taxCalcType === 'percentage') {
            $taxPct = isset($body['tax_percentage']) ? (float) $body['tax_percentage'] : 0.0;
            if ($taxPct < 0 || $taxPct > 100) {
                jsonError('tax_percentage must be between 0 and 100');
            }
        } else {
            $taxAmount = isset($body['tax_fixed_amount']) ? (float) $body['tax_fixed_amount'] : 0.0;
            if ($taxAmount < 0) {
                jsonError('tax_fixed_amount must be non-negative');
            }
        }
    } else {
        $taxCalcType = null;
    }
    
    // ── VALIDITY: duration in hours or days, max 7 days ──
    $validityAmount = (int) ($body['validity_amount'] ?? 7);
    $validityUnit   = strtolower(strField($body['validity_unit'] ?? '', 10));
    if (!in_array($validityUnit, ['hours', 'days'], true)) {
        $validityUnit = 'days';
    }
    // Validate max 7 days
    $maxHours = 7 * 24; // 168 hours
    $validityHours = $validityUnit === 'hours' ? $validityAmount : $validityAmount * 24;
    if ($validityHours <= 0 || $validityHours > $maxHours) {
        jsonError('Validity cannot exceed 7 days (168 hours)');
    }
    
    // ── MAX VISITS: optional, positive integer ──
    $maxVisits = null;
    if (isset($body['max_visits']) && $body['max_visits'] !== '' && $body['max_visits'] !== null) {
        $maxVisits = (int) $body['max_visits'];
        if ($maxVisits <= 0) {
            jsonError('max_visits must be a positive integer');
        }
    }
    
    $productIds   = array_map('intval', (array) ($body['product_ids'] ?? []));

    // ── VALIDATION ──
    if ($customerName === '') {
        jsonError('assigned_customer_name is required');
    }
    if (!in_array($baseType, ['fob', 'cif'], true)) {
        jsonError('price_base_type must be "fob" or "cif"');
    }
    if ($discountPct !== null && ($discountPct < 0 || $discountPct > 100)) {
        jsonError('discount_percentage must be between 0 and 100');
    }
    $productIds = array_values(array_unique(array_filter($productIds, fn($v) => $v > 0)));
    if (empty($productIds)) {
        jsonError('product_ids array is required and must contain at least one valid product ID');
    }

    // Load and validate products
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $pSt = $pdo->prepare(
        "SELECT id, product_name, price_fob, price_cif, active
           FROM supplier_products
            WHERE org_id = ? AND id IN ({$placeholders})"
    );
        $pSt->execute(array_merge([$targetOrgId], $productIds));
    $productMap = [];
    foreach ($pSt->fetchAll() as $p) {
        $productMap[(int) $p['id']] = $p;
    }

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

    // Generate token and calculate expires_at
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $plainToken);
    if ($validityUnit === 'hours') {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validityAmount} hours"));
    } else {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validityAmount} days"));
    }

    $orgSt = $pdo->prepare('SELECT id, name FROM organizations WHERE id = ? AND is_active = 1');
    $orgSt->execute([$targetOrgId]);
    $orgRow = $orgSt->fetch();
    if (!$orgRow) {
        jsonError('Current business unit is inactive', 422);
    }

    try {
        $pdo->beginTransaction();

        // Insert master quote record with new fee and validity fields
        $qIns = $pdo->prepare(
            'INSERT INTO quote_assignments
             (org_id, assigned_customer_name, company_name, special_conditions,
              discount_percentage,
              profit_calculation_type, profit_percentage, profit_fixed_amount,
              transport_calculation_type, transport_percentage, transport_fixed_amount,
              tax_calculation_type, tax_percentage, tax_fixed_amount,
              validity_amount, validity_unit, max_visits,
              token_hash, expires_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $qIns->execute([
            $targetOrgId,
            $customerName,
            $companyName !== '' ? $companyName : null,
            $conditions  !== '' ? $conditions  : null,
            $discountPct,
            $profitCalcType,
            $profitPct,
            $profitAmount,
            $transportCalcType,
            $transportPct,
            $transportAmount,
            $taxCalcType,
            $taxPct,
            $taxAmount,
            $validityAmount,
            $validityUnit,
            $maxVisits,
            $tokenHash,
            $expiresAt,
            $auth['user_id'],
        ]);
        $quoteId = (int) $pdo->lastInsertId();

        // Insert line items
        $iIns = $pdo->prepare(
            'INSERT INTO quote_assignment_items
             (quote_assignment_id, product_id, price_base_type,
              price_base_amount, profit_percentage, profit_calculation_type, profit_fixed_amount, final_unit_price)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($productIds as $pid) {
            $p         = $productMap[$pid];
            $baseAmt   = (float) ($baseType === 'fob' ? $p['price_fob'] : $p['price_cif']);
            
            // Calculate final price based on profit type
            $finalPrice = $baseAmt;
            if ($profitCalcType === 'percentage') {
                $finalPrice += $baseAmt * ($profitPct / 100);
            } else {
                $finalPrice += $profitAmount;
            }
            $finalPrice = round($finalPrice, 2);
            
            $iIns->execute([
                $quoteId, $pid, $baseType, $baseAmt, $profitPct, $profitCalcType, $profitAmount, $finalPrice
            ]);
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
        'org_id'     => $targetOrgId,
        'org_name'   => (string) ($orgRow['name'] ?? ''),
        'business_unit_name' => (string) ($orgRow['name'] ?? ''),
        'business_unit' => [
            'id' => $targetOrgId,
            'name' => (string) ($orgRow['name'] ?? ''),
        ],
    ], 201);
}

// ── REVOKE ────────────────────────────────────────────────────

function _revokeAssignment(int $id, array $auth, PDO $pdo): void
{
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    if (empty($allowedOrgIds)) {
        jsonError('Assignment not found, not in this org, or not active', 404);
    }

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $st = $pdo->prepare(
        "UPDATE quote_assignments
            SET status = 'revoked',
                revoked_at = NOW(),
                revoked_by_user_id = ?
          WHERE id = ? AND org_id IN ({$orgPlaceholders}) AND status = 'active'"
    );
    $st->execute(array_merge([$auth['user_id'], $id], $allowedOrgIds));

    if ($st->rowCount() === 0) {
        jsonError('Assignment not found, not in this org, or not active', 404);
    }

    jsonOk(['id' => $id, 'status' => 'revoked']);
}

// ── DELETE (soft) ─────────────────────────────────────────────

function _deleteAssignment(int $id, array $auth, PDO $pdo): void
{
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    if (empty($allowedOrgIds)) {
        jsonError('Assignment not found, not in this org, or already deleted', 404);
    }

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $st = $pdo->prepare(
        "UPDATE quote_assignments
            SET status = 'deleted',
                deleted_at = NOW(),
                deleted_by_user_id = ?
          WHERE id = ? AND org_id IN ({$orgPlaceholders}) AND status IN ('active','expired','revoked')"
    );
    $st->execute(array_merge([$auth['user_id'], $id], $allowedOrgIds));

    if ($st->rowCount() === 0) {
        jsonError('Assignment not found, not in this org, or already deleted', 404);
    }

    jsonOk(['id' => $id, 'status' => 'deleted']);
}

// ── CLONE (regenerate link) ───────────────────────────────────

function _cloneAssignment(int $id, array $auth, PDO $pdo): void
{
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    if (empty($allowedOrgIds)) {
        jsonError('Assignment not found or already deleted', 404);
    }

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    // Load parent
    $st = $pdo->prepare(
        "SELECT * FROM quote_assignments
          WHERE id = ? AND org_id IN ({$orgPlaceholders}) AND status != 'deleted'"
    );
    $st->execute(array_merge([$id], $allowedOrgIds));
    $parent = $st->fetch();
    if (!$parent) {
        jsonError('Assignment not found or already deleted', 404);
    }

    // Load parent items
    $iSt = $pdo->prepare(
        'SELECT product_id, price_base_type, price_base_amount,
                profit_percentage, profit_calculation_type, profit_fixed_amount, final_unit_price
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

    // Generate token and calculate expires_at based on parent's validity settings
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $plainToken);
    $validityAmount = (int) ($parent['validity_amount'] ?? 7);
    $validityUnit   = $parent['validity_unit'] ?? 'days';
    if ($validityUnit === 'hours') {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validityAmount} hours"));
    } else {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$validityAmount} days"));
    }

    try {
        $pdo->beginTransaction();

        $qIns = $pdo->prepare(
            'INSERT INTO quote_assignments
             (org_id, assigned_customer_name, company_name, special_conditions,
              discount_percentage,
              profit_calculation_type, profit_percentage, profit_fixed_amount,
              transport_calculation_type, transport_percentage, transport_fixed_amount,
              tax_calculation_type, tax_percentage, tax_fixed_amount,
              validity_amount, validity_unit, max_visits,
              token_hash, expires_at, created_by_user_id, parent_quote_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $qIns->execute([
            $parent['org_id'],
            $customerName,
            $parent['company_name'],
            $parent['special_conditions'],
            $parent['discount_percentage'],
            $parent['profit_calculation_type'],
            $parent['profit_percentage'],
            $parent['profit_fixed_amount'],
            $parent['transport_calculation_type'],
            $parent['transport_percentage'],
            $parent['transport_fixed_amount'],
            $parent['tax_calculation_type'],
            $parent['tax_percentage'],
            $parent['tax_fixed_amount'],
            $parent['validity_amount'],
            $parent['validity_unit'],
            $parent['max_visits'],
            $tokenHash,
            $expiresAt,
            $auth['user_id'],
            $id,    // parent_quote_id
        ]);
        $newId = (int) $pdo->lastInsertId();

        $iIns = $pdo->prepare(
            'INSERT INTO quote_assignment_items
             (quote_assignment_id, product_id, price_base_type,
              price_base_amount, profit_percentage, profit_calculation_type, profit_fixed_amount, final_unit_price)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $iIns->execute([
                $newId,
                $item['product_id'],
                $item['price_base_type'],
                $item['price_base_amount'],
                $item['profit_percentage'],
                $item['profit_calculation_type'],
                $item['profit_fixed_amount'],
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

// ── REPLICATE (POST /api/v1/quotes/:id/replicate) ─────────────
// Preferred alias of clone with:
//  - customer_name required (cannot inherit from parent)
//  - company_name optional (blank by default)
//  - special_conditions optional override (defaults to parent's value)
//  - Response uses quote_id / public_url for clarity

function handleQuoteReplicate(string $method, ?int $id, string $action): void
{
    if ($method !== 'POST' || $id === null || $action !== 'replicate') {
        jsonError('Method Not Allowed', 405);
    }
    $auth = requireAuth(['admin', 'owner']);
    $pdo  = getDB();
    _replicateAssignment($id, $auth, $pdo);
}

function _replicateAssignment(int $id, array $auth, PDO $pdo): void
{
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    if (empty($allowedOrgIds)) {
        jsonError('Quote not found or not accessible', 404);
    }

    // 1. Load parent — RBAC-scoped by org (IDOR protection)
    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $st = $pdo->prepare(
        "SELECT id, org_id, special_conditions, discount_percentage,
                profit_calculation_type, profit_percentage, profit_fixed_amount,
                transport_calculation_type, transport_percentage, transport_fixed_amount,
                tax_calculation_type, tax_percentage, tax_fixed_amount,
                validity_amount, validity_unit, max_visits
           FROM quote_assignments
          WHERE id = ? AND org_id IN ({$orgPlaceholders}) AND status != 'deleted'
          LIMIT 1"
    );
    $st->execute(array_merge([$id], $allowedOrgIds));
    $parent = $st->fetch();
    if (!$parent) {
        jsonError('Quote not found or already deleted', 404);
    }

    // 2. Load parent items — prices frozen at creation, never re-calculated
    $iSt = $pdo->prepare(
        'SELECT product_id, price_base_type, price_base_amount,
                profit_percentage, profit_calculation_type, profit_fixed_amount, final_unit_price
           FROM quote_assignment_items
          WHERE quote_assignment_id = ?'
    );
    $iSt->execute([$id]);
    $items = $iSt->fetchAll();
    if (empty($items)) {
        jsonError('Parent quote has no items', 422);
    }

    // 3. Validate payload — customer_name required; company + conditions optional
    $body         = parseBody();
    $customerName = strField($body['customer_name'] ?? '', 200);
    if ($customerName === '') {
        jsonError('customer_name is required and must not be empty', 422);
    }
    $companyName = strField($body['company_name'] ?? '', 200);
    // special_conditions: if provided in payload, use it; else inherit from parent
    $conditions = isset($body['special_conditions'])
        ? strField((string) $body['special_conditions'], 5000)
        : (string) ($parent['special_conditions'] ?? '');

    // 4. Relative expiry — same validity_amount + validity_unit as parent (max 7 days)
    $validityAmount = (int) ($parent['validity_amount'] ?? 7);
    $validityUnit   = in_array($parent['validity_unit'], ['hours', 'days'], true)
                    ? $parent['validity_unit'] : 'days';
    $validityHours  = $validityUnit === 'hours' ? $validityAmount : ($validityAmount * 24);
    if ($validityHours <= 0 || $validityHours > 168) {
        $validityAmount = 7;
        $validityUnit   = 'days';
    }

    // 5. New cryptographically-secure token — never reuse parent token
    $plainToken = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $plainToken);
    $expiresAt  = $validityUnit === 'hours'
        ? date('Y-m-d H:i:s', strtotime("+{$validityAmount} hours"))
        : date('Y-m-d H:i:s', strtotime("+{$validityAmount} days"));

    // 6. Transactional insert — new quote row + line items (cloned)
    try {
        $pdo->beginTransaction();

        $qIns = $pdo->prepare(
            'INSERT INTO quote_assignments
             (org_id, assigned_customer_name, company_name, special_conditions,
              discount_percentage,
              profit_calculation_type, profit_percentage, profit_fixed_amount,
              transport_calculation_type, transport_percentage, transport_fixed_amount,
              tax_calculation_type, tax_percentage, tax_fixed_amount,
              validity_amount, validity_unit, max_visits,
              token_hash, expires_at, created_by_user_id, parent_quote_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $qIns->execute([
            (int) $parent['org_id'],
            $customerName,
            $companyName !== '' ? $companyName : null,
            $conditions  !== '' ? $conditions  : null,
            $parent['discount_percentage'],
            $parent['profit_calculation_type'],
            $parent['profit_percentage'],
            $parent['profit_fixed_amount'],
            $parent['transport_calculation_type'],
            $parent['transport_percentage'],
            $parent['transport_fixed_amount'],
            $parent['tax_calculation_type'],
            $parent['tax_percentage'],
            $parent['tax_fixed_amount'],
            $validityAmount,
            $validityUnit,
            $parent['max_visits'],
            $tokenHash,
            $expiresAt,
            $auth['user_id'],
            $id,   // parent_quote_id for traceability
        ]);
        $newId = (int) $pdo->lastInsertId();

        $iIns = $pdo->prepare(
            'INSERT INTO quote_assignment_items
             (quote_assignment_id, product_id, price_base_type,
              price_base_amount, profit_percentage, profit_calculation_type,
              profit_fixed_amount, final_unit_price)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $iIns->execute([
                $newId,
                (int) $item['product_id'],
                $item['price_base_type'],
                $item['price_base_amount'],
                $item['profit_percentage'],
                $item['profit_calculation_type'],
                $item['profit_fixed_amount'],
                $item['final_unit_price'],
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('_replicateAssignment error: ' . $e->getMessage());
        jsonError('Save failed', 500);
    }

    // 7. Response — token returned ONCE; plain token never stored in DB
    jsonOk([
        'quote_id'        => $newId,
        'parent_quote_id' => $id,
        'public_url'      => _buildQuoteUrl($plainToken),
        'expires_at'      => $expiresAt,
        'max_views'       => $parent['max_visits'],    // null = unlimited
        'status'          => 'active',
    ], 201);
}

