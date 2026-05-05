<?php
/**
 * api/v1/resources/auth.php — Authentication resource handler.
 *
 * Routes:
 *   POST /api/v1/auth/change-required-password
 *       Change a temporary password when must_change_password = 1.
 *       Requires an active authenticated session (any role).
 *       Body (JSON): { "new_password": "...", "confirm_password": "..." }
 *
 * Security:
 *   - Requires valid session (isLoggedIn()).
 *   - Validates must_change_password flag from session.
 *   - Full password policy enforced via Validator::validatePassword().
 *   - Only the bcrypt hash is stored; plaintext never persisted.
 *   - Session ID is regenerated after password change.
 *   - Audit log records the event.
 */

require_once __DIR__ . '/../../../includes/Validator.php';
require_once __DIR__ . '/../../../includes/audit.php';
require_once __DIR__ . '/../../../includes/auth.php';

function handleAuth(string $method, string $sub): void
{
    match (true) {
        $method === 'POST' && $sub === 'change-required-password' => handleChangeRequiredPassword(),
        default => jsonError('Not found', 404),
    };
}

/**
 * POST /api/v1/auth/change-required-password
 *
 * Allows an authenticated user to satisfy the must_change_password requirement.
 *
 * Request body:
 *   {
 *     "new_password":     "...",   // string, 12–128 chars
 *     "confirm_password": "..."    // must match new_password
 *   }
 *
 * Success 200:
 *   { "ok": true, "message": "Password changed successfully." }
 *
 * Error 401 — not logged in
 * Error 403 — must_change_password is not required right now
 * Error 422 — validation failure
 * Error 500 — database error
 */
function handleChangeRequiredPassword(): void
{
    // Ensure the user has an active session.
    if (!isLoggedIn()) {
        jsonError('Unauthorized: not logged in', 401);
    }

    // Confirm the password change is actually required.
    if (empty($_SESSION['must_change_password'])) {
        jsonError('Forbidden: password change is not required for this session', 403);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId === 0) {
        jsonError('Unauthorized: session missing user_id', 401);
    }

    $body           = parseBody();
    $newPassword     = (string) ($body['new_password']     ?? '');
    $confirmPassword = (string) ($body['confirm_password'] ?? '');

    // Hard-cap at 128 chars (bcrypt DoS guard)
    if (strlen($newPassword) > 128) {
        $newPassword = substr($newPassword, 0, 128);
    }

    if ($newPassword === '') {
        jsonError('new_password is required', 422);
    }

    if ($newPassword !== $confirmPassword) {
        jsonError('Passwords do not match', 422);
    }

    $policy = Validator::validatePassword($newPassword);
    if (!$policy['ok']) {
        jsonError('Password does not meet policy requirements: ' . implode(', ', $policy['errors']), 422);
    }

    try {
        $pdo = getDB();

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $pdo->prepare(
            'UPDATE users
                SET password_hash                  = ?,
                    must_change_password            = 0,
                    temporary_password_created_at   = NULL,
                    temporary_password_expires_at   = NULL
              WHERE id = ?'
        )->execute([$newHash, $userId]);

        auditLog('forced_password_change_completed', 'info', null, $userId, [
            'username' => $_SESSION['username'] ?? '',
            'via'      => 'api',
        ]);

        // Clear the must_change_password flag from the session.
        $_SESSION['must_change_password'] = 0;
        unset($_SESSION['must_change_password']);

        // Regenerate session ID after credential change.
        session_regenerate_id(true);

        jsonOk(['message' => 'Password changed successfully.']);

    } catch (Throwable $e) {
        error_log('[api/auth/change-required-password] DB error: ' . $e->getMessage());
        jsonError('Internal server error', 500);
    }
}
