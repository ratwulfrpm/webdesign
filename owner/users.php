<?php
/**
 * /login/owner/users.php — Business-owner administration panel
 *
 * Entrypoint only — all logic delegated to UserManagementService and shared views.
 * Access: role = 'owner' only.
 * Features:
 *  - Full user list across ALL roles and ALL organisations (global scope)
 *  - Activate / deactivate / unlock / change_role for any user (except self)
 *  - Password-request queue + contract validity reviews
 *  - Invitation generation (admin/support/supplier) with multi-org BU assignment
 *  - Language selector
 *  - 30-min idle timeout enforced by requireAuth()
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
require_once __DIR__ . '/../includes/session.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/RBAC.php';
require_once __DIR__ . '/../includes/TenantScope.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tabs.php';
require_once __DIR__ . '/../includes/contract_validity_admin.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/org_scope.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/Validator.php';
require_once __DIR__ . '/../includes/AppConfig.php';
require_once __DIR__ . '/../includes/services/UserManagementService.php';

// Auth + RBAC checks
requireAuth();
initLang();
requireRole(['owner']);

// ── Context ───────────────────────────────────────────────────
$pdo              = getDB();
$orgId            = 0;  // owner is global — no single org
$userId           = (int) $_SESSION['user_id'];
$role             = 'owner';
$lang             = currentLang();
$accessibleOrgs   = loadAccessibleOrganizations($pdo, $userId, $role);
$accessibleOrgIds = array_map('intval', array_column($accessibleOrgs, 'id'));
$scopedOrgIds     = [];   // empty = global (owner sees all)
$orgName          = '';   // no org badge for owner
$redirectUrl      = '/login/owner/users.php';

$actor = [
    'role'               => $role,
    'user_id'            => $userId,
    'scoped_org_ids'     => $scopedOrgIds,
    'accessible_org_ids' => $accessibleOrgIds,
    'accessible_orgs'    => $accessibleOrgs,
    'redirect_url'       => $redirectUrl,
    'lang'               => $lang,
];

// ── Handle POST actions via UserManagementService ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = $_POST['action'] ?? '';
    $result = UserManagementService::handleAction($pdo, $actor, $action, $_POST);
    foreach ($result['session_vars'] as $k => $v) {
        $_SESSION[$k] = $v;
    }
    if ($result['feedback'] !== '') {
        $_SESSION['_users_feedback'] = $result['feedback'];
    }
    header('Location: ' . $result['redirect']);
    exit;
}



// ── Fetch data via UserManagementService ──────────────────────
$usersPage = max(1, (int) ($_GET['upage'] ?? 1));
$usersData = UserManagementService::getUsersForActor($pdo, $role, $scopedOrgIds, $usersPage);
$users      = $usersData['users'];
$usersTotal = $usersData['total'];
$usersPages = $usersData['pages'];
$usersPage  = $usersData['page'];

$requests         = $pdo->query(
    'SELECT id, company_name, email, username, notes, status, requested_at
       FROM password_requests
      ORDER BY status ASC, requested_at DESC'
)->fetchAll();

$validityRequests = cvrListValidityRequests($pdo, null);   // null = global (owner)

$orgs        = $accessibleOrgs;
$invitations = UserManagementService::getInvitationsForActor($pdo, $role, $accessibleOrgIds);


// ── Consume session flash vars ────────────────────────────────
$feedback = $_SESSION['_users_feedback'] ?? '';
unset($_SESSION['_users_feedback']);

$invFeedback     = $_SESSION['inv_feedback']      ?? '';
$invFeedbackType = $_SESSION['inv_feedback_type'] ?? 'success';
$invNewLink      = $_SESSION['inv_new_link']       ?? '';
$invNewEmail     = $_SESSION['inv_new_inv_email']  ?? null;
$invEmailResult  = $_SESSION['inv_email_result']   ?? null;
unset(
    $_SESSION['inv_feedback'],
    $_SESSION['inv_feedback_type'],
    $_SESSION['inv_new_link'],
    $_SESSION['inv_new_inv_email'],
    $_SESSION['inv_email_result']
);

$devTempPassword = $_SESSION['dev_temp_password'] ?? null;
unset($_SESSION['dev_temp_password']);

// ── Page-specific variables for shared view ───────────────────
$actorRole     = $role;
$actionUrl     = $redirectUrl;
$pageTitle     = t('owner_page_title');
$headerTitle   = t('owner_title');
$username      = htmlspecialchars($_SESSION['username'] ?? 'Owner', ENT_QUOTES, 'UTF-8');
$initial       = strtoupper(substr($_SESSION['username'] ?? 'O', 0, 1));
$canChangeRole = true;    // Owner CAN change roles
$isOwner       = true;

// ── Render shared view ────────────────────────────────────────
require __DIR__ . '/../includes/views/users/users_page.php';




