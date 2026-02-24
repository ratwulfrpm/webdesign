<?php
/**
 * Authentication helpers.
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Attempts to log in a user by email/username.
 *
 * @param  string $identifier  Email or username submitted
 * @param  string $password    Plain-text password submitted
 * @return array|false         User row on success, false on failure
 */
function attemptLogin(string $identifier, string $password): array|false
{
    // Basic sanitation — trim whitespace
    $identifier = trim($identifier);

    if ($identifier === '' || $password === '') {
        return false;
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, username, email, password_hash
           FROM users
          WHERE email = :email OR username = :username
          LIMIT 1'
    );
    $stmt->execute([':email' => $identifier, ':username' => $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        // Use a dummy verify to keep timing consistent (prevent user enumeration)
        password_verify($password, '$2y$12$invaliddummyhashfortimingequalityXXXXXXXXXXXXXXXXXXXXX');
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Rehash if the algo/cost has changed
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$newHash, $user['id']]);
    }

    return $user;
}

/**
 * Creates a new authenticated session for the given user.
 */
function createSession(array $user): void
{
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['logged_in'] = true;
}

/**
 * Returns true if a valid session exists.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
}

/**
 * Redirects to login if no session, otherwise does nothing.
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: /apple-login/index.php');
        exit;
    }
}

/**
 * Destroys the current session completely.
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
