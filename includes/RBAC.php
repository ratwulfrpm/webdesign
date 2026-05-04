<?php
/**
 * includes/RBAC.php — Central Role-Based Access Control layer.
 *
 * Defines a canonical permission matrix for all roles.  Complements the
 * per-page role guards (requireAuth / requireRole) with resource-level
 * checks and role-creation hierarchy enforcement.
 *
 * Role hierarchy (numeric rank — same as ROLE_HIERARCHY constant in auth.php):
 *   owner = 4  >  admin = 3  >  support = 2  >  supplier = 1  >  user/link = 0
 *
 * Owner/Admin parity rule:
 *   Every permission granted to admin is also granted to owner (owner ≥ admin).
 *   Owner additionally holds permissions restricted to rank 4.
 *   This file NEVER grants admin a permission that is denied to owner.
 */

require_once __DIR__ . '/auth.php';   // loads Auth class + procedural helpers

final class RBAC
{
    /** Numeric hierarchy — mirrors ROLE_HIERARCHY in auth.php. */
    private const HIERARCHY = [
        'owner'    => 4,
        'admin'    => 3,
        'support'  => 2,
        'supplier' => 1,
        'user'     => 0,
    ];

    /**
     * Permission matrix.
     *
     * Key   = 'domain.action'
     * Value = minimum role rank required (inclusive).
     *
     * Owner always satisfies any permission because rank 4 ≥ everything.
     * Admin (rank 3) satisfies all entries with required rank ≤ 3.
     * Support (rank 2) satisfies entries with required rank ≤ 2.
     * Supplier (rank 1) satisfies entries with required rank = 1.
     */
    private const PERMISSIONS = [
        // ── User management ──────────────────────────────────
        'users.list'              => 2,   // support+ (support sees suppliers in their BU only)
        'users.create'            => 3,   // admin+
        'users.activate'          => 3,   // admin+
        'users.deactivate'        => 3,   // admin+
        'users.unlock'            => 3,   // admin+
        'users.change_role'       => 4,   // owner only

        // ── Invitations ───────────────────────────────────────
        'invitations.list'        => 2,   // support+ (read-only for support)
        'invitations.create'      => 3,   // admin+
        'invitations.revoke'      => 3,   // admin+

        // ── Products ──────────────────────────────────────────
        'products.list'           => 2,   // support+
        'products.view'           => 1,   // supplier+
        'products.create'         => 1,   // supplier (own) or admin+
        'products.edit'           => 1,   // supplier (own) or admin+
        'products.delete'         => 3,   // admin+
        'products.admin_code'     => 3,   // admin+ (internal product code)

        // ── Assignments / Quotes ──────────────────────────────
        'assignments.list'        => 2,   // support+
        'assignments.create'      => 2,   // support+
        'assignments.view'        => 2,   // support+
        'assignments.revoke'      => 2,   // support+
        'assignments.clone'       => 2,   // support+
        'assignments.delete'      => 3,   // admin+

        // ── Business units (organizations) ───────────────────
        'business_units.list'     => 4,   // owner only
        'business_units.create'   => 4,   // owner only
        'business_units.edit'     => 4,   // owner only
        'business_units.admins'   => 4,   // owner only

        // ── Contracts ─────────────────────────────────────────
        'contracts.list'          => 2,   // support+
        'contracts.create'        => 1,   // supplier+
        'contracts.review'        => 3,   // admin+

        // ── Contract validity requests ────────────────────────
        'validity_requests.create' => 1,  // supplier
        'validity_requests.review' => 3,  // admin+

        // ── Supplier self-scope ───────────────────────────────
        'supplier.profile'        => 1,   // supplier+
        'supplier.products'       => 1,   // supplier+
        'supplier.contracts'      => 1,   // supplier+
        'supplier.documents'      => 1,   // supplier+
    ];

    // ── Public API ────────────────────────────────────────────

    /**
     * Returns true if the currently authenticated user has permission for $action.
     *
     * @param  string       $action    e.g. 'users.list', 'products.create'
     * @param  string|null  $resource  Reserved for future resource-instance checks.
     * @return bool
     */
    public static function can(string $action, ?string $resource = null): bool
    {
        $role = Auth::role();
        $rank = self::HIERARCHY[$role] ?? -1;

        // Attempt compound key first ('action:resource'), fall back to 'action'.
        $key      = ($resource !== null && isset(self::PERMISSIONS["{$action}:{$resource}"]))
                    ? "{$action}:{$resource}"
                    : $action;
        $required = self::PERMISSIONS[$key] ?? PHP_INT_MAX;

        return $rank >= $required;
    }

    /**
     * Guard: abort if the current user lacks $permission.
     *
     * - In API context (JSON Accept header): emits HTTP 403 JSON and exits.
     * - In web context: redirects to the role's home page (HTTP 403).
     *
     * @param string $permission  e.g. 'users.create'
     */
    public static function requirePermission(string $permission): void
    {
        if (self::can($permission)) {
            return;
        }

        if (self::_isApiContext()) {
            http_response_code(403);
            echo json_encode(
                ['success' => false, 'error' => 'Forbidden'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        http_response_code(403);
        redirectToHome();
        exit;
    }

    /**
     * Returns true if $creatorRole is allowed to create a user with $targetRole.
     *
     * Hierarchy enforcement:
     *   owner   → may create admin, support, supplier
     *   admin   → may create support, supplier  (NOT admin, NOT owner)
     *   support → may NOT create any role
     *   others  → may NOT create any role
     *
     * Excepción Admin/Owner:
     *   Admin cannot create other admins — this preserves owner exclusivity
     *   over the admin role.  Owner retains the right to create admin.
     */
    public static function canCreateRole(string $creatorRole, string $targetRole): bool
    {
        return match ($creatorRole) {
            'owner' => in_array($targetRole, ['admin', 'support', 'supplier'], true),
            'admin' => in_array($targetRole, ['support', 'supplier'], true),
            default => false,
        };
    }

    /**
     * Assert that $creatorRole can create $targetRole, or abort with 403.
     *
     * @param string $creatorRole  Role of the user performing the creation.
     * @param string $targetRole   Role being assigned to the new user.
     */
    public static function assertRoleHierarchy(string $creatorRole, string $targetRole): void
    {
        if (self::canCreateRole($creatorRole, $targetRole)) {
            return;
        }

        if (self::_isApiContext()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => "Role '{$creatorRole}' cannot create role '{$targetRole}'",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(403);
        redirectToHome();
        exit;
    }

    // ── Private helpers ───────────────────────────────────────

    /** True when the current request expects a JSON response. */
    private static function _isApiContext(): bool
    {
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        $ct     = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        return str_contains($accept, 'application/json')
            || str_contains($ct, 'application/json');
    }
}
