<?php
/**
 * /login/admin/users.php — Admin user management entrypoint.
 *
 * Access: role = 'admin' | 'support'
 *   admin  — manages supplier users within assigned business units.
 *   support — read-only list + activate/deactivate/unlock in scoped BU.
 *
 * All action logic is centralised in UserManagementService.
 * All HTML is rendered by includes/views/users/users_page.php.
 *
 * ┌──────────────────────────────────────────────────────┐
 * │ ADMIN CANNOT:                                         │
 * │   • change any user's role                           │
 * │   • create or manage Business Units                  │
 * │   • invite users with the 'admin' role               │
 * │   • access users outside assigned business units     │
 * └──────────────────────────────────────────────────────┘
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

// ── Auth + role guard ─────────────────────────────────────────
requireAuth();
initLang();
requireRole(['admin', 'support']);

// ── Context ───────────────────────────────────────────────────
$pdo      = getDB();
$orgId    = (int) ($_SESSION['org_id'] ?? 0);
$userId   = (int) $_SESSION['user_id'];
$role     = (string) ($_SESSION['role'] ?? '');
$lang     = currentLang();
$accessibleOrgs   = loadAccessibleOrganizations($pdo, $userId, $role);
$accessibleOrgIds = array_map('intval', array_column($accessibleOrgs, 'id'));
$scopedOrgIds     = $role === 'support'
    ? (in_array($orgId, $accessibleOrgIds, true) ? [$orgId] : [])
    : $accessibleOrgIds;
$orgName  = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');

// Actor context passed to UserManagementService
$redirectUrl = '/login/admin/users.php';
$actor = [
    'role'               => $role,
    'user_id'            => $userId,
    'scoped_org_ids'     => $scopedOrgIds,
    'accessible_org_ids' => $accessibleOrgIds,
    'accessible_orgs'    => $accessibleOrgs,
    'redirect_url'       => $redirectUrl,
    'lang'               => $lang,
];

// ── Handle POST actions (PRG pattern) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = (string) ($_POST['action'] ?? '');
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

$validityRequests = cvrListValidityRequests($pdo, $scopedOrgIds);

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
$pageTitle     = t('admin_page_title');
$headerTitle   = t('admin_title');
$username      = htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$initial       = strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1));
$canChangeRole = false;   // Admin CANNOT change roles — owner-only privilege
$isOwner       = false;

// ── Render shared view ────────────────────────────────────────
require __DIR__ . '/../includes/views/users/users_page.php';


