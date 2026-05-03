<?php
/**
 * api/v1/resources/users.php — Scoped user administration resource handler.
 *
 * Routes:
 *   GET   /api/v1/users       list accessible users
 *   GET   /api/v1/users/:id   accessible user detail
 *   PATCH /api/v1/users/:id   perform activate|deactivate|unlock action
 *
 * RBAC: admin/owner only.
 */

require_once __DIR__ . '/../../../includes/org_scope.php';

function handleUsers(string $method, ?int $id): void
{
    $auth = requireAuth(['admin', 'owner']);
    $pdo  = getDB();

    match (true) {
        $method === 'GET'   && $id === null => _listScopedUsers($auth, $pdo),
        $method === 'GET'   && $id !== null => _getScopedUser($id, $auth, $pdo),
        $method === 'PATCH' && $id !== null => _patchScopedUser($id, $auth, $pdo),
        default => jsonError('Method Not Allowed', 405),
    };
}

function _userAllowedOrgIds(array $auth, PDO $pdo): array
{
    return loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
}

function _userRoleFilter(string $viewerRole, string $requestedRole = ''): array
{
    if ($viewerRole === 'admin') {
        return ['supplier'];
    }

    if ($requestedRole !== '' && in_array($requestedRole, ['owner', 'admin', 'supplier'], true)) {
        return [$requestedRole];
    }

    return [];
}

function _decodeBusinessUnits(?string $idsCsv, ?string $namesCsv): array
{
    $ids = $idsCsv !== null && $idsCsv !== '' ? explode(',', $idsCsv) : [];
    $names = $namesCsv !== null && $namesCsv !== '' ? explode('||', $namesCsv) : [];
    $units = [];

    $count = min(count($ids), count($names));
    for ($index = 0; $index < $count; $index++) {
        $units[] = [
            'id' => (int) $ids[$index],
            'name' => (string) $names[$index],
        ];
    }

    return $units;
}

function _mapScopedUserRow(array $row): array
{
    $businessUnits = _decodeBusinessUnits($row['org_ids_csv'] ?? null, $row['org_names_csv'] ?? null);
    $roles = array_values(array_filter(explode(',', (string) ($row['roles_csv'] ?? ''))));
    $primaryRole = count($roles) === 1 ? $roles[0] : ($roles[0] ?? 'supplier');

    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'email' => (string) $row['email'],
        'is_active' => (bool) $row['is_active'],
        'first_login' => (bool) $row['first_login'],
        'failed_attempts' => (int) $row['failed_attempts'],
        'locked_until' => $row['locked_until'],
        'created_at' => $row['created_at'],
        'role' => $primaryRole,
        'roles' => $roles,
        'business_unit_name' => implode(', ', array_column($businessUnits, 'name')),
        'business_units' => $businessUnits,
    ];
}

function _loadScopedUserRow(int $id, array $auth, PDO $pdo): ?array
{
    $allowedOrgIds = _userAllowedOrgIds($auth, $pdo);
    if (empty($allowedOrgIds)) {
        return null;
    }

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $roles = _userRoleFilter($auth['role']);
    $params = [$id];
    $where = ['u.id = ?', 'om.is_active = 1', "om.org_id IN ({$orgPlaceholders})"];
    $params = array_merge($params, $allowedOrgIds);

    if (!empty($roles)) {
        $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
        $where[] = "om.role IN ({$rolePlaceholders})";
        $params = array_merge($params, $roles);
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.is_active, u.first_login,
                u.failed_attempts, u.locked_until, u.created_at,
                GROUP_CONCAT(DISTINCT om.role ORDER BY om.role SEPARATOR ",") AS roles_csv,
                GROUP_CONCAT(DISTINCT o.id ORDER BY o.name SEPARATOR ",") AS org_ids_csv,
                GROUP_CONCAT(DISTINCT o.name ORDER BY o.name SEPARATOR "||") AS org_names_csv
           FROM users u
           JOIN org_members om ON om.user_id = u.id
           JOIN organizations o ON o.id = om.org_id
          WHERE ' . implode(' AND ', $where) . '
          GROUP BY u.id, u.username, u.email, u.is_active, u.first_login,
                   u.failed_attempts, u.locked_until, u.created_at
          LIMIT 1'
    );
    $stmt->execute($params);

    $row = $stmt->fetch();
    return $row ?: null;
}

function _listScopedUsers(array $auth, PDO $pdo): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $status = strField($_GET['status'] ?? '', 20);
    $filterOrgId = (int) ($_GET['org_id'] ?? 0);
    $requestedRole = strField($_GET['role'] ?? '', 20);

    $allowedOrgIds = _userAllowedOrgIds($auth, $pdo);
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

    $roles = _userRoleFilter($auth['role'], $requestedRole);
    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $where = ['om.is_active = 1', "om.org_id IN ({$orgPlaceholders})"];
    $params = $allowedOrgIds;

    if ($filterOrgId > 0) {
        $where[] = 'om.org_id = ?';
        $params[] = $filterOrgId;
    }

    if ($status === 'active') {
        $where[] = 'u.is_active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'u.is_active = 0';
    }

    if (!empty($roles)) {
        $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
        $where[] = "om.role IN ({$rolePlaceholders})";
        $params = array_merge($params, $roles);
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT u.id)
           FROM users u
           JOIN org_members om ON om.user_id = u.id
          WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.email, u.is_active, u.first_login,
                u.failed_attempts, u.locked_until, u.created_at,
                GROUP_CONCAT(DISTINCT om.role ORDER BY om.role SEPARATOR ',') AS roles_csv,
                GROUP_CONCAT(DISTINCT o.id ORDER BY o.name SEPARATOR ',') AS org_ids_csv,
                GROUP_CONCAT(DISTINCT o.name ORDER BY o.name SEPARATOR '||') AS org_names_csv
           FROM users u
           JOIN org_members om ON om.user_id = u.id
           JOIN organizations o ON o.id = om.org_id
          WHERE {$whereSql}
          GROUP BY u.id, u.username, u.email, u.is_active, u.first_login,
                   u.failed_attempts, u.locked_until, u.created_at
          ORDER BY u.username ASC
          LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $items = array_map('_mapScopedUserRow', $stmt->fetchAll());

    jsonOk([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => (int) ceil($total / max(1, $perPage)),
        'per_page' => $perPage,
    ]);
}

function _getScopedUser(int $id, array $auth, PDO $pdo): void
{
    $row = _loadScopedUserRow($id, $auth, $pdo);
    if (!$row) {
        jsonError('User not found', 404);
    }

    jsonOk(['user' => _mapScopedUserRow($row)]);
}

function _patchScopedUser(int $id, array $auth, PDO $pdo): void
{
    $row = _loadScopedUserRow($id, $auth, $pdo);
    if (!$row) {
        jsonError('User not found', 404);
    }

    $body = parseBody();
    $action = strField($body['action'] ?? '', 20);
    if (!in_array($action, ['activate', 'deactivate', 'unlock'], true)) {
        jsonError('Invalid action. Allowed: activate, deactivate, unlock');
    }

    if ($action === 'deactivate' && $id === (int) $auth['user_id']) {
        jsonError('You cannot deactivate your own account', 422);
    }

    match ($action) {
        'activate' => $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$id]),
        'deactivate' => $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]),
        'unlock' => $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$id]),
    };

    $updated = _loadScopedUserRow($id, $auth, $pdo);
    jsonOk([
        'action' => $action,
        'user' => _mapScopedUserRow($updated ?: $row),
    ]);
}