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
 *  - Body size capped at 512 KB to prevent memory DoS.
 *  - Input.php and Validator.php loaded for centralized field limits.
 *
 * IMPORTANT — naming:
 *  The web-layer requireAuth() (defined in includes/auth.php) and the
 *  API-layer requireApiAuth() defined here are deliberately different names
 *  to avoid PHP fatal redeclaration errors when both files are loaded in
 *  the same request (which is the case for api/v1/index.php).
 *  All API resource files must call requireApiAuth(), never requireAuth().
 */

// Load centralized helpers
require_once __DIR__ . '/../../includes/Input.php';
require_once __DIR__ . '/../../includes/Escape.php';
require_once __DIR__ . '/../../includes/Validator.php';

// ── Constants ─────────────────────────────────────────────────

/** Maximum accepted request body size (512 KB). */
define('API_MAX_BODY_BYTES', 524288);

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
 *
 * Standard error codes:
 *   400 — bad request / invalid input
 *   401 — unauthenticated
 *   403 — forbidden
 *   404 — not found
 *   405 — method not allowed
 *   422 — unprocessable entity (validation errors)
 *   429 — too many requests
 *   500 — generic server error (no details leaked)
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

/**
 * Emit a 422 Unprocessable Entity JSON error with a field-level errors map.
 * Use this for form/body validation failures.
 *
 * @param  array<string, string>  $errors  field → message
 * @param  string                 $summary Optional top-level summary
 */
function jsonValidationError(array $errors, string $summary = 'Validation failed'): never
{
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => $summary,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emit a generic 500 error without revealing internal details.
 * Always log the real reason before calling this.
 */
function jsonServerError(string $logContext = ''): never
{
    if ($logContext !== '') {
        error_log('[API 500] ' . $logContext);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.'], JSON_UNESCAPED_UNICODE);
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
 * Body size is capped at API_MAX_BODY_BYTES to prevent memory exhaustion.
 */
function parseBody(): array
{
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json')) {
        // Guard against huge payloads
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > API_MAX_BODY_BYTES) {
            jsonError('Request body too large', 400);
        }
        $raw = fread(fopen('php://input', 'rb'), API_MAX_BODY_BYTES + 1);
        if (strlen($raw) > API_MAX_BODY_BYTES) {
            jsonError('Request body too large', 400);
        }
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
 * Trim, sanitize, and cap a string field from a parsed body or GET array.
 * Rejects arrays (returns '').
 *
 * @param  mixed  $val
 * @param  int    $maxLen
 * @return string
 */
function strField(mixed $val, int $maxLen = 255): string
{
    if (is_array($val) || is_object($val)) {
        return '';
    }
    return mb_substr(trim((string) $val), 0, $maxLen, 'UTF-8');
}

/**
 * Like strField but calls jsonError(422) if the result is empty.
 *
 * @param  mixed   $val
 * @param  string  $fieldName  For the error message
 * @param  int     $maxLen
 * @return string
 */
function strFieldRequired(mixed $val, string $fieldName, int $maxLen = 255): string
{
    $v = strField($val, $maxLen);
    if ($v === '') {
        jsonError("Field '{$fieldName}' is required", 422);
    }
    return $v;
}

/**
 * Read a float from a body/GET value. Returns null if invalid or out of range.
 *
 * @param  mixed      $val
 * @param  float|null $min
 * @param  float|null $max
 * @return float|null
 */
function floatField(mixed $val, ?float $min = null, ?float $max = null): ?float
{
    return Input::toDecimal($val, $min, $max);
}

/**
 * Safe LIKE pattern — escapes %, _, \ in user input then wraps in %.
 */
function likeWrap(string $v): string
{
    return '%' . addcslashes($v, '%_\\') . '%';
}

/**
 * Determine the client IP address for rate limiting / audit purposes.
 * Never trust this blindly for auth — only use for logging/rate-limiting.
 *
 * @return string
 */
function _publicClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v === '') continue;
        // X-Forwarded-For may contain multiple IPs; take the first (leftmost = client)
        $ip = trim(explode(',', $v)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}
