<?php
/**
 * includes/auth.php � Authentication & multi-org RBAC helpers.
 *
 * Supported features:
 *  - Login with email or username + bcrypt password
 *  - Lockout after 3 failed attempts for 1 hour
 *  - is_active check on login
 *  - Multi-organization RBAC: user belongs to one or more orgs,
 *    each with an independent role (owner / admin / supplier / user)
 *  - Two-phase session for support only:
 *      Phase 1 (pending):  credentials valid, awaiting BU selection
 *      Phase 2 (active):   full session with role context
 *  - Owner/admin sessions are global (no active BU requirement)
 *  - Language preference loaded into session
 *  - Idle-timeout enforcement (30 minutes)
 *  - Per-request DB revalidation of is_active
 */

require_once __DIR__ . '/../config/db.php';

define('MAX_ATTEMPTS', 3);
define('LOCKOUT_SECS', 3600);
define('IDLE_TIMEOUT', 1800);       // 30-minute inactivity limit
define('ABSOLUTE_TIMEOUT', 28800);  // 8-hour hard session ceiling
define('ORG_PICK_TIMEOUT', 300);

define('AUTH_INVALID',  'INVALID');
define('AUTH_INACTIVE', 'INACTIVE');
define('AUTH_NO_ORG',   'NO_ORG');

define('ROLE_HIERARCHY', [
    'owner'    => 4,
    'admin'    => 3,
    'support'  => 2,
    'supplier' => 1,
]);

define('ROLE_HOME', [
    'owner'    => '/login/admin/products.php',
    'admin'    => '/login/admin/products.php',
    'support'  => '/login/admin/products.php',
    'supplier' => '/login/supplier/summary.php',
]);

// ---------------------------------------------------------------
// AUTHENTICATION
// ---------------------------------------------------------------

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

    // Legacy end-customer role is deprecated: access is now token-only via quote link.
    if (($user['role'] ?? '') === 'user') {
        return AUTH_INVALID;
    }

    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([$user['id']]);

    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
    }

    return $user;
}

// ---------------------------------------------------------------
// ORGANIZATION HELPERS
// ---------------------------------------------------------------

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
            AND om.role IN ("owner", "admin", "support", "supplier")
            AND o.is_active  = 1
          ORDER BY o.name ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Resolves the effective session role from a user's org memberships.
 * Priority: owner > admin > support > supplier
 */
function resolveEffectiveRole(array $orgs): string
{
    $rank = [
        'owner' => 4,
        'admin' => 3,
        'support' => 2,
        'supplier' => 1,
    ];

    $bestRole = '';
    $bestRank = 0;

    foreach ($orgs as $org) {
        $role = (string) ($org['role'] ?? '');
        $r = $rank[$role] ?? 0;
        if ($r > $bestRank) {
            $bestRank = $r;
            $bestRole = $role;
        }
    }

    return $bestRole;
}

/**
 * Returns the first organization for a specific role from a membership list.
 */
function firstOrgForRole(array $orgs, string $role): ?array
{
    foreach ($orgs as $org) {
        if (($org['role'] ?? '') === $role) {
            return $org;
        }
    }
    return null;
}

// ---------------------------------------------------------------
// SESSION MANAGEMENT
// ---------------------------------------------------------------

/**
 * Phase 1 � pending session: user authenticated, org not yet chosen.
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
    $_SESSION['pending_role']        = 'support';
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
    if (empty($_SESSION['pending_login']) || (string) ($_SESSION['pending_role'] ?? '') !== 'support') {
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

    $userId      = (int) $_SESSION['pending_user_id'];
    $username    = $_SESSION['pending_username'];
    $firstLogin  = (int) ($_SESSION['pending_first_login'] ?? 1);
    $lang        = $_SESSION['lang'] ?? 'es';
    $supportOrgs = is_array($_SESSION['pending_orgs'] ?? null) ? $_SESSION['pending_orgs'] : [];

    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['logged_in']          = true;
    $_SESSION['user_id']             = $userId;
    $_SESSION['username']            = $username;
    $_SESSION['role']                = $found['role'];
    $_SESSION['org_id']              = (int) $found['id'];
    $_SESSION['org_slug']            = $found['slug'];
    $_SESSION['org_name']            = $found['name'];
    $_SESSION['support_orgs']        = $supportOrgs;
    $_SESSION['first_login']         = $firstLogin;
    $_SESSION['lang']                = $lang;
    $_SESSION['last_activity']       = time();
    $_SESSION['session_start_time']  = time();

    return true;
}

/**
 * Phase 2 � full session: user authenticated AND org already chosen.
 * Used when user belongs to exactly 1 organization (skip picker).
 */
function createSession(array $user, array $org): void
{
    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['logged_in']         = true;
    $_SESSION['user_id']           = (int) $user['id'];
    $_SESSION['username']          = $user['username'];
    $_SESSION['role']              = $org['role'];
    $_SESSION['org_id']            = (int) $org['id'];
    $_SESSION['org_slug']          = $org['slug'];
    $_SESSION['org_name']          = $org['name'];
    if (($org['role'] ?? '') === 'support') {
        $_SESSION['support_orgs'] = [$org];
    }
    $_SESSION['first_login']        = (int) $user['first_login'];
    $_SESSION['lang']               = $user['preferred_language'] ?? 'es';
    $_SESSION['last_activity']      = time();
    $_SESSION['session_start_time'] = time();
}

/**
 * Full session for global roles (owner/admin) without active BU dependency.
 */
function createGlobalSession(array $user, string $role): void
{
    session_regenerate_id(true);
    $_SESSION = [];

    $_SESSION['logged_in']         = true;
    $_SESSION['user_id']           = (int) $user['id'];
    $_SESSION['username']          = $user['username'];
    $_SESSION['role']              = $role;
    $_SESSION['org_id']            = 0;
    $_SESSION['org_slug']          = '';
    $_SESSION['org_name']          = '';
    $_SESSION['first_login']       = (int) $user['first_login'];
    $_SESSION['lang']              = $user['preferred_language'] ?? 'es';
    $_SESSION['last_activity']     = time();
    $_SESSION['session_start_time']= time();
}

/** True only when a full (org-selected) session is active. */
function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in'])
        && !empty($_SESSION['user_id'])
    && !empty($_SESSION['role']);
}

/** True when a pending (pre-org-selection) session is active. */
function isPendingLogin(): bool
{
    return !empty($_SESSION['pending_login'])
        && !empty($_SESSION['pending_user_id'])
        && !empty($_SESSION['pending_orgs']);
}

// ── Cache-control helper ─────────────────────────────────────

/**
 * Emit no-store / no-cache headers on every authenticated response.
 *
 * Covers HTTP/1.1 (Cache-Control), HTTP/1.0 (Pragma), and legacy
 * proxy/browser expiry (Expires).  Call this at the top of every
 * authenticated page guard so no sensitive view is ever cached —
 * regardless of whether the page's own header block remembers to
 * include all three directives.
 */
function sendNoCacheHeaders(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

// ── Session guards ───────────────────────────────────────────

/**
 * Guard for fully-authenticated pages.
 * Enforces idle timeout, absolute session ceiling, and DB revalidation.
 * Also emits no-store cache headers to prevent browser/proxy caching.
 */
function requireAuth(): void
{
    sendNoCacheHeaders();

    if (!isLoggedIn()) {
        if (isPendingLogin()) {
            header('Location: /login/org-picker.php');
            exit;
        }
        header('Location: /login/index.php');
        exit;
    }

    $now = time();

    // Absolute session ceiling — hard-limit regardless of activity.
    if (isset($_SESSION['session_start_time']) &&
        ($now - (int) $_SESSION['session_start_time']) > ABSOLUTE_TIMEOUT) {
        destroySession();
        header('Location: /login/index.php?reason=timeout');
        exit;
    }

    // Idle timeout — expire after IDLE_TIMEOUT seconds of inactivity.
    if (($now - ($_SESSION['last_activity'] ?? 0)) > IDLE_TIMEOUT) {
        destroySession();
        header('Location: /login/index.php?reason=timeout');
        exit;
    }
    $_SESSION['last_activity'] = $now;

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row  = $stmt->fetch();
        if (!$row || !(int) $row['is_active']) {
            destroySession();
            header('Location: /login/index.php?reason=deactivated');
            exit;
        }

        if ((string) ($_SESSION['role'] ?? '') === 'support') {
            $orgStmt = $pdo->prepare(
                'SELECT o.id, o.slug, o.name, om.role
                   FROM org_members om
                   JOIN organizations o ON o.id = om.org_id
                  WHERE om.user_id = ?
                    AND om.is_active = 1
                    AND om.role = "support"
                    AND o.is_active = 1
                  ORDER BY o.name ASC'
            );
            $orgStmt->execute([(int) $_SESSION['user_id']]);
            $supportOrgs = $orgStmt->fetchAll() ?: [];

            if (empty($supportOrgs)) {
                destroySession();
                header('Location: /login/index.php?reason=unsupported_role');
                exit;
            }

            $_SESSION['support_orgs'] = $supportOrgs;

            $activeOrgId = (int) ($_SESSION['org_id'] ?? 0);
            $isValidActiveOrg = false;
            foreach ($supportOrgs as $supportOrg) {
                if ((int) ($supportOrg['id'] ?? 0) === $activeOrgId) {
                    $isValidActiveOrg = true;
                    break;
                }
            }

            if (!$isValidActiveOrg) {
                $fallbackOrg = $supportOrgs[0];
                $_SESSION['org_id']   = (int) $fallbackOrg['id'];
                $_SESSION['org_slug'] = (string) $fallbackOrg['slug'];
                $_SESSION['org_name'] = (string) $fallbackOrg['name'];
            }
        }
    } catch (PDOException $e) {
        error_log('requireAuth DB check failed: ' . $e->getMessage());
    }
}

/**
 * Guard for the org-picker page.
 * Redirects away if already fully logged in or not authenticated at all.
 * Emits no-store cache headers to prevent browser caching.
 */
function requirePendingAuth(): void
{
    sendNoCacheHeaders();

    if (isLoggedIn()) {
        redirectToHome();
    }

    if (!isPendingLogin()) {
        header('Location: /login/index.php');
        exit;
    }

    if ((string) ($_SESSION['pending_role'] ?? '') !== 'support') {
        destroySession();
        header('Location: /login/index.php?reason=unsupported_role');
        exit;
    }

    if ((time() - ($_SESSION['last_activity'] ?? 0)) > ORG_PICK_TIMEOUT) {
        destroySession();
        header('Location: /login/index.php?reason=timeout');
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

// ---------------------------------------------------------------
// RBAC
// ---------------------------------------------------------------

/**
 * Redirects to the user's home panel for their current org role.
 * Respects supplier first_login flag.
 */
function redirectToHome(): void
{
    $role       = $_SESSION['role']        ?? '';
    $firstLogin = (int) ($_SESSION['first_login'] ?? 0);

    if (!in_array($role, ['owner', 'admin', 'support', 'supplier'], true)) {
        destroySession();
        header('Location: /login/index.php?reason=unsupported_role');
        exit;
    }

    if ($role === 'supplier' && $firstLogin === 1) {
        header('Location: /login/supplier/profile.php');
        exit;
    }

    $homes = ROLE_HOME;
    header('Location: ' . ($homes[$role] ?? '/login/index.php'));
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
        header('Location: /login/index.php');
        exit;
    }
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
        redirectToHome();
    }
}

/**
 * True if $managerRole can manage $targetRole within the same org.
 *
 * owner  ? manages everyone (incl. other owners)
 * admin  ? manages supplier and user only
 * others ? no management rights
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

// ---------------------------------------------------------------
// AUTH FACADE — OOP wrapper over the procedural helpers above.
// ---------------------------------------------------------------

/**
 * Auth — Static facade for authentication and session management.
 *
 * New code should use Auth:: methods.  All existing procedural calls
 * (requireAuth, requireRole, etc.) remain valid and are NOT replaced.
 *
 * Owner/Admin rule (enforced globally):
 *   - Owner  → org_id = 0 in session (global, no BU selection needed).
 *   - Admin  → org_id = 0 in session (global, no BU selection needed).
 *   - Support → org_id = active BU in session (required).
 *   - Neither owner nor admin should be shown a mandatory BU selector.
 */
final class Auth
{
    // ── Identity ─────────────────────────────────────────────

    /**
     * Returns full session context for the authenticated user, or null.
     *
     * @return array{
     *   user_id: int, username: string, role: string,
     *   org_id: int, org_name: string, first_login: int, lang: string
     * }|null
     */
    public static function user(): ?array
    {
        if (!isLoggedIn()) {
            return null;
        }
        return [
            'user_id'     => (int)    ($_SESSION['user_id']     ?? 0),
            'username'    => (string) ($_SESSION['username']    ?? ''),
            'role'        => (string) ($_SESSION['role']        ?? ''),
            'org_id'      => (int)    ($_SESSION['org_id']      ?? 0),
            'org_name'    => (string) ($_SESSION['org_name']    ?? ''),
            'first_login' => (int)    ($_SESSION['first_login'] ?? 0),
            'lang'        => (string) ($_SESSION['lang']        ?? 'es'),
        ];
    }

    /** Current authenticated user's ID, or 0 if not logged in. */
    public static function id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /** Current role string, or '' if not logged in. */
    public static function role(): string
    {
        return (string) ($_SESSION['role'] ?? '');
    }

    /** Numeric rank of the current role. owner=4, admin=3, support=2, supplier=1, else 0. */
    public static function roleRank(): int
    {
        return ROLE_HIERARCHY[self::role()] ?? 0;
    }

    // ── Session state ────────────────────────────────────────

    /** True if a complete, fully-authenticated session is active. */
    public static function check(): bool
    {
        return isLoggedIn();
    }

    /** True if a pending (pre-org-selection) session is active (support only). */
    public static function isPending(): bool
    {
        return isPendingLogin();
    }

    // ── Guards ───────────────────────────────────────────────

    /**
     * Guard: enforce full authentication.
     * Redirects to login if not authenticated.
     * Enforces idle timeout and per-request DB revalidation.
     */
    public static function requireLogin(): void
    {
        requireAuth();
    }

    /**
     * Guard: enforce that the session has one of the given roles.
     * Call requireLogin() (or requireAccess()) before this.
     * Redirects to the role's home page on mismatch.
     *
     * @param string[] $roles  Allowed roles, e.g. ['owner', 'admin']
     */
    public static function requireRole(array $roles): void
    {
        requireRole($roles);
    }

    /**
     * Combined guard: login + role check.
     * Returns minimal auth context for handlers.
     *
     * @param  string[]  $roles  Allowed roles (empty = any authenticated role)
     * @return array{user_id: int, role: string, org_id: int}
     */
    public static function requireAccess(array $roles = []): array
    {
        requireAuth();
        if (!empty($roles)) {
            requireRole($roles);
        }
        return [
            'user_id' => (int)    ($_SESSION['user_id'] ?? 0),
            'role'    => (string) ($_SESSION['role']    ?? ''),
            'org_id'  => (int)    ($_SESSION['org_id']  ?? 0),
        ];
    }

    // ── Lifecycle ────────────────────────────────────────────

    /** Destroy the session completely (full logout). */
    public static function logout(): void
    {
        destroySession();
    }

    /**
     * Force an immediate DB revalidation of the current user's is_active flag.
     * Destroys the session and redirects if the user is deactivated.
     */
    public static function refreshUser(): void
    {
        if (!isLoggedIn()) {
            return;
        }
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $row  = $stmt->fetch();
        if (!$row || !(int) $row['is_active']) {
            destroySession();
            header('Location: /login/index.php?reason=deactivated');
            exit;
        }
    }

    /**
     * Regenerate the session ID.
     * Note: login and org-pick already call session_regenerate_id(true) internally.
     */
    public static function regenerateSession(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Clear role-context session keys without destroying the full session.
     * Use when switching roles or orgs within an existing session.
     * Always follow with a new createGlobalSession() / createSession() call.
     */
    public static function clearRoleContext(): void
    {
        unset(
            $_SESSION['role'],
            $_SESSION['org_id'],
            $_SESSION['org_slug'],
            $_SESSION['org_name'],
            $_SESSION['support_orgs']
        );
    }
}
