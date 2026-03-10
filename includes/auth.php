<?php
/**
 * Authentication helpers.
 *
 * Features implemented:
 *  - Login with email or username + bcrypt password
 *  - Lockout after 3 failed attempts for 1 hour
 *  - is_active check on login
 *  - Role-aware session (admin / supplier)
 *  - first_login flag for supplier routing
 *  - Language preference loaded into session
 *  - Idle-timeout enforcement (30 minutes)
 *  - Per-request DB revalidation of is_active
 */

require_once __DIR__ . '/../config/db.php';

/** Maximum consecutive failed login attempts before lockout. */
define('MAX_ATTEMPTS', 3);

/** Lockout duration in seconds (1 hour). */
define('LOCKOUT_SECS', 3600);

/** Idle timeout in seconds (30 minutes). */
define('IDLE_TIMEOUT', 1800);

// ── Error codes returned by attemptLogin() ────────────────────
define('AUTH_INVALID',  'INVALID');
define('AUTH_INACTIVE', 'INACTIVE');
// Locked returns 'LOCKED:<minutes_remaining>'

/**
 * Attempts to authenticate a user.
 *
 * @param  string $identifier  Email or username
 * @param  string $password    Plain-text password
 * @return array|string        Full user row on success.
 *                             String error code on failure:
 *                              'INVALID'     — user not found or wrong password
 *                              'INACTIVE'    — account disabled by admin
 *                              'LOCKED:<n>'  — account locked, n minutes remaining
 */
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
          WHERE email = :email OR username = :username
          LIMIT 1'
    );
    $stmt->execute([':email' => $identifier, ':username' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        // Timing-safe dummy verify — prevents user-enumeration via response time
        password_verify($password, '$2y$12$invaliddummyhashfortimingequalityXXXXXXXXXXXXXXXXXXXXX');
        return AUTH_INVALID;
    }

    // ── 1. Lockout check ─────────────────────────────────────
    if (!empty($user['locked_until'])) {
        $lockedUntilTs = strtotime($user['locked_until']);
        if (time() < $lockedUntilTs) {
            $minutesLeft = (int) ceil(($lockedUntilTs - time()) / 60);
            return 'LOCKED:' . $minutesLeft;
        }
        // Lockout expired — reset counters automatically
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$user['id']]);
        $user['failed_attempts'] = 0;
        $user['locked_until']    = null;
    }

    // ── 2. Verify password ───────────────────────────────────
    if (!password_verify($password, $user['password_hash'])) {
        $newAttempts = (int) $user['failed_attempts'] + 1;

        if ($newAttempts >= MAX_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_SECS);
            $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$newAttempts, $lockUntil, $user['id']]);
            return 'LOCKED:60';
        }

        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')
            ->execute([$newAttempts, $user['id']]);
        return AUTH_INVALID;
    }

    // ── 3. Active status check ───────────────────────────────
    if (!(int) $user['is_active']) {
        return AUTH_INACTIVE;
    }

    // ── 4. Successful login — reset failure counters ─────────
    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([$user['id']]);

    // ── 5. Rehash if cost/algo changed ───────────────────────
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $user['id']]);
    }

    return $user;
}

/**
 * Creates a fully populated authenticated session.
 * Stores role, first_login flag, language preference, and activity timestamp.
 */
function createSession(array $user): void
{
    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['first_login']   = (int) $user['first_login'];
    $_SESSION['logged_in']     = true;
    $_SESSION['last_activity'] = time();

    // Load user's stored language preference (fallback: es)
    if (!empty($user['preferred_language'])) {
        $_SESSION['lang'] = $user['preferred_language'];
    }
}

/**
 * Returns true only when a valid, complete session exists.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in'])
        && !empty($_SESSION['user_id'])
        && !empty($_SESSION['role']);
}

/**
 * Protects any page that requires authentication.
 *
 * Enforces:
 *  1. Session existence
 *  2. 30-minute idle timeout
 *  3. DB revalidation that user is still active
 *
 * Call at the very top of every protected page, after session_start().
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: /apple-login/index.php');
        exit;
    }

    // Idle timeout — close session after 30 minutes of inactivity
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if ((time() - $lastActivity) > IDLE_TIMEOUT) {
        destroySession();
        header('Location: /apple-login/index.php?reason=timeout');
        exit;
    }

    // Refresh activity timestamp on every authenticated request
    $_SESSION['last_activity'] = time();

    // DB revalidation: ensure the user has not been deactivated since login
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch();

        if (!$row || !(int) $row['is_active']) {
            destroySession();
            header('Location: /apple-login/index.php?reason=deactivated');
            exit;
        }
    } catch (PDOException $e) {
        error_log('requireAuth DB check failed: ' . $e->getMessage());
        // On DB failure we do NOT kick the user out — fail open to avoid
        // locking everyone out during a DB hiccup. Log and continue.
    }
}

/**
 * Destroys the current session and clears its cookie.
 */
function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
