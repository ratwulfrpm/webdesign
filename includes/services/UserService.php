<?php
/**
 * includes/services/UserService.php — Shared user-management service.
 *
 * Provides utility methods used by both admin/users.php and owner/users.php,
 * eliminating duplicated query and decision logic between the two files.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/services/UserService.php';
 *   $users = UserService::getUsersForActor($pdo, $actor, $scopedOrgIds);
 *   if (UserService::canManageUser($actor, $targetRole)) { ... }
 *   $actions = UserService::getAvailableActions($actor, $targetRole);
 */
class UserService
{
    // ── Query helpers ─────────────────────────────────────────

    /**
     * Fetch paginated users visible to the given actor.
     *
     * Owner sees ALL members across ALL organisations.
     * Admin/Support see only 'supplier' members within their scoped org IDs.
     *
     * @param  PDO    $pdo
     * @param  string $actor        'owner' | 'admin' | 'support'
     * @param  int[]  $scopedOrgIds Org IDs the actor has access to
     * @param  int    $page         1-based page number
     * @param  int    $perPage      Rows per page (default 50)
     * @return array{users: array, total: int, pages: int, page: int}
     */
    public static function getUsersForActor(
        PDO    $pdo,
        string $actor,
        array  $scopedOrgIds,
        int    $page    = 1,
        int    $perPage = 50
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        if ($actor === 'owner') {
            $cntStmt = $pdo->query(
                'SELECT COUNT(DISTINCT u.id)
                   FROM users u
                   JOIN org_members om ON u.id = om.user_id
                  WHERE om.is_active = 1'
            );
            $total  = (int) $cntStmt->fetchColumn();
            $pages  = max(1, (int) ceil($total / $perPage));
            $page   = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            $uStmt = $pdo->prepare(
                'SELECT u.id, u.username, u.email, u.is_active,
                        u.first_login, u.failed_attempts, u.locked_until,
                        GROUP_CONCAT(DISTINCT om.role
                            ORDER BY FIELD(om.role,"owner","admin","support","supplier","user")
                            SEPARATOR ",") AS roles_csv,
                        GROUP_CONCAT(DISTINCT o.name ORDER BY o.name SEPARATOR ", ") AS org_names
                   FROM users u
                   JOIN org_members om ON u.id = om.user_id
                   JOIN organizations o ON o.id = om.org_id
                  WHERE om.is_active = 1
                  GROUP BY u.id, u.username, u.email, u.is_active,
                           u.first_login, u.failed_attempts, u.locked_until
                  ORDER BY u.username ASC
                  LIMIT ? OFFSET ?'
            );
            $uStmt->execute([$perPage, $offset]);

        } else {
            // admin / support: supplier role only, scoped to accessible orgs
            if (empty($scopedOrgIds)) {
                return ['users' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
            }

            $ph = implode(',', array_fill(0, count($scopedOrgIds), '?'));

            $cntStmt = $pdo->prepare(
                "SELECT COUNT(DISTINCT u.id)
                   FROM users u
                   JOIN org_members om ON u.id = om.user_id
                  WHERE om.org_id IN ({$ph})
                    AND om.role = 'supplier'
                    AND om.is_active = 1"
            );
            $cntStmt->execute($scopedOrgIds);
            $total  = (int) $cntStmt->fetchColumn();
            $pages  = max(1, (int) ceil($total / $perPage));
            $page   = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            $uStmt = $pdo->prepare(
                "SELECT u.id, u.username, u.email, u.is_active,
                        u.first_login, u.failed_attempts, u.locked_until,
                        u.created_at, 'supplier' AS role,
                        GROUP_CONCAT(DISTINCT o.name ORDER BY o.name SEPARATOR ', ') AS org_names
                   FROM users u
                   JOIN org_members om ON u.id = om.user_id
                   JOIN organizations o ON o.id = om.org_id
                  WHERE om.org_id IN ({$ph})
                    AND om.role = 'supplier'
                    AND om.is_active = 1
                  GROUP BY u.id, u.username, u.email, u.is_active,
                           u.first_login, u.failed_attempts, u.locked_until, u.created_at
                  ORDER BY u.username ASC
                  LIMIT {$perPage} OFFSET {$offset}"
            );
            $uStmt->execute($scopedOrgIds);
        }

        return [
            'users' => $uStmt->fetchAll(),
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ];
    }

    // ── Permission helpers ────────────────────────────────────

    /**
     * True if the actor role is permitted to perform management actions
     * (activate/deactivate/unlock/reset) on a user with $targetRole.
     *
     * Owner  : can manage admin, support, supplier (not other owners)
     * Admin  : can manage support, supplier (not owner, admin)
     * Support: read-only — cannot manage any user
     */
    public static function canManageUser(string $actor, string $targetRole): bool
    {
        return match ($actor) {
            'owner'   => in_array($targetRole, ['admin', 'support', 'supplier'], true),
            'admin'   => in_array($targetRole, ['support', 'supplier'], true),
            default   => false,
        };
    }

    /**
     * Return the set of action identifiers available to $actor against a
     * user whose primary role is $targetRole.
     *
     * @return string[]  subset of: 'activate', 'deactivate', 'unlock', 'reset_password', 'change_role'
     */
    public static function getAvailableActions(string $actor, string $targetRole): array
    {
        if (!self::canManageUser($actor, $targetRole)) {
            return [];
        }

        $actions = ['activate', 'deactivate', 'unlock', 'reset_password'];

        // Only owners may change roles
        if ($actor === 'owner') {
            $actions[] = 'change_role';
        }

        return $actions;
    }
}
