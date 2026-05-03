<?php
/**
 * api/v1/resources/me.php — Current-user identity & scope endpoint.
 *
 * Routes:
 *   GET /api/v1/me   Returns the authenticated user's role, assigned
 *                    business units (from org_members), and the currently
 *                    active business unit (org_id in session).
 *
 * RBAC: all authenticated roles (owner, admin, support, supplier).
 */

require_once __DIR__ . '/../../../includes/org_scope.php';

function handleMe(string $method): void
{
    if ($method !== 'GET') {
        jsonError('Method Not Allowed', 405);
    }

    $auth = requireAuth(['owner', 'admin', 'support', 'supplier']);
    $pdo  = getDB();

    $userId  = (int) $auth['user_id'];
    $role    = (string) $auth['role'];
    $orgId   = (int) $auth['org_id'];
    // username and org_name come from the full session (requireAuth only returns user_id/role/org_id)
    $username = (string) ($_SESSION['username'] ?? '');
    $orgName  = (string) ($_SESSION['org_name'] ?? '');

    // Fetch all BUs the user is assigned to (all active org_members rows)
    $stmt = $pdo->prepare(
        'SELECT o.id, o.name, om.role AS bu_role
           FROM org_members om
           JOIN organizations o ON o.id = om.org_id
          WHERE om.user_id = ?
            AND om.is_active = 1
            AND o.is_active = 1
          ORDER BY o.name ASC'
    );
    $stmt->execute([$userId]);
    $buRows = $stmt->fetchAll();

    $businessUnits = array_map(fn($r) => [
        'id'      => (int) $r['id'],
        'name'    => (string) $r['name'],
        'role'    => (string) $r['bu_role'],
        'active'  => ((int) $r['id'] === $orgId),
    ], $buRows);

    jsonOk([
        'user_id'                => $userId,
        'username'               => $username,
        'role'                   => $role,
        'active_business_unit'   => [
            'id'   => $orgId,
            'name' => $orgName,
        ],
        'business_units'         => $businessUnits,
        'business_unit_count'    => count($businessUnits),
    ]);
}
