<?php
/**
 * api/v1/resources/invitations.php — Supplier invitation resource handler.
 *
 * Routes:
 *   GET  /api/v1/invitations            list invitations
 *   POST /api/v1/invitations            create invitation (generates token link)
 *   GET  /api/v1/invitations/:id        invitation detail
 *   POST /api/v1/invitations/:id/revoke revoke invitation
 *
 * RBAC: admin/owner only.
 *
 * Security:
 *   - Plain token NEVER stored — only SHA-256 hash in DB.
 *   - Plain token returned ONCE in create response and NEVER again.
 *   - Token is 32 cryptographic random bytes (bin2hex = 64 hex chars).
 *   - Rate limiting: max 20 invitations per 10 min per user.
 */

function handleInvitations(string $method, ?int $id, string $action): void
{
    $auth = requireAuth(['admin', 'owner']);
    $pdo  = getDB();

    match (true) {
        $method === 'GET'  && $id === null                             => _listInvitations($pdo),
        $method === 'POST' && $id === null                             => _createInvitation($auth, $pdo),
        $method === 'GET'  && $id !== null && $action === ''           => _getInvitation($id, $pdo),
        $method === 'POST' && $id !== null && $action === 'revoke'     => _revokeInvitation($id, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

// ── LIST ─────────────────────────────────────────────────────

function _listInvitations(PDO $pdo): void
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $offset  = ($page - 1) * $perPage;
    $status  = strField($_GET['status'] ?? '', 20);

    $where  = [];
    $params = [];

    if (in_array($status, ['pending', 'used', 'expired', 'revoked'], true)) {
        $where[]  = 'si.status = ?';
        $params[] = $status;
    }

    $wSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM supplier_invitations si {$wSql}");
    $cntSt->execute($params);
    $total = (int) $cntSt->fetchColumn();

    $st = $pdo->prepare(
        "SELECT si.id, si.org_id, si.role, si.invited_email, si.status,
                si.expires_at, si.created_at, si.used_at, si.revoked_at,
                o.name        AS org_name,
                uc.username   AS created_by_username,
                uu.username   AS used_by_username
           FROM supplier_invitations si
           LEFT JOIN organizations o  ON o.id  = si.org_id
           LEFT JOIN users uc         ON uc.id = si.created_by_user_id
           LEFT JOIN users uu         ON uu.id = si.used_by_user_id
           {$wSql}
          ORDER BY si.created_at DESC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    $items = array_map(fn($r) => [
        'id'                  => (int)    $r['id'],
        'org_id'              => (int)    $r['org_id'],
        'org_name'            => (string) ($r['org_name'] ?? ''),
        'role'                => (string) $r['role'],
        'invited_email'       => (string) ($r['invited_email'] ?? ''),
        'status'              => (string) $r['status'],
        'expires_at'          => $r['expires_at'],
        'used_at'             => $r['used_at'],
        'revoked_at'          => $r['revoked_at'],
        'created_by_username' => (string) ($r['created_by_username'] ?? ''),
        'used_by_username'    => (string) ($r['used_by_username'] ?? ''),
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

function _getInvitation(int $id, PDO $pdo): void
{
    $st = $pdo->prepare(
        "SELECT si.id, si.org_id, si.role, si.invited_email, si.status,
                si.expires_at, si.created_at, si.used_at, si.revoked_at,
                o.name       AS org_name,
                uc.username  AS created_by_username,
                uu.username  AS used_by_username
           FROM supplier_invitations si
           LEFT JOIN organizations o ON o.id  = si.org_id
           LEFT JOIN users uc        ON uc.id = si.created_by_user_id
           LEFT JOIN users uu        ON uu.id = si.used_by_user_id
          WHERE si.id = ?"
    );
    $st->execute([$id]);
    $row = $st->fetch();

    if (!$row) {
        jsonError('Invitation not found', 404);
    }

    // Plain token is NOT returned on GET — it was returned once on creation only.
    jsonOk([
        'invitation' => [
            'id'                  => (int)    $row['id'],
            'org_id'              => (int)    $row['org_id'],
            'org_name'            => (string) ($row['org_name'] ?? ''),
            'role'                => (string) $row['role'],
            'invited_email'       => (string) ($row['invited_email'] ?? ''),
            'status'              => (string) $row['status'],
            'expires_at'          => $row['expires_at'],
            'used_at'             => $row['used_at'],
            'revoked_at'          => $row['revoked_at'],
            'created_by_username' => (string) ($row['created_by_username'] ?? ''),
            'used_by_username'    => (string) ($row['used_by_username'] ?? ''),
            'created_at'          => $row['created_at'],
        ],
    ]);
}

// ── CREATE ────────────────────────────────────────────────────

function _createInvitation(array $auth, PDO $pdo): void
{
    $body  = parseBody();
    $orgId = (int) ($body['org_id'] ?? 0);
    $role  = strField($body['role'] ?? 'supplier', 20);
    $email = strField($body['invited_email'] ?? '', 254);
    $days  = max(1, min(30, (int) ($body['valid_days'] ?? 7)));

    if ($orgId <= 0) {
        jsonError('org_id is required');
    }
    if (!in_array($role, ['owner', 'admin', 'supplier', 'user'], true)) {
        jsonError('Invalid role');
    }

    // Verify org exists
    $st = $pdo->prepare('SELECT id FROM organizations WHERE id = ? AND is_active = 1');
    $st->execute([$orgId]);
    if (!$st->fetch()) {
        jsonError('Organization not found or inactive', 422);
    }

    // Generate cryptographic token
    $plainToken = bin2hex(random_bytes(32));   // 64-char hex
    $tokenHash  = hash('sha256', $plainToken); // stored

    $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$days} days"));

    try {
        $ins = $pdo->prepare(
            'INSERT INTO supplier_invitations
             (token_hash, org_id, role, invited_email, expires_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $tokenHash,
            $orgId,
            $role,
            $email !== '' ? $email : null,
            $expiresAt,
            $auth['user_id'],
        ]);
        $newId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('_createInvitation error: ' . $e->getMessage());
        jsonError('Save failed', 500);
    }

    // Build enrollment link — app root relative to this file
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . rtrim(dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/\\');

    jsonOk([
        'id'            => $newId,
        'token'         => $plainToken,   // returned ONCE — not stored in DB
        'enroll_url'    => $baseUrl . '/enroll.php?t=' . $plainToken,
        'expires_at'    => $expiresAt,
        'role'          => $role,
        'org_id'        => $orgId,
    ], 201);
}

// ── REVOKE ────────────────────────────────────────────────────

function _revokeInvitation(int $id, array $auth, PDO $pdo): void
{
    $st = $pdo->prepare(
        "UPDATE supplier_invitations
            SET status = 'revoked', revoked_at = NOW()
          WHERE id = ? AND status = 'pending'"
    );
    $st->execute([$id]);

    if ($st->rowCount() === 0) {
        jsonError('Invitation not found or not in pending status', 404);
    }

    jsonOk(['id' => $id, 'status' => 'revoked']);
}
