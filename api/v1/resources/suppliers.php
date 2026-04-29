<?php
/**
 * api/v1/resources/suppliers.php — Suppliers resource handler.
 *
 * Routes:
 *   GET /api/v1/suppliers          list active suppliers with contract status
 *   GET /api/v1/suppliers/:id      supplier detail + contacts + product count
 *
 * RBAC: admin/owner only.
 *
 * Security:
 *   - No supplier_product_code or cost data exposed.
 *   - All params bound via PDO prepared statements.
 */

function handleSuppliers(string $method, ?int $id): void
{
    $auth = requireAuth(['admin', 'owner']);
    $pdo  = getDB();

    match (true) {
        $method === 'GET' && $id === null => _listSuppliers($auth, $pdo),
        $method === 'GET' && $id !== null => _getSupplier($id, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

// ── LIST ─────────────────────────────────────────────────────

function _listSuppliers(array $auth, PDO $pdo): void
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $offset  = ($page - 1) * $perPage;

    // Optional search
    $search = strField($_GET['q'] ?? '', 100);

    // TENANT ISOLATION: only suppliers in the current org
    $where  = ["om.org_id = ?", "om.role = 'supplier'", "om.is_active = 1", 'u.is_active = 1'];
    $params = [$auth['org_id']];

    if ($search !== '') {
        $where[]  = '(u.username LIKE ? OR u.company_name LIKE ? OR u.full_name LIKE ?)';
        $lk       = likeWrap($search);
        $params[] = $lk;
        $params[] = $lk;
        $params[] = $lk;
    }

    $wSql = implode(' AND ', $where);

    $cntSt = $pdo->prepare(
        "SELECT COUNT(DISTINCT u.id) FROM users u
          JOIN org_members om ON om.user_id = u.id
         WHERE {$wSql}"
    );
    $cntSt->execute($params);
    $total = (int) $cntSt->fetchColumn();

    $st = $pdo->prepare(
        "SELECT DISTINCT u.id, u.username, u.full_name, u.company_name, u.email,
                u.addr_city, u.addr_country_id, u.created_at,
                (SELECT COUNT(*) FROM supplier_products sp
                  WHERE sp.supplier_id = u.id AND sp.active = 1 AND sp.org_id = ?)  AS product_count,
                (SELECT COUNT(*) FROM supplier_contracts sc
                  WHERE sc.supplier_id = u.id AND sc.org_id = ?)                    AS contract_count,
                (SELECT MAX(sc2.signed_date) FROM supplier_contracts sc2
                  WHERE sc2.supplier_id = u.id AND sc2.is_primary = 1
                    AND sc2.org_id = ?)                                              AS latest_contract_date
           FROM users u
           JOIN org_members om ON om.user_id = u.id
          WHERE {$wSql}
          ORDER BY u.company_name ASC, u.username ASC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    // Prepend org_id three times for the subqueries, then the WHERE params
    $st->execute(array_merge([$auth['org_id'], $auth['org_id'], $auth['org_id']], $params));
    $rows = $st->fetchAll();

    $items = array_map(fn($r) => [
        'id'                  => (int)    $r['id'],
        'username'            => (string) $r['username'],
        'full_name'           => (string) ($r['full_name'] ?? ''),
        'company_name'        => (string) ($r['company_name'] ?? ''),
        'email'               => (string) $r['email'],
        'addr_city'           => (string) ($r['addr_city'] ?? ''),
        'product_count'       => (int)    $r['product_count'],
        'contract_count'      => (int)    $r['contract_count'],
        'latest_contract_date'=> $r['latest_contract_date'],
        'created_at'          => $r['created_at'],
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

function _getSupplier(int $id, array $auth, PDO $pdo): void
{
    // TENANT ISOLATION: verify supplier belongs to current org
    $st = $pdo->prepare(
        "SELECT u.id, u.username, u.full_name, u.company_name, u.email,
                u.tax_id, u.legal_rep_name,
                u.company_phone_code, u.company_phone_number,
                u.addr_street, u.addr_city, u.addr_state, u.addr_zip, u.addr_country_id,
                u.factory_street, u.factory_city, u.factory_state, u.factory_zip,
                u.factory_country_id, u.is_active, u.created_at
           FROM users u
           JOIN org_members om ON om.user_id = u.id
          WHERE u.id = ?
            AND om.org_id = ?
            AND om.role = 'supplier'
            AND om.is_active = 1"
    );
    $st->execute([$id, $auth['org_id']]);
    $supplier = $st->fetch();

    if (!$supplier) {
        jsonError('Supplier not found', 404);
    }

    // Contacts
    $cSt = $pdo->prepare(
        'SELECT id, name, role, email, phone_code, phone_number, is_primary
           FROM supplier_contacts
          WHERE supplier_id = ?
          ORDER BY is_primary DESC, name ASC'
    );
    $cSt->execute([$id]);
    $contacts = $cSt->fetchAll();

    // Product count — scoped to org
    $pSt = $pdo->prepare(
        'SELECT COUNT(*) FROM supplier_products WHERE supplier_id = ? AND org_id = ? AND active = 1'
    );
    $pSt->execute([$id, $auth['org_id']]);
    $productCount = (int) $pSt->fetchColumn();

    // Primary contracts — scoped to org
    $contractSt = $pdo->prepare(
        "SELECT id, signed_date, effective_start_date, effective_end_date,
                original_filename, is_primary, created_at
           FROM supplier_contracts
          WHERE supplier_id = ? AND org_id = ?
          ORDER BY is_primary DESC, created_at DESC
          LIMIT 5"
    );
    $contractSt->execute([$id, $auth['org_id']]);
    $contracts = $contractSt->fetchAll();

    jsonOk([
        'supplier' => [
            'id'                  => (int)    $supplier['id'],
            'username'            => (string) $supplier['username'],
            'full_name'           => (string) ($supplier['full_name'] ?? ''),
            'company_name'        => (string) ($supplier['company_name'] ?? ''),
            'email'               => (string) $supplier['email'],
            'tax_id'              => (string) ($supplier['tax_id'] ?? ''),
            'legal_rep_name'      => (string) ($supplier['legal_rep_name'] ?? ''),
            'company_phone'       => trim(($supplier['company_phone_code'] ?? '') . ' ' . ($supplier['company_phone_number'] ?? '')),
            'address'             => [
                'street'     => (string) ($supplier['addr_street'] ?? ''),
                'city'       => (string) ($supplier['addr_city'] ?? ''),
                'state'      => (string) ($supplier['addr_state'] ?? ''),
                'zip'        => (string) ($supplier['addr_zip'] ?? ''),
                'country_id' => $supplier['addr_country_id'] ? (int) $supplier['addr_country_id'] : null,
            ],
            'factory_address'     => [
                'street'     => (string) ($supplier['factory_street'] ?? ''),
                'city'       => (string) ($supplier['factory_city'] ?? ''),
                'state'      => (string) ($supplier['factory_state'] ?? ''),
                'zip'        => (string) ($supplier['factory_zip'] ?? ''),
                'country_id' => $supplier['factory_country_id'] ? (int) $supplier['factory_country_id'] : null,
            ],
            'is_active'           => (bool)   $supplier['is_active'],
            'product_count'       => $productCount,
            'contacts'            => $contacts,
            'contracts'           => $contracts,
            'created_at'          => $supplier['created_at'],
        ],
    ]);
}
