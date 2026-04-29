<?php
/**
 * api/v1/resources/business_units.php — Business Units (Organizations) resource handler.
 *
 * Routes:
 *   GET    /api/v1/business-units              list all organizations (owner only)
 *   POST   /api/v1/business-units              create new organization (owner only)
 *   GET    /api/v1/business-units/:id          organization detail (owner only)
 *   PATCH  /api/v1/business-units/:id          update org name/description/status (owner only)
 *   GET    /api/v1/business-units/:id/admins   list admins assigned to org (owner only)
 *   POST   /api/v1/business-units/:id/admins   assign an admin to org (owner only)
 *   DELETE /api/v1/business-units/:id/admins/:uid  remove admin from org (owner only)
 *
 * RBAC: owner only.
 *
 * Security:
 *   - Slug uniqueness enforced at DB level (UNIQUE KEY) and application level.
 *   - Slug is sanitized: lowercase alphanumeric + hyphens only.
 *   - IDOR not applicable for owner (owner sees all by design).
 *   - All parameters bound via PDO prepared statements.
 *   - org_id NEVER taken from request body — always from session or URL segment.
 */

function handleBusinessUnits(string $method, ?int $id, string $sub, string $subId): void
{
    $auth = requireAuth(['owner']);
    $pdo  = getDB();

    // Sub-resource: /business-units/:id/admins[/:uid]
    if ($id !== null && $sub === 'admins') {
        _handleAdmins($method, $id, (int) $subId ?: null, $auth, $pdo);
        return;
    }

    match (true) {
        $method === 'GET'   && $id === null => _listBusinessUnits($pdo),
        $method === 'POST'  && $id === null => _createBusinessUnit($auth, $pdo),
        $method === 'GET'   && $id !== null => _getBusinessUnit($id, $pdo),
        $method === 'PATCH' && $id !== null => _updateBusinessUnit($id, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

// ── LIST ─────────────────────────────────────────────────────

function _listBusinessUnits(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT o.id, o.slug, o.name, o.description, o.is_active, o.created_at,
                COUNT(DISTINCT om.user_id) AS member_count
           FROM organizations o
           LEFT JOIN org_members om ON om.org_id = o.id AND om.is_active = 1
          GROUP BY o.id
          ORDER BY o.name ASC"
    );
    $rows = $stmt->fetchAll();

    $items = array_map(fn($r) => [
        'id'           => (int)    $r['id'],
        'slug'         => (string) $r['slug'],
        'name'         => (string) $r['name'],
        'description'  => (string) ($r['description'] ?? ''),
        'is_active'    => (bool)   $r['is_active'],
        'member_count' => (int)    $r['member_count'],
        'created_at'   => $r['created_at'],
    ], $rows);

    jsonOk(['items' => $items, 'total' => count($items)]);
}

// ── DETAIL ───────────────────────────────────────────────────

function _getBusinessUnit(int $id, PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT o.id, o.slug, o.name, o.description, o.is_active, o.created_at,
                COUNT(DISTINCT om.user_id) AS member_count
           FROM organizations o
           LEFT JOIN org_members om ON om.org_id = o.id AND om.is_active = 1
          WHERE o.id = ?
          GROUP BY o.id"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    if (!$r) {
        jsonError('Business unit not found', 404);
    }

    // Per-role breakdown
    $roleStmt = $pdo->prepare(
        "SELECT om.role, COUNT(*) AS cnt
           FROM org_members om
          WHERE om.org_id = ? AND om.is_active = 1
          GROUP BY om.role"
    );
    $roleStmt->execute([$id]);
    $roleBreakdown = [];
    foreach ($roleStmt->fetchAll() as $rb) {
        $roleBreakdown[$rb['role']] = (int) $rb['cnt'];
    }

    jsonOk([
        'business_unit' => [
            'id'             => (int)    $r['id'],
            'slug'           => (string) $r['slug'],
            'name'           => (string) $r['name'],
            'description'    => (string) ($r['description'] ?? ''),
            'is_active'      => (bool)   $r['is_active'],
            'member_count'   => (int)    $r['member_count'],
            'role_breakdown' => $roleBreakdown,
            'created_at'     => $r['created_at'],
        ],
    ]);
}

// ── CREATE ────────────────────────────────────────────────────

function _createBusinessUnit(array $auth, PDO $pdo): void
{
    $body = parseBody();

    $name        = strField($body['name']        ?? '', 200);
    $slug        = _sanitizeSlug($body['slug']   ?? '');
    $description = strField($body['description'] ?? '', 500);
    $isActive    = isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1;

    if ($name === '') {
        jsonError('name is required');
    }
    if ($slug === '') {
        jsonError('slug is required and must contain only letters, numbers, and hyphens');
    }

    // Uniqueness pre-check (friendly error before DB unique constraint)
    $chk = $pdo->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
    $chk->execute([$slug]);
    if ($chk->fetch()) {
        jsonError("Slug '{$slug}' is already in use", 422);
    }

    try {
        $ins = $pdo->prepare(
            'INSERT INTO organizations (slug, name, description, is_active)
             VALUES (?, ?, ?, ?)'
        );
        $ins->execute([
            $slug,
            $name,
            $description !== '' ? $description : null,
            $isActive,
        ]);
        $newId = (int) $pdo->lastInsertId();
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError("Slug '{$slug}' is already in use", 422);
        }
        error_log('_createBusinessUnit error: ' . $e->getMessage());
        jsonError('Save failed', 500);
    }

    // Automatically add the creating owner as a member of the new business unit
    try {
        $pdo->prepare(
            'INSERT INTO org_members (user_id, org_id, role) VALUES (?, ?, "owner")
             ON DUPLICATE KEY UPDATE is_active = 1, role = "owner"'
        )->execute([$auth['user_id'], $newId]);
    } catch (\PDOException $e) {
        error_log('_createBusinessUnit owner member error: ' . $e->getMessage());
        // Non-fatal — org was created; log and continue
    }

    jsonOk([
        'id'        => $newId,
        'slug'      => $slug,
        'name'      => $name,
        'is_active' => (bool) $isActive,
    ], 201);
}

// ── UPDATE ────────────────────────────────────────────────────

function _updateBusinessUnit(int $id, PDO $pdo): void
{
    // Verify org exists
    $chk = $pdo->prepare('SELECT id, slug FROM organizations WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    $existing = $chk->fetch();
    if (!$existing) {
        jsonError('Business unit not found', 404);
    }

    $body = parseBody();
    $sets   = [];
    $params = [];

    if (array_key_exists('name', $body)) {
        $name = strField($body['name'], 200);
        if ($name === '') {
            jsonError('name cannot be empty');
        }
        $sets[]   = '`name` = ?';
        $params[] = $name;
    }

    if (array_key_exists('description', $body)) {
        $sets[]   = '`description` = ?';
        $params[] = strField($body['description'], 500) ?: null;
    }

    if (array_key_exists('is_active', $body)) {
        $sets[]   = '`is_active` = ?';
        $params[] = (int) (bool) $body['is_active'];
    }

    // Slug update: must remain unique
    if (array_key_exists('slug', $body)) {
        $newSlug = _sanitizeSlug($body['slug']);
        if ($newSlug === '') {
            jsonError('slug must contain only letters, numbers, and hyphens');
        }
        if ($newSlug !== $existing['slug']) {
            $dupChk = $pdo->prepare('SELECT id FROM organizations WHERE slug = ? AND id != ? LIMIT 1');
            $dupChk->execute([$newSlug, $id]);
            if ($dupChk->fetch()) {
                jsonError("Slug '{$newSlug}' is already in use", 422);
            }
        }
        $sets[]   = '`slug` = ?';
        $params[] = $newSlug;
    }

    if (empty($sets)) {
        jsonError('No updatable fields provided');
    }

    $params[] = $id;
    try {
        $pdo->prepare('UPDATE organizations SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($params);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('Slug is already in use', 422);
        }
        error_log('_updateBusinessUnit error: ' . $e->getMessage());
        jsonError('Update failed', 500);
    }

    jsonOk(['id' => $id, 'updated' => true]);
}

// ── ADMINS sub-resource ───────────────────────────────────────

function _handleAdmins(string $method, int $orgId, ?int $userId, array $auth, PDO $pdo): void
{
    // Verify org exists
    $chk = $pdo->prepare('SELECT id FROM organizations WHERE id = ? LIMIT 1');
    $chk->execute([$orgId]);
    if (!$chk->fetch()) {
        jsonError('Business unit not found', 404);
    }

    match (true) {
        $method === 'GET'    && $userId === null => _listOrgAdmins($orgId, $pdo),
        $method === 'POST'   && $userId === null => _assignAdmin($orgId, $auth, $pdo),
        $method === 'DELETE' && $userId !== null => _removeAdmin($orgId, $userId, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

function _listOrgAdmins(int $orgId, PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.email, u.full_name, u.company_name,
                u.is_active, om.role, om.joined_at
           FROM users u
           JOIN org_members om ON om.user_id = u.id
          WHERE om.org_id = ?
            AND om.role IN ('admin', 'owner')
            AND om.is_active = 1
          ORDER BY om.role DESC, u.username ASC"
    );
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();

    jsonOk([
        'org_id' => $orgId,
        'admins' => array_map(fn($r) => [
            'id'           => (int)    $r['id'],
            'username'     => (string) $r['username'],
            'email'        => (string) $r['email'],
            'full_name'    => (string) ($r['full_name'] ?? ''),
            'company_name' => (string) ($r['company_name'] ?? ''),
            'is_active'    => (bool)   $r['is_active'],
            'role'         => (string) $r['role'],
            'joined_at'    => $r['joined_at'],
        ], $rows),
    ]);
}

function _assignAdmin(int $orgId, array $auth, PDO $pdo): void
{
    $body   = parseBody();
    $userId = (int) ($body['user_id'] ?? 0);
    $role   = strField($body['role'] ?? 'admin', 20);

    if ($userId <= 0) {
        jsonError('user_id is required');
    }
    if (!in_array($role, ['admin', 'owner'], true)) {
        jsonError('role must be admin or owner');
    }

    // Verify user exists and is active
    $userChk = $pdo->prepare('SELECT id, is_active FROM users WHERE id = ? LIMIT 1');
    $userChk->execute([$userId]);
    $user = $userChk->fetch();
    if (!$user) {
        jsonError('User not found', 404);
    }
    if (!(int) $user['is_active']) {
        jsonError('Cannot assign an inactive user', 422);
    }

    // Prevent owner from removing themselves from their own org via this endpoint
    if ($userId === $auth['user_id']) {
        jsonError('Cannot assign yourself via this endpoint', 403);
    }

    try {
        $pdo->prepare(
            'INSERT INTO org_members (user_id, org_id, role, is_active)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE role = VALUES(role), is_active = 1'
        )->execute([$userId, $orgId, $role]);
    } catch (\PDOException $e) {
        error_log('_assignAdmin error: ' . $e->getMessage());
        jsonError('Assignment failed', 500);
    }

    jsonOk([
        'user_id' => $userId,
        'org_id'  => $orgId,
        'role'    => $role,
        'assigned' => true,
    ]);
}

function _removeAdmin(int $orgId, int $userId, array $auth, PDO $pdo): void
{
    // Cannot remove yourself
    if ($userId === $auth['user_id']) {
        jsonError('Cannot remove yourself from a business unit', 403);
    }

    $del = $pdo->prepare(
        "UPDATE org_members SET is_active = 0
          WHERE org_id = ? AND user_id = ? AND role IN ('admin','owner')"
    );
    $del->execute([$orgId, $userId]);

    if ($del->rowCount() === 0) {
        jsonError('Admin membership not found', 404);
    }

    jsonOk(['user_id' => $userId, 'org_id' => $orgId, 'removed' => true]);
}

// ── Helpers ───────────────────────────────────────────────────

/**
 * Sanitize a slug: lowercase, alphanumeric + hyphens, max 60 chars.
 * Returns '' if the result would be invalid.
 */
function _sanitizeSlug(string $raw): string
{
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = mb_substr($slug, 0, 60);
    return $slug;
}
