<?php
/**
 * api/v1/resources/contracts.php — Supplier contracts resource handler.
 *
 * Routes:
 *   GET  /api/v1/contracts           list contracts (filter: ?supplier_id=)
 *   POST /api/v1/contracts           upload a new contract (multipart/form-data)
 *   GET  /api/v1/contracts/:id       contract detail (metadata only, no file stream)
 *
 * Design note: contracts are immutable — no PATCH or DELETE routes.
 * To deactivate a contract, use the web interface (set is_primary = 0).
 *
 * RBAC: admin/owner only.
 *
 * Security:
 *   - File upload via multipart validated (PDF/JPEG/PNG, max 10 MB).
 *   - storage_path is server-controlled, never from user input.
 *   - IDOR not applicable (admin sees all contracts by design).
 */

define('CONTRACT_MAX_BYTES', 10 * 1024 * 1024); // 10 MB
define('CONTRACT_ALLOWED_MIME', ['application/pdf', 'image/jpeg', 'image/png']);

require_once __DIR__ . '/../../../includes/storage.php';

function handleContracts(string $method, ?int $id): void
{
    $auth = requireApiAuth(['admin', 'owner']);
    $pdo  = getDB();

    match (true) {
        $method === 'GET'  && $id === null => _listContracts($auth, $pdo),
        $method === 'POST' && $id === null => _createContract($auth, $pdo),
        $method === 'GET'  && $id !== null => _getContract($id, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

// ── LIST ─────────────────────────────────────────────────────

function _listContracts(array $auth, PDO $pdo): void
{
    $page       = max(1, (int) ($_GET['page'] ?? 1));
    $perPage    = 25;
    $offset     = ($page - 1) * $perPage;
    $supplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;

    // TENANT ISOLATION: always scope to session org
    $where  = ['sc.org_id = ?'];
    $params = [$auth['org_id']];

    if ($supplierId !== null && $supplierId > 0) {
        $where[]  = 'sc.supplier_id = ?';
        $params[] = $supplierId;
    }

    $wSql = 'WHERE ' . implode(' AND ', $where);

    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM supplier_contracts sc {$wSql}");
    $cntSt->execute($params);
    $total = (int) $cntSt->fetchColumn();

    $st = $pdo->prepare(
        "SELECT sc.id, sc.supplier_id,
                u.username     AS supplier_username,
                u.company_name AS supplier_company,
                sc.original_filename, sc.mime_type, sc.file_size,
                sc.signed_date, sc.effective_start_date, sc.effective_end_date,
                sc.is_primary, sc.notes, sc.created_at
           FROM supplier_contracts sc
           JOIN users u ON u.id = sc.supplier_id
           {$wSql}
          ORDER BY sc.supplier_id ASC, sc.is_primary DESC, sc.created_at DESC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    $items = array_map(fn($r) => [
        'id'                  => (int)    $r['id'],
        'supplier_id'         => (int)    $r['supplier_id'],
        'supplier_username'   => (string) $r['supplier_username'],
        'supplier_company'    => (string) ($r['supplier_company'] ?? ''),
        'original_filename'   => (string) $r['original_filename'],
        'mime_type'           => (string) $r['mime_type'],
        'file_size'           => (int)    $r['file_size'],
        'signed_date'         => $r['signed_date'],
        'effective_start_date'=> $r['effective_start_date'],
        'effective_end_date'  => $r['effective_end_date'],
        'is_primary'          => (bool)   $r['is_primary'],
        'notes'               => (string) ($r['notes'] ?? ''),
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

function _getContract(int $id, array $auth, PDO $pdo): void
{
    // TENANT ISOLATION: filter by org_id
    $st = $pdo->prepare(
        "SELECT sc.id, sc.supplier_id,
                u.username     AS supplier_username,
                u.company_name AS supplier_company,
                sc.original_filename, sc.mime_type, sc.file_size, sc.file_hash,
                sc.signed_date, sc.effective_start_date, sc.effective_end_date,
                sc.is_primary, sc.notes, sc.uploaded_by_user_id, sc.created_at
           FROM supplier_contracts sc
           JOIN users u ON u.id = sc.supplier_id
          WHERE sc.id = ? AND sc.org_id = ?"
    );
    $st->execute([$id, $auth['org_id']]);
    $row = $st->fetch();

    if (!$row) {
        jsonError('Contract not found', 404);
    }

    jsonOk([
        'contract' => [
            'id'                  => (int)    $row['id'],
            'supplier_id'         => (int)    $row['supplier_id'],
            'supplier_username'   => (string) $row['supplier_username'],
            'supplier_company'    => (string) ($row['supplier_company'] ?? ''),
            'original_filename'   => (string) $row['original_filename'],
            'mime_type'           => (string) $row['mime_type'],
            'file_size'           => (int)    $row['file_size'],
            'file_hash'           => (string) ($row['file_hash'] ?? ''),
            'signed_date'         => $row['signed_date'],
            'effective_start_date'=> $row['effective_start_date'],
            'effective_end_date'  => $row['effective_end_date'],
            'is_primary'          => (bool)   $row['is_primary'],
            'notes'               => (string) ($row['notes'] ?? ''),
            'uploaded_by_user_id' => (int)    $row['uploaded_by_user_id'],
            'created_at'          => $row['created_at'],
        ],
    ]);
}

// ── CREATE (upload) ───────────────────────────────────────────

function _createContract(array $auth, PDO $pdo): void
{
    // Validate required POST fields
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    if ($supplierId <= 0) {
        jsonError('supplier_id is required');
    }

    // TENANT ISOLATION: verify supplier belongs to current org
    $st = $pdo->prepare(
        'SELECT u.id FROM users u
          JOIN org_members om ON om.user_id = u.id
         WHERE u.id = ? AND u.is_active = 1
           AND om.org_id = ? AND om.is_active = 1
           AND om.role = "supplier"
         LIMIT 1'
    );
    $st->execute([$supplierId, $auth['org_id']]);
    if (!$st->fetch()) {
        jsonError('Supplier not found or not a member of this business unit', 422);
    }

    // File upload validation
    if (empty($_FILES['contract_file']) || $_FILES['contract_file']['error'] !== UPLOAD_ERR_OK) {
        jsonError('contract_file upload required (multipart/form-data)');
    }

    $file = $_FILES['contract_file'];

    if ($file['size'] > CONTRACT_MAX_BYTES) {
        jsonError('File exceeds maximum size of 10 MB', 422);
    }

    // MIME validation using finfo (server-side, not trusting Content-Type header)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, CONTRACT_ALLOWED_MIME, true)) {
        jsonError('Invalid file type. Allowed: PDF, JPEG, PNG', 422);
    }

    // Build storage path
    $ext       = match ($mimeType) {
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    };
    $storagDir = appStorageDir('contracts') . DIRECTORY_SEPARATOR . $supplierId . DIRECTORY_SEPARATOR;
    if (!is_dir($storagDir)) {
        mkdir($storagDir, 0755, true);
    }
    $filename    = bin2hex(random_bytes(16)) . '.' . $ext;
    $storagePath = 'uploads/contracts/' . $supplierId . '/' . $filename;
    $fullPath    = $storagDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        error_log('_createContract: move_uploaded_file failed for supplier ' . $supplierId);
        jsonError('File storage failed', 500);
    }

    $fileHash  = hash_file('sha256', $fullPath);
    $isPrimary = isset($_POST['is_primary']) && $_POST['is_primary'] === '1' ? 1 : 0;

    // If marking as primary, clear existing primary first (scoped to org)
    if ($isPrimary) {
        $pdo->prepare(
            'UPDATE supplier_contracts SET is_primary = 0 WHERE supplier_id = ? AND org_id = ?'
        )->execute([$supplierId, $auth['org_id']]);
    }

    $ins = $pdo->prepare(
        'INSERT INTO supplier_contracts
         (supplier_id, org_id, storage_path, original_filename, mime_type, file_size, file_hash,
          signed_date, effective_start_date, effective_end_date, notes,
          is_primary, uploaded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $supplierId,
        $auth['org_id'],
        $storagePath,
        basename($file['name']),
        $mimeType,
        $file['size'],
        $fileHash,
        strField($_POST['signed_date'] ?? '', 10) ?: null,
        strField($_POST['effective_start_date'] ?? '', 10) ?: null,
        strField($_POST['effective_end_date'] ?? '', 10) ?: null,
        strField($_POST['notes'] ?? '', 2000) ?: null,
        $isPrimary,
        $auth['user_id'],
    ]);

    jsonOk(['id' => (int) $pdo->lastInsertId()], 201);
}
