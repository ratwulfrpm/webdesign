<?php
/**
 * includes/TenantScope.php — Central tenant / business-unit scope resolver.
 *
 * Maps the current user's role to the correct business-unit scope and
 * provides helpers to build scoped DB queries and validate BU access.
 *
 * Role scoping rules:
 *
 *   owner   → global access, all organizations, no org_id filter applied.
 *             businessUnitIds() returns [] to signal "no restriction".
 *
 *   admin   → all organizations the user is assigned to (org_members rows).
 *             businessUnitIds() returns the full list of assigned org IDs.
 *             org_id is NOT required in session (global admin session).
 *
 *   support → only the active_business_unit stored in session as org_id.
 *             org_id is validated against live org_members on every request.
 *             businessUnitIds() returns [active_org_id] (single element).
 *
 *   supplier→ own business unit from session org_id.
 *             businessUnitIds() returns [org_id].
 *
 *   user/link → no business unit — token-only quote access only.
 *
 * Owner/Admin parity:
 *   Owner retains all admin capabilities and extends them globally.
 *   Admin is scoped to assigned BUs; owner is never scoped.
 *   Neither role uses active_business_unit — no BU selector shown.
 */

require_once __DIR__ . '/auth.php';       // Auth class + session helpers
require_once __DIR__ . '/org_scope.php';  // loadAccessibleOrganizations, loadAccessibleOrgIds

final class TenantScope
{
    // ── Business unit resolution ──────────────────────────────

    /**
     * Returns accessible organizations for the current user.
     * Each element: ['id' => int, 'name' => string].
     *
     * Owner : all active organizations in the system.
     * Admin : all organizations the user is assigned to.
     * Support: only the active organization (org_id in session).
     * Supplier: only their own organization.
     *
     * @return list<array{id:int, name:string}>
     */
    public static function businessUnitsForCurrentUser(): array
    {
        $pdo    = getDB();
        $userId = Auth::id();
        $role   = Auth::role();
        $orgId  = (int) ($_SESSION['org_id'] ?? 0);

        $orgs = loadAccessibleOrganizations($pdo, $userId, $role);

        if ($role === 'support') {
            // Support sees ONLY their active BU.
            $orgs = array_values(array_filter(
                $orgs,
                static fn($o) => (int) ($o['id'] ?? 0) === $orgId
            ));
        }

        return $orgs;
    }

    /**
     * Returns the array of integer org IDs the current user may access.
     *
     * Owner   → returns [] (empty = "no restriction"; queries must NOT filter).
     * Admin   → returns all assigned org IDs.
     * Support → returns [active_org_id] (validated against org_members).
     * Supplier→ returns [org_id] from session.
     *
     * Callers must check for empty array for owner:
     *   if (Auth::role() === 'owner') { // global query, no org filter }
     *
     * @return int[]
     */
    public static function businessUnitIds(): array
    {
        $pdo    = getDB();
        $userId = Auth::id();
        $role   = Auth::role();

        // Owner: unrestricted — callers should handle the empty-array signal.
        if ($role === 'owner') {
            return [];
        }

        // Support: only the active BU, validated against live org_members.
        if ($role === 'support') {
            $orgId = (int) ($_SESSION['org_id'] ?? 0);
            $all   = loadAccessibleOrgIds($pdo, $userId, $role);
            return in_array($orgId, $all, true) ? [$orgId] : [];
        }

        // Admin, supplier: all assigned orgs.
        return loadAccessibleOrgIds($pdo, $userId, $role);
    }

    /**
     * Returns true if the current user may access $businessId.
     *
     * Owner: always true (global).
     * Others: $businessId must be in their assigned BU list.
     */
    public static function canAccessBusiness(int $businessId): bool
    {
        if (Auth::role() === 'owner') {
            return true;
        }
        return in_array($businessId, self::businessUnitIds(), true);
    }

    /**
     * Returns the active business unit ID for support.
     * For owner/admin, returns 0 (not applicable — no BU selection).
     * Validates the active org_id against live org_members; falls back to
     * first assigned BU if the stored org_id is no longer valid.
     */
    public static function activeBusinessForSupport(): int
    {
        $role = Auth::role();

        if ($role !== 'support') {
            return 0;   // owner/admin: not applicable
        }

        $orgId  = (int) ($_SESSION['org_id'] ?? 0);
        $pdo    = getDB();
        $userId = Auth::id();
        $all    = loadAccessibleOrgIds($pdo, $userId, $role);

        if (in_array($orgId, $all, true)) {
            return $orgId;
        }

        // Auto-correct: fall back to first assigned BU.
        if (!empty($all)) {
            $_SESSION['org_id'] = $all[0];
            return $all[0];
        }

        return 0;
    }

    /**
     * Appends a tenant-scope WHERE clause to $baseQuery.
     *
     * Returns an array with:
     *   'query'  => string  (the $baseQuery with appended clause)
     *   'params' => array   (named PDO params to merge into execute())
     *
     * Owner    : no clause appended (global access).
     * Support  : appends "{$col} = :_ts_org_id" (single org).
     * Admin/Sup: appends "{$col} IN (:_ts_org_0, :_ts_org_1, ...)"
     *
     * Usage example:
     *   [$sql, $params] = array_values(TenantScope::applyToQuery(
     *       'SELECT * FROM products WHERE 1=1', 'p.org_id'
     *   ));
     *   $stmt = $pdo->prepare($sql);
     *   $stmt->execute($params);
     *
     * @param  string  $baseQuery  SQL ending in "WHERE 1=1" or similar.
     * @param  string  $col        Column reference, e.g. "p.org_id" or "org_id".
     * @return array{query: string, params: array<string, mixed>}
     */
    public static function applyToQuery(string $baseQuery, string $col = 'org_id'): array
    {
        if (Auth::role() === 'owner') {
            return ['query' => $baseQuery, 'params' => []];
        }

        $ids = self::businessUnitIds();

        if (empty($ids)) {
            // No accessible BUs → return impossible condition to avoid data leaks.
            return ['query' => $baseQuery . ' AND 1=0', 'params' => []];
        }

        if (count($ids) === 1) {
            return [
                'query'  => $baseQuery . " AND {$col} = :_ts_org_id",
                'params' => [':_ts_org_id' => $ids[0]],
            ];
        }

        // Multiple IDs — expand to named placeholders for PDO.
        $placeholders = [];
        $params       = [];
        foreach ($ids as $i => $id) {
            $key            = ":_ts_org_{$i}";
            $placeholders[] = $key;
            $params[$key]   = $id;
        }

        return [
            'query'  => $baseQuery . ' AND ' . $col . ' IN (' . implode(',', $placeholders) . ')',
            'params' => $params,
        ];
    }

    /**
     * Guard: abort with 403 if the current user cannot access $businessId.
     *
     * - API context (JSON): emits HTTP 403 JSON and exits.
     * - Web context: redirects to role home (HTTP 403).
     */
    public static function requireBusinessAccess(int $businessId): void
    {
        if (self::canAccessBusiness($businessId)) {
            return;
        }

        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        $ct     = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($accept, 'application/json') || str_contains($ct, 'application/json')) {
            http_response_code(403);
            echo json_encode(
                ['success' => false, 'error' => 'Forbidden: business unit out of scope'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        http_response_code(403);
        redirectToHome();
        exit;
    }
}
