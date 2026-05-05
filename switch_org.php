<?php
/**
 * /login/switch_org.php — Switch the active organization mid-session.
 *
 * POST only. Requires:
 *   - Active authenticated session (logged_in = true)
 *   - Valid CSRF token
 *   - org_id that the user actually belongs to (verified server-side)
 *
 * On success: regenerates session, updates org context, redirects back.
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';

requireAuth();
requireRole(['support']);
csrfValidate();

$targetOrgId = (int) ($_POST['org_id'] ?? 0);
$returnTo    = $_POST['return_to'] ?? '/login/admin/products.php';

// Whitelist return_to to relative paths only (prevent open redirect)
if (!preg_match('#^/login/#', $returnTo)) {
    $returnTo = '/login/admin/products.php';
}

if ($targetOrgId <= 0) {
    header('Location: ' . $returnTo);
    exit;
}

$pdo    = getDB();
$userId = (int) $_SESSION['user_id'];

// Verify user belongs to this org and is active
$stmt = $pdo->prepare(
    'SELECT o.id, o.slug, o.name, om.role
       FROM org_members om
       JOIN organizations o ON o.id = om.org_id
      WHERE om.user_id = ? AND om.org_id = ? AND om.is_active = 1 AND o.is_active = 1
    AND om.role = "support"
      LIMIT 1'
);
$stmt->execute([$userId, $targetOrgId]);
$org = $stmt->fetch();

if (!$org) {
    // Not a member — silently redirect back
    header('Location: ' . $returnTo);
    exit;
}

$allSupportStmt = $pdo->prepare(
        'SELECT o.id, o.slug, o.name, om.role
             FROM org_members om
             JOIN organizations o ON o.id = om.org_id
            WHERE om.user_id = ?
                AND om.is_active = 1
                AND o.is_active = 1
                AND om.role = "support"
            ORDER BY o.name ASC'
);
$allSupportStmt->execute([$userId]);
$supportOrgs = $allSupportStmt->fetchAll() ?: [];

// Preserve non-org session data
$username           = $_SESSION['username'];
$firstLogin         = $_SESSION['first_login']           ?? 0;
$lang               = $_SESSION['lang']                   ?? 'es';
$mustChangePassword = (int) ($_SESSION['must_change_password'] ?? 0);

session_regenerate_id(true);
$_SESSION = [];

$_SESSION['logged_in']              = true;
$_SESSION['user_id']                = $userId;
$_SESSION['username']               = $username;
$_SESSION['role']                   = $org['role'];
$_SESSION['org_id']                 = (int) $org['id'];
$_SESSION['org_slug']               = $org['slug'];
$_SESSION['org_name']               = $org['name'];
$_SESSION['support_orgs']           = $supportOrgs;
$_SESSION['first_login']            = $firstLogin;
$_SESSION['must_change_password']   = $mustChangePassword;
$_SESSION['lang']                   = $lang;
$_SESSION['last_activity']          = time();
$_SESSION['session_start_time']     = time();

auditLog('support_business_unit_selected', 'info', null, $userId, [
    'org_id'   => (int) $org['id'],
    'org_name' => $org['name'],
]);

if ($mustChangePassword) {
    header('Location: /login/change_password.php');
    exit;
}

header('Location: ' . $returnTo);
exit;
