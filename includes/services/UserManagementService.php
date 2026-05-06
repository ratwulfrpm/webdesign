<?php
/**
 * includes/services/UserManagementService.php — Central user management service.
 *
 * Eliminates duplicated action-handling and query logic between
 * admin/users.php and owner/users.php.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  OWNER / ADMIN PARITY RULE                                          │
 * │  Everything admin can do, owner can also do with global scope.      │
 * │  Owner additionally: canChangeRole, global invitation scope.        │
 * │                                                                     │
 * │  BUSINESS UNIT CREATION: OWNER-ONLY                                 │
 * │  Lives in owner/business_units.php — NOT exposed here.             │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Role rules enforced:
 *   owner   — global scope, may change roles, may reset admin/support/supplier
 *   admin   — scoped to assigned BUs, supplier list only, cannot change roles
 *   support — read-only for most ops; may activate/deactivate/unlock in scoped BU
 *   supplier/user — no access (blocked by entrypoint requireRole before this runs)
 *
 * Usage:
 *   require_once __DIR__ . '/UserManagementService.php';
 *   $result = UserManagementService::handleAction($pdo, $actor, $action, $_POST);
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../audit.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../Validator.php';
require_once __DIR__ . '/../org_scope.php';
require_once __DIR__ . '/../contract_validity_admin.php';
require_once __DIR__ . '/../lang.php';

class UserManagementService
{
    // ── Role constants ─────────────────────────────────────────

    /** Roles permitted to call this service at all. */
    private const ALLOWED_ACTOR_ROLES = ['owner', 'admin', 'support'];

    /** Target roles admin may manage (list/unlock/deactivate/activate). */
    private const ADMIN_MANAGEABLE_ROLES = ['supplier', 'support'];

    /** Target roles owner may manage (reset_password excludes other owners). */
    private const OWNER_MANAGEABLE_ROLES = ['admin', 'support', 'supplier'];

    // ── Public RBAC helpers ────────────────────────────────────

    /**
     * True if $actor may perform standard management actions
     * (activate/deactivate/unlock) on a user whose primary role is $targetRole.
     *
     * Owner  : admin | support | supplier
     * Admin  : support | supplier
     * Support: no management rights (read-only)
     */
    public static function canManageUser(string $actor, string $targetRole): bool
    {
        return match ($actor) {
            'owner'  => in_array($targetRole, self::OWNER_MANAGEABLE_ROLES, true),
            'admin'  => in_array($targetRole, self::ADMIN_MANAGEABLE_ROLES, true),
            default  => false,
        };
    }

    /**
     * True if $actor may reset the password of a user with $targetRole.
     * Owner cannot reset another owner. Support cannot reset anyone.
     */
    public static function canResetPassword(string $actor, string $targetRole): bool
    {
        return match ($actor) {
            'owner'  => in_array($targetRole, self::OWNER_MANAGEABLE_ROLES, true),
            'admin'  => in_array($targetRole, self::ADMIN_MANAGEABLE_ROLES, true),
            default  => false,
        };
    }

    /** True only for owner. Admin, support, others: false. */
    public static function canChangeRole(string $actor): bool
    {
        return $actor === 'owner';
    }

    /**
     * Returns the set of action identifiers available to $actor against a
     * user whose primary role is $targetRole.
     *
     * @return string[]  subset of: 'activate', 'deactivate', 'unlock',
     *                   'reset_password', 'change_role'
     */
    public static function getAvailableActions(string $actor, string $targetRole): array
    {
        if (!self::canManageUser($actor, $targetRole)) {
            return [];
        }

        $actions = ['activate', 'deactivate', 'unlock', 'reset_password'];

        if ($actor === 'owner') {
            $actions[] = 'change_role';
        }

        return $actions;
    }

    // ── Query helpers ──────────────────────────────────────────

    /**
     * Fetch paginated users visible to the given actor.
     *
     * Owner   → ALL members across ALL organisations (roles_csv multi-role).
     * Admin / Support → 'supplier' role only, scoped to accessible org IDs.
     *
     * @param  PDO    $pdo
     * @param  string $actor        'owner' | 'admin' | 'support'
     * @param  int[]  $scopedOrgIds Org IDs actor has access to (empty for owner)
     * @param  int    $page         1-based page number
     * @param  int    $perPage      Rows per page (default 50)
     * @return array{users:array, total:int, pages:int, page:int}
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

    /**
     * Fetch invitations visible to actor within accessible org IDs.
     *
     * @param  PDO    $pdo
     * @param  string $actor           Role string (used for future extension)
     * @param  int[]  $accessibleOrgIds Org IDs actor may see
     * @return array
     */
    public static function getInvitationsForActor(PDO $pdo, string $actor, array $accessibleOrgIds): array
    {
        if (empty($accessibleOrgIds)) {
            return [];
        }

        $ph   = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT si.id, si.org_id, o.name AS org_name,
                    si.extra_org_ids,
                    si.role, si.invited_email, si.status,
                    si.expires_at, si.created_at,
                    cb.username AS created_by_username,
                    ub.username AS used_by_username
               FROM supplier_invitations si
               JOIN organizations o  ON o.id  = si.org_id
               JOIN users cb         ON cb.id = si.created_by_user_id
               LEFT JOIN users ub    ON ub.id = si.used_by_user_id
              WHERE si.org_id IN ({$ph})
              ORDER BY si.created_at DESC
              LIMIT 200"
        );
        $stmt->execute($accessibleOrgIds);

        return $stmt->fetchAll();
    }

    // ── Central action dispatcher ──────────────────────────────

    /**
     * Handle a POST action for user management.
     *
     * CSRF must be validated by the caller before invoking this method.
     *
     * $actor array keys:
     *   role              string  — Actor's role ('owner'|'admin'|'support')
     *   user_id           int     — Actor's user ID
     *   scoped_org_ids    int[]   — Org IDs scoped to actor ([] for owner = global)
     *   accessible_org_ids int[] — Full accessible org IDs (same as scoped for admin)
     *   accessible_orgs   array   — Full org rows [{id, name}]
     *   redirect_url      string  — URL to redirect to after action (PRG)
     *   lang              string  — Current language code
     *
     * Return array keys:
     *   ok           bool    — Whether action succeeded
     *   feedback     string  — User-facing feedback message (store in session before redirect)
     *   session_vars array   — Flash session vars to set (inv_feedback, dev_temp_password, etc.)
     *   redirect     string  — URL to redirect to
     *
     * @param PDO    $pdo
     * @param array  $actor
     * @param string $action
     * @param array  $post    Sanitized $_POST data
     * @return array
     */
    public static function handleAction(PDO $pdo, array $actor, string $action, array $post): array
    {
        $actorRole        = (string) ($actor['role']               ?? '');
        $actorUserId      = (int)    ($actor['user_id']            ?? 0);
        $scopedOrgIds     = (array)  ($actor['scoped_org_ids']     ?? []);
        $accessibleOrgIds = (array)  ($actor['accessible_org_ids'] ?? []);
        $accessibleOrgs   = (array)  ($actor['accessible_orgs']    ?? []);
        $redirectUrl      = (string) ($actor['redirect_url']       ?? '/');
        $lang             = (string) ($actor['lang']               ?? 'en');

        // Guard: only allowed roles may proceed
        if (!in_array($actorRole, self::ALLOWED_ACTOR_ROLES, true)) {
            return self::_forbidden($redirectUrl);
        }

        $uid           = (int)    ($post['user_id']             ?? 0);
        $rid           = (int)    ($post['request_id']          ?? 0);
        $vrid          = (int)    ($post['validity_request_id'] ?? 0);
        $reviewComment = trim((string) ($post['review_comment'] ?? ''));

        return match ($action) {
            'activate'   => self::_activate($pdo, $actorRole, $uid, $scopedOrgIds, $redirectUrl),
            'deactivate' => self::_deactivate($pdo, $actorRole, $actorUserId, $uid, $scopedOrgIds, $redirectUrl),
            'unlock'     => self::_unlock($pdo, $actorRole, $uid, $scopedOrgIds, $redirectUrl),

            'change_role'    => self::_changeRole($pdo, $actorRole, $actorUserId, $uid, $post, $redirectUrl),
            'resolve_request' => self::_resolveRequest($pdo, $rid, $redirectUrl),
            'reset_password' => self::_resetPassword($pdo, $actorRole, $actorUserId, $uid, $lang, $scopedOrgIds, $redirectUrl),

            'approve_contract_validity_request' => self::_approveContractValidity(
                $pdo, $actorRole, $actorUserId, $vrid, $scopedOrgIds, $redirectUrl
            ),
            'reject_contract_validity_request' => self::_rejectContractValidity(
                $pdo, $actorRole, $actorUserId, $vrid, $scopedOrgIds, $reviewComment, $redirectUrl
            ),

            'generate_invitation' => self::_generateInvitation(
                $pdo, $actorRole, $actorUserId, $post,
                $accessibleOrgs, $accessibleOrgIds, $redirectUrl, $lang
            ),
            'revoke_invitation' => self::_revokeInvitation(
                $pdo, $actorRole, $actorUserId, $post, $accessibleOrgIds, $redirectUrl
            ),

            default => ['ok' => false, 'feedback' => '', 'session_vars' => [], 'redirect' => $redirectUrl],
        };
    }

    // ── Private helpers ────────────────────────────────────────

    /** Return a 403-flavoured result and set HTTP status. */
    private static function _forbidden(string $redirectUrl): array
    {
        http_response_code(403);
        return [
            'ok'          => false,
            'feedback'    => t('error_forbidden'),
            'session_vars' => [],
            'redirect'    => $redirectUrl,
        ];
    }

    /** Empty-result shortcut. */
    private static function _noop(string $redirectUrl): array
    {
        return ['ok' => false, 'feedback' => '', 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    // ── Action handlers ────────────────────────────────────────

    private static function _activate(
        PDO    $pdo,
        string $actorRole,
        int    $uid,
        array  $scopedOrgIds,
        string $redirectUrl
    ): array {
        if ($uid <= 0) {
            return self::_noop($redirectUrl);
        }

        if ($actorRole === 'owner') {
            $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$uid]);
        } elseif (orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier'])) {
            $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$uid]);
        } else {
            return self::_forbidden($redirectUrl);
        }

        return ['ok' => true, 'feedback' => t('feedback_activated'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    private static function _deactivate(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        int    $uid,
        array  $scopedOrgIds,
        string $redirectUrl
    ): array {
        if ($uid <= 0 || $uid === $actorUserId) {
            return self::_noop($redirectUrl);
        }

        if ($actorRole === 'owner') {
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
        } elseif (orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier'])) {
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
        } else {
            return self::_forbidden($redirectUrl);
        }

        return ['ok' => true, 'feedback' => t('feedback_deactivated'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    private static function _unlock(
        PDO    $pdo,
        string $actorRole,
        int    $uid,
        array  $scopedOrgIds,
        string $redirectUrl
    ): array {
        if ($uid <= 0) {
            return self::_noop($redirectUrl);
        }

        if ($actorRole === 'owner') {
            $pdo->prepare(
                'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?'
            )->execute([$uid]);
        } elseif (orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier'])) {
            $pdo->prepare(
                'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?'
            )->execute([$uid]);
        } else {
            return self::_forbidden($redirectUrl);
        }

        return ['ok' => true, 'feedback' => t('feedback_unlocked'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    /**
     * Change role — OWNER-ONLY.
     *
     * Admin sending this action is blocked with 403 and audit-logged.
     * Admin cannot manipulate role via payload — the backend enforces this.
     */
    private static function _changeRole(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        int    $uid,
        array  $post,
        string $redirectUrl
    ): array {
        if ($actorRole !== 'owner') {
            auditLog('forbidden_user_management_attempt', 'warning', null, $actorUserId, [
                'action'     => 'change_role',
                'actor_role' => $actorRole,
                'target_uid' => $uid,
            ]);
            return self::_forbidden($redirectUrl);
        }

        $newRole    = (string) ($post['new_role'] ?? '');
        $validRoles = ['owner', 'admin', 'support', 'supplier'];

        if ($uid <= 0
            || !in_array($newRole, $validRoles, true)
            || $uid === $actorUserId
        ) {
            return self::_noop($redirectUrl);
        }

        $pdo->prepare(
            'UPDATE org_members SET role = ? WHERE user_id = ? AND is_active = 1'
        )->execute([$newRole, $uid]);

        return ['ok' => true, 'feedback' => t('feedback_role_changed'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    private static function _resolveRequest(PDO $pdo, int $rid, string $redirectUrl): array
    {
        if ($rid <= 0) {
            return self::_noop($redirectUrl);
        }

        $pdo->prepare(
            'UPDATE password_requests SET status = "resolved", resolved_at = NOW() WHERE id = ?'
        )->execute([$rid]);

        return ['ok' => true, 'feedback' => t('feedback_request_resolved'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    /**
     * Reset password — admin/owner only (support blocked).
     *
     * Owner: may reset admin, support, supplier (not other owners, not self).
     * Admin: may reset support, supplier only within scope (not owner, not admin, not self).
     */
    private static function _resetPassword(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        int    $uid,
        string $lang,
        array  $scopedOrgIds,
        string $redirectUrl
    ): array {
        // Support cannot reset passwords.
        if ($actorRole === 'support') {
            return ['ok' => false, 'feedback' => t('error_unsupported_role'), 'session_vars' => [], 'redirect' => $redirectUrl];
        }

        if ($uid <= 0 || $uid === $actorUserId) {
            return self::_noop($redirectUrl);
        }

        // Fetch target user's role and email.
        $tgtStmt = $pdo->prepare(
            'SELECT u.id, u.email, u.preferred_language,
                    om.role AS org_role
               FROM users u
               JOIN org_members om ON om.user_id = u.id AND om.is_active = 1
              WHERE u.id = ?
              LIMIT 1'
        );
        $tgtStmt->execute([$uid]);
        $targetUser = $tgtStmt->fetch();

        if (!$targetUser) {
            return ['ok' => false, 'feedback' => t('error_not_found'), 'session_vars' => [], 'redirect' => $redirectUrl];
        }

        $targetRole = (string) $targetUser['org_role'];

        // RBAC: check actor may reset this target's role.
        if (!self::canResetPassword($actorRole, $targetRole)) {
            auditLog('forbidden_user_management_attempt', 'warning', null, $actorUserId, [
                'action'         => 'reset_password',
                'target_user_id' => $uid,
                'target_role'    => $targetRole,
                'actor_role'     => $actorRole,
            ]);
            return ['ok' => false, 'feedback' => t('reset_pwd_err_forbidden'), 'session_vars' => [], 'redirect' => $redirectUrl];
        }

        // Admin also needs scope check: target must be in admin's BUs.
        if ($actorRole === 'admin'
            && !orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier', 'support'])
        ) {
            return self::_forbidden($redirectUrl);
        }

        // Generate temporary password.
        $tempPassword = Validator::generateTemporaryPassword();
        $tempHash     = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $createdAt    = date('Y-m-d H:i:s');
        $expiresAt    = date('Y-m-d H:i:s', time() + 86400); // 24 h
        $expiresHuman = date('d/m/Y H:i', time() + 86400);
        $targetLang   = $targetUser['preferred_language'] ?? $lang;

        $pdo->prepare(
            'UPDATE users
                SET password_hash                = ?,
                    must_change_password          = 1,
                    temporary_password_created_at = ?,
                    temporary_password_expires_at = ?,
                    failed_attempts               = 0,
                    locked_until                  = NULL
              WHERE id = ?'
        )->execute([$tempHash, $createdAt, $expiresAt, $uid]);

        $emailResult = sendPasswordResetEmail(
            $targetUser['email'],
            $tempPassword,
            $expiresHuman,
            $targetLang
        );

        auditLog('password_reset_requested', 'info', null, $actorUserId, [
            'target_user_id' => $uid,
            'email_sent'     => $emailResult['sent'],
        ]);
        auditLog('temporary_password_generated', 'info', null, $actorUserId, [
            'target_user_id' => $uid,
        ]);

        $sessionVars = [];
        // DEV-ONLY: persist temp password for display when email is unconfigured.
        if (isset($emailResult['dev_temp_password'])) {
            $sessionVars['dev_temp_password'] = $emailResult['dev_temp_password'];
        }

        return [
            'ok'          => true,
            'feedback'    => t('reset_pwd_success'),
            'session_vars' => $sessionVars,
            'redirect'    => $redirectUrl,
        ];
    }

    private static function _approveContractValidity(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        int    $vrid,
        array  $scopedOrgIds,
        string $redirectUrl
    ): array {
        if ($vrid <= 0) {
            return self::_noop($redirectUrl);
        }

        // Owner passes null (global); admin passes scoped IDs.
        $scope = ($actorRole === 'owner') ? null : $scopedOrgIds;
        $ok    = cvrApproveRequest($pdo, $vrid, $actorUserId, $scope);

        if ($ok) {
            $logKey = ($actorRole === 'owner')
                ? 'owner_contract_validity_request_approved'
                : 'admin_contract_validity_request_approved';
            auditLog($logKey, 'info', null, $actorUserId, ['request_id' => $vrid]);
            auditLog('contract_primary_changed_by_review', 'info', null, $actorUserId, ['request_id' => $vrid]);
            return ['ok' => true, 'feedback' => t('contract_review_approved'), 'session_vars' => [], 'redirect' => $redirectUrl];
        }

        return ['ok' => false, 'feedback' => t('contract_review_action_failed'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    private static function _rejectContractValidity(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        int    $vrid,
        array  $scopedOrgIds,
        string $reviewComment,
        string $redirectUrl
    ): array {
        if ($vrid <= 0) {
            return self::_noop($redirectUrl);
        }

        $scope   = ($actorRole === 'owner') ? null : $scopedOrgIds;
        $comment = $reviewComment !== '' ? $reviewComment : null;
        $ok      = cvrRejectRequest($pdo, $vrid, $actorUserId, $scope, $comment);

        if ($ok) {
            $logKey = ($actorRole === 'owner')
                ? 'owner_contract_validity_request_rejected'
                : 'admin_contract_validity_request_rejected';
            auditLog($logKey, 'warning', null, $actorUserId, ['request_id' => $vrid]);
            return ['ok' => true, 'feedback' => t('contract_review_rejected'), 'session_vars' => [], 'redirect' => $redirectUrl];
        }

        return ['ok' => false, 'feedback' => t('contract_review_action_failed'), 'session_vars' => [], 'redirect' => $redirectUrl];
    }

    /**
     * Generate an invitation link.
     *
     * Owner may invite: admin | support | supplier.
     * Admin may invite: support | supplier   (NOT admin).
     * Support: blocked — returns error session var.
     *
     * Mass-assignment guard: if admin sends inv_role=admin, it is silently
     * downgraded to 'supplier' and a 403 is returned with audit log.
     */
    private static function _generateInvitation(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        array  $post,
        array  $accessibleOrgs,
        array  $accessibleOrgIds,
        string $redirectUrl,
        string $lang
    ): array {
        // Support cannot generate invitations.
        if ($actorRole === 'support') {
            return [
                'ok'          => false,
                'feedback'    => '',
                'session_vars' => [
                    'inv_feedback'      => t('error_unsupported_role'),
                    'inv_feedback_type' => 'error',
                ],
                'redirect' => $redirectUrl,
            ];
        }

        $invRole  = (string) ($post['inv_role'] ?? 'supplier');
        $invEmail = mb_substr(
            trim((string) ($post['invited_email'] ?? '')),
            0,
            Validator::maxLen('email'),
            'UTF-8'
        );

        // Role whitelist per actor — admin CANNOT invite admin.
        $allowedInvRoles = ($actorRole === 'owner')
            ? ['admin', 'support', 'supplier']
            : ['support', 'supplier'];

        if (!in_array($invRole, $allowedInvRoles, true)) {
            // Mass-assignment guard: admin tried to set invRole=admin.
            if ($invRole === 'admin' && $actorRole !== 'owner') {
                auditLog('forbidden_user_management_attempt', 'warning', null, $actorUserId, [
                    'action'     => 'generate_invitation',
                    'inv_role'   => 'admin',
                    'actor_role' => $actorRole,
                ]);
                return self::_forbidden($redirectUrl);
            }
            $invRole = 'supplier'; // safe fallback
        }

        $extraOrgIds = [];
        $targetOrgId = 0;

        if ($invRole === 'admin') {
            // Only owner can invite admin (enforced above).
            $checkedIds = array_map('intval', (array) ($post['admin_org_ids'] ?? []));
            // Only allow IDs within actor's accessible orgs.
            $checkedIds = array_values(array_filter(
                $checkedIds,
                fn($id) => in_array($id, $accessibleOrgIds, true)
            ));

            if (empty($checkedIds)) {
                return [
                    'ok'          => false,
                    'feedback'    => '',
                    'session_vars' => [
                        'inv_feedback'      => t('inv_err_no_org_selected'),
                        'inv_feedback_type' => 'error',
                    ],
                    'redirect' => $redirectUrl,
                ];
            }

            $targetOrgId = $checkedIds[0];
            $extraOrgIds = array_slice($checkedIds, 1);
        } else {
            $defaultOrgId = empty($accessibleOrgIds) ? 0 : $accessibleOrgIds[0];
            $targetOrgId  = (int) ($post['org_id'] ?? $defaultOrgId);

            // Scope guard: org must be in actor's accessible list.
            if (!orgScopeContainsOrgId($accessibleOrgIds, $targetOrgId)) {
                return [
                    'ok'          => false,
                    'feedback'    => '',
                    'session_vars' => [
                        'inv_feedback'      => t('error_no_org'),
                        'inv_feedback_type' => 'error',
                    ],
                    'redirect' => $redirectUrl,
                ];
            }
        }

        if ($invEmail !== '' && !filter_var($invEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok'          => false,
                'feedback'    => '',
                'session_vars' => [
                    'inv_feedback'      => t('enroll_err_email'),
                    'inv_feedback_type' => 'error',
                ],
                'redirect' => $redirectUrl,
            ];
        }

        // Build org name lookup for audit log.
        $orgLookup = [];
        foreach ($accessibleOrgs as $org) {
            $orgLookup[(int) $org['id']] = (string) $org['name'];
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = date('Y-m-d H:i:s', time() + 72 * 3600); // 72 h
        $enrollLink = buildEnrollLink($plainToken);

        $invStmt = $pdo->prepare(
            'INSERT INTO supplier_invitations
                (token_hash, org_id, extra_org_ids, role, invited_email, status, expires_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, "pending", ?, ?)'
        );
        $invStmt->execute([
            $tokenHash,
            $targetOrgId,
            !empty($extraOrgIds) ? json_encode(array_values($extraOrgIds)) : null,
            $invRole,
            $invEmail !== '' ? $invEmail : null,
            $expiresAt,
            $actorUserId,
        ]);

        auditLog('invitation_created', 'info', (int) $pdo->lastInsertId(), $actorUserId, [
            'org_id'        => $targetOrgId,
            'org_name'      => $orgLookup[$targetOrgId] ?? null,
            'role'          => $invRole,
            'invited_email' => $invEmail !== '' ? $invEmail : null,
        ]);

        $emailResult = null;
        if ($invEmail !== '') {
            $emailResult = sendInvitationEmail($invEmail, $enrollLink, $lang);
        }

        return [
            'ok'          => true,
            'feedback'    => '',
            'session_vars' => [
                'inv_new_link'      => $enrollLink,
                'inv_new_inv_email' => $invEmail !== '' ? $invEmail : null,
                'inv_email_result'  => $emailResult,
                'inv_feedback'      => t('inv_generated_success'),
                'inv_feedback_type' => 'success',
            ],
            'redirect' => $redirectUrl,
        ];
    }

    /**
     * Revoke an invitation — admin/owner only (support blocked).
     *
     * Scope guard: invitation must belong to an org in actor's accessible list.
     */
    private static function _revokeInvitation(
        PDO    $pdo,
        string $actorRole,
        int    $actorUserId,
        array  $post,
        array  $accessibleOrgIds,
        string $redirectUrl
    ): array {
        // Support cannot revoke invitations.
        if ($actorRole === 'support') {
            return self::_forbidden($redirectUrl);
        }

        $invId = (int) ($post['inv_id'] ?? 0);

        if ($invId > 0 && !empty($accessibleOrgIds)) {
            $ph     = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
            $params = array_merge([$invId], $accessibleOrgIds);

            $revStmt = $pdo->prepare(
                "UPDATE supplier_invitations
                    SET status = 'revoked', revoked_at = NOW()
                  WHERE id = ?
                    AND org_id IN ({$ph})
                    AND status = 'pending'"
            );
            $revStmt->execute($params);

            if ($revStmt->rowCount() > 0) {
                auditLog('invitation_revoked', 'info', $invId, $actorUserId);
            }
        }

        return [
            'ok'          => true,
            'feedback'    => '',
            'session_vars' => [
                'inv_feedback'      => t('inv_revoked_success'),
                'inv_feedback_type' => 'success',
            ],
            'redirect' => $redirectUrl,
        ];
    }
}
