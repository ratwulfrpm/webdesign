<?php
/**
 * api/v1/_helpers.php — Shared utilities for REST API v1.
 *
 * Provides: JSON response helpers, session-based RBAC guard,
 * body parsing, and input sanitization helpers.
 *
 * Security:
 *  - All JSON output is UTF-8 encoded.
 *  - Auth guard validates session role against allowed list.
 *  - intParam() rejects non-positive IDs immediately.
 *  - parseBody() only reads php://input for application/json content.
 *
 * IMPORTANT — naming:
 *  The web-layer requireAuth() (defined in includes/auth.php) and the
 *  API-layer requireApiAuth() defined here are deliberately different names
 *  to avoid PHP fatal redeclaration errors when both files are loaded in
 *  the same request (which is the case for api/v1/index.php).
 *  All API resource files must call requireApiAuth(), never requireAuth().
 */

// ── Response helpers ──────────────────────────────────────────

/**
 * Emit a JSON success response and exit.
 * $payload is merged with {'success':true}.
 */
function jsonOk(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode(
        array_merge(['success' => true], $payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Emit a JSON error response and exit.
 */
function jsonError(string $message, int $code = 400, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(
        array_merge(['success' => false, 'error' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ── Auth guard ────────────────────────────────────────────────

/**
 * API auth guard: require an active session with one of the given roles.
 *
 * Returns context array: ['user_id', 'role', 'org_id'].
 *
 * Named requireApiAuth (not requireAuth) to avoid PHP fatal redeclaration
 * conflict with the web-layer requireAuth(): void in includes/auth.php.
 * Both files are loaded by api/v1/index.php and must have distinct names.
 *
 * @param  string[] $roles Allowed roles, e.g. ['admin','owner']
 * @return array{user_id:int, role:string, org_id:int}
 */
function requireApiAuth(array $roles = ['admin', 'owner']): array
{
    if (empty($_SESSION['user_id'])) {
        jsonError('Unauthorized', 401);
    }

    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $roles, true)) {
        jsonError('Forbidden', 403);
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'role'    => $role,
        'org_id'  => (int) ($_SESSION['org_id'] ?? 0),
    ];
}

// ── Input helpers ─────────────────────────────────────────────

/**
 * Parse the request body.
 * Supports application/json (reads php://input) and multipart/form-data / x-www-form-urlencoded (reads $_POST).
 */
function parseBody(): array
{
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

/**
 * Cast and validate a value as a positive integer ID.
 * Calls jsonError(400) if invalid.
 */
function intParam(mixed $val, string $name = 'ID'): int
{
    $n = (int) $val;
    if ($n <= 0) {
        jsonError("{$name} must be a positive integer");
    }
    return $n;
}

/**
 * Trim and cap a string field.
 */
function strField(mixed $val, int $maxLen = 255): string
{
    return mb_substr(trim((string) $val), 0, $maxLen);
}

/**
 * Safe LIKE pattern — escapes %, _, \ in user input then wraps in %.
 */
function likeWrap(string $v): string
{
    return '%' . addcslashes($v, '%_\\') . '%';
}
