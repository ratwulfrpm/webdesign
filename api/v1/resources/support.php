<?php
/**
 * api/v1/resources/support.php — Support-role specific resource handler.
 *
 * Routes:
 *   POST /api/v1/support/active-business   Switch the support user's active
 *                                           business unit within their session.
 *
 * RBAC: support only (admin and owner change org via the org-picker flow).
 *
 * Security:
 *   - org_id is validated against the caller's own org_members rows (IDOR guard).
 *   - Session is NOT regenerated here because we only change the active BU
 *     within an already fully-authenticated session; full session creation
 *     (with regeneration) was done at login time.
 */

require_once __DIR__ . '/../../../includes/org_scope.php';
require_once __DIR__ . '/../../../includes/audit.php';

function handleSupport(string $method, string $sub): void
{
    if ($method === 'POST' && $sub === 'active-business') {
        _switchActiveBusinessUnit();
        return;
    }

    jsonError('Not Found', 404);
}

function _switchActiveBusinessUnit(): void
{
    $auth = requireApiAuth(['support']);
    $pdo  = getDB();

    $body  = parseBody();
    $orgId = intParam($body['business_unit_id'] ?? 0, 'business_unit_id');

    if ($orgId <= 0) {
        jsonError('business_unit_id is required', 422);
    }

    // Verify the caller is actually a member of the requested org
    $allowedOrgIds = loadAccessibleOrgIds($pdo, $auth['user_id'], $auth['role']);
    if (!orgScopeContainsOrgId($allowedOrgIds, $orgId)) {
        auditLog('support_bu_switch_denied', 'warning', null, (int) $auth['user_id'], [
            'requested_org_id' => $orgId,
            'allowed_org_ids'  => $allowedOrgIds,
        ]);
        jsonError('Business unit not accessible', 403);
    }

    // Fetch the org name for the session
    $stmt = $pdo->prepare('SELECT name, slug FROM organizations WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$orgId]);
    $org = $stmt->fetch();

    if (!$org) {
        jsonError('Business unit not found or inactive', 404);
    }

    // Update session active BU (no session_regenerate_id — session already exists)
    $previousOrgId = (int) $_SESSION['org_id'];
    $_SESSION['org_id']   = $orgId;
    $_SESSION['org_slug'] = (string) $org['slug'];
    $_SESSION['org_name'] = (string) $org['name'];

    auditLog('support_bu_switched', 'info', null, (int) $auth['user_id'], [
        'previous_org_id' => $previousOrgId,
        'new_org_id'      => $orgId,
        'new_org_name'    => $org['name'],
    ]);

    jsonOk([
        'active_business_unit' => [
            'id'   => $orgId,
            'name' => (string) $org['name'],
            'slug' => (string) $org['slug'],
        ],
        'message' => 'Active business unit updated.',
    ]);
}
