<?php
/**
 * includes/auth.php — Authentication & multi-org RBAC helpers.
 *
 * Supported features:
 *  - Login with email or username + bcrypt password
 *  - Lockout after 3 failed attempts for 1 hour
 *  - is_active check on login
 *  - Multi-organization RBAC: user belongs to one or more orgs,
 *    each with an independent role (owner / admin / supplier / user)
 *  - Two-phase session:
 *      Phase 1 (pending):  credentials valid, awaiting org selection
 *      Phase 2 (active):   org chosen, full session with role context
 *  - Org-picker redirect when user has access to more than 1 organization
 *  - Language preference loaded into session
 *  - Idle-timeout enforcement (30 minutes)
 *  - Per-request DB revalidation of is_active
 */

require_once __DIR__ . '/../config/db.php';

define('MAX_ATTEMPTS', 3);
define('LOCKOUT_SECS', 3600);
define('IDLE_TIMEOUT', 1800);
define('ORG_PICK_TIMEOUT', 300);

define('AUTH_INVALID',  'INVALID');
define('AUTH_INACTIVE', 'INACTIVE');
define('AUTH_NO_ORG',   'NO_ORG');

define('ROLE_HIERARCHY', [
    'owner'    => 4,
    'admin'    => 3,
    'supplier' => 2,
    'user'     => 1,
]);

define('ROLE_HOME', [
    'owner'    => '/jshop/owner/index.php',
    'admin'    => '/jshop/admin/index.php',
    'supplier' => '/jshop/supplier/summary.php',
    'user'     => '/jshop/user/dashboard.php',
]);

// ═══════════════════════════════════════════════════════════════
// AUTHENTICATION
// ═══════════════════════════════════════════════════════════════

function attemptLogin(string $identifier, string $password): array|string
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return AUTH_INVALID;
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, username, email, password_hash,
                is_active, role, failed_attempts, locked_until,
                first_login, preferred_language
           FROM users
          WHERE email = :e OR username = :u
          LIMIT 1'
    );
    $stmt->execute([':e' => $identifier, ':u' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, '$2y$12$invaliddummyhashfortimingequalityXXXXXXXXXXXXXXXXXXXXX');
        return AUTH_INVALID;
    }

    if (!empty($user['locked_until'])) {
        $ts = strtotime($user['locked_until']);
        if (time() < $ts) {
            return 'LOCKED:' . (int) ceil(($ts - time()) / 60);
        }
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$user['id']]);
        $user['failed_attempts'] = 0;
        $user['locked_until']    = null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $n = (int) $user['failed_attempts'] + 1;
        if ($n >= MAX_ATTEMPTS) {
            $lock = date('Y-m-d H:i:s', time() + LOCKOUT_SECS);
            $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$n, $lock, $user['id']]);
            return 'LOCKED:60';
        }
        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')
            ->execute([$n, $user['id']]);
        return AUTH_INVALID;
    }

    if (!(int) $user['is_active']) {
        return AUTH_INACTIVE;
    }

    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([$user['id']]);

    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
    }

    return $user;
}

// ═══════════════════════════════════════════════════════════════
// ORGANIZATION HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Returns all active org memberships for a user.
 * Each element: ['id', 'slug', 'name', 'description', 'role']
 */
function getUserOrgs(int $userId): array
{
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT o.id, o.slug, o.name, o.description, om.role
           FROM org_members om
           JOIN organizations o ON o.id = om.org_id
          WHERE om.user_id  = ?
            AND om.is_active = 1
            AND o.is_active  = 1
          ORDER BY o.name ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════════
// SESSION MANAGEMENT
// ═══════════════════════════════════════════════════════════════

/**
 * Phase 1 — pending session: user authenticated, org not yet chosen.
 * Used when user belongs to > 1 organization.
 */
function createPendingSession(array $user, array $orgs): void
{
    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['pending_login']       = true;
    $_SESSION['pending_user_id']     = (int) $user['id'];
    $_SESSION['pending_username']    = $user['username'];
    $_SESSION['pending_first_login'] = (int) $user['first_login'];
    $_SESSION['pending_orgs']        = $orgs;
    $_SESSION['lang']                = $user['preferred_language'] ?? 'es';
    $_SESSION['last_activity']       = time();
}

/**
 * Promotes a pending session to a full session by selecting an org.
 *
 * @param  int  $orgId  Organization ID the user clicked
 * @return bool         False if org not in user's pending list
 */
function selectOrg(int $orgId): bool
{
    if (empty($_SESSION['pending_login'])) {
        return false;
    }

    $found = null;
    foreach ($_SESSION['pending_orgs'] as $org) {
        if ((int) $org['id'] === $orgId) {
            $found = $org;
            break;
        }
    }
    if ($found === null) {
        return false;
    }

    $userId     = (int) $_SESSION['pending_user_id'];
    $username   = $_SESSION['pending_username'];
    $firstLogin = (int) ($_SESSION['pending_first_login'] ?? 1);
    $lang       = $_SESSION['lang'] ?? 'es';

    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['logged_in']     = true;
    $_SESSION['user_id']       = $userId;
    $_SESSION['username']      = $username;
    $_SESSION['role']          = $found['role'];
    $_SESSION['org_id']        = (int) $found['id'];
    $_SESSION['org_slug']      = $found['slug'];
    $_SESSION['org_name']      = $found['name'];
    $_SESSION['first_login']   = $firstLogin;
    $_SESSION['lang']          = $lang;
    $_SESSION['last_activity'] = time();

    return true;
}

/**
 * Phase 2 — full session: user authenticated AND org already chosen.
 * Used when user belongs to exactly 1 organization (skip picker).
 */
function createSession(array $user, array $org): void
{
    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['logged_in']     = true;
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $org['role'];
    $_SESSION['org_id']        = (int) $org['id'];
    $_SESSION['org_slug']      = $org['slug'];
    $_SESSION['org_name']      = $org['name'];
    $_SESSION['first_login']   = (int) $user['first_login'];
    $_SESSION['lang']          = $user['preferred_language'] ?? 'es';
    $_SESSION['last_activity'] = time();
}

/** True only when a full (org-selected) session is active. */
function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in'])
        && !empty($_SESSION['user_id'])
        && !empty($_SESSION['role'])
        && !empty($_SESSION['org_id']);
}

/** True when a pending (pre-org-selection) session is active. */
function isPendingLogin(): bool
{
    return !empty($_SESSION['pending_login'])
        && !empty($_SESSION['pending_user_id'])
        && !empty($_SESSION['pending_orgs']);
}

/**
 * Guard for fully-authenticated pages.
 * Enforces idle timeout and DB revalidation.
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        if (isPendingLogin()) {
            header('Location: /jshop/org-picker.php');
            exit;
        }
        header('Location: /jshop/index.php');
        exit;
    }

    if ((time() - ($_SESSION['last_activity'] ?? 0)) > IDLE_TIMEOUT) {
        destroySession();
        header('Location: /jshop/index.php?reason=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row  = $stmt->fetch();
        if (!$row || !(int) $row['is_active']) {
            destroySession();
            header('Location: /jshop/index.php?reason=deactivated');
            exit;
        }
    } catch (PDOException $e) {
        error_log('requireAuth DB check failed: ' . $e->getMessage());
    }
}

/**
 * Guard for the org-picker page.
 * Redirects away if already fully logged in or not authenticated at all.
 */
function requirePendingAuth(): void
{
    if (isLoggedIn()) {
        redirectToHome();
    }

    if (!isPendingLogin()) {
        header('Location: /jshop/index.php');
        exit;
    }

    if ((time() - ($_SESSION['last_activity'] ?? 0)) > ORG_PICK_TIMEOUT) {
        destroySession();
        header('Location: /jshop/index.php?reason=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/** Destroys the current session and clears its cookie. */
function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ═══════════════════════════════════════════════════════════════
// RBAC
// ═══════════════════════════════════════════════════════════════

/**
 * Redirects to the user's home panel for their current org role.
 * Respects supplier first_login flag.
 */
function redirectToHome(): void
{
    $role       = $_SESSION['role']        ?? 'user';
    $firstLogin = (int) ($_SESSION['first_login'] ?? 0);

    if ($role === 'supplier' && $firstLogin === 1) {
        header('Location: /jshop/supplier/profile.php');
        exit;
    }

    $homes = ROLE_HOME;
    header('Location: ' . ($homes[$role] ?? '/jshop/index.php'));
    exit;
}

/**
 * Ensures the current session has one of the $allowed roles.
 * requireAuth() must have been called first.
 *
 * @param string[] $allowed  e.g. ['owner', 'admin']
 */
function requireRole(array $allowed): void
{
    if (!isLoggedIn()) {
        header('Location: /jshop/index.php');
        exit;
    }
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
        redirectToHome();
    }
}

/**
 * True if $managerRole can manage $targetRole within the same org.
 *
 * owner  → manages everyone (incl. other owners)
 * admin  → manages supplier and user only
 * others → no management rights
 */
function canManageRole(string $managerRole, string $targetRole): bool
{
    if ($managerRole === 'owner') {
        return true;
    }
    if ($managerRole === 'admin') {
        $h = ROLE_HIERARCHY;
        return isset($h[$targetRole]) && $h[$targetRole] < $h['admin'];
    }
    return false;
}
