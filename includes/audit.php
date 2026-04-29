<?php
/**
 * includes/audit.php — Audit log helper for the product-assignments module.
 *
 * All writes are best-effort: a DB failure never interrupts the main request.
 * Requires config/db.php to be loaded before calling any function here.
 */

/**
 * Record an audit event to the audit_log table.
 *
 * @param string   $event        Short snake_case event identifier.
 * @param string   $severity     'info' | 'warning' | 'error'
 * @param int|null $assignmentId Related product_assignments.id (null = N/A).
 * @param int|null $userId       Authenticated user ID (null = anonymous).
 * @param array    $detail       Extra key/value data serialised as JSON.
 */
function auditLog(
    string $event,
    string $severity     = 'info',
    ?int   $assignmentId = null,
    ?int   $userId       = null,
    array  $detail       = []
): void {
    try {
        $pdo        = getDB();
        $ip         = _auditClientIp();
        $ua         = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $severityOk = in_array($severity, ['info', 'warning', 'error'], true)
                    ? $severity
                    : 'info';
        $detailJson = !empty($detail)
                    ? (json_encode($detail, JSON_UNESCAPED_UNICODE) ?: null)
                    : null;

        $pdo->prepare(
            'INSERT INTO audit_log
                (event, severity, assignment_id, user_id, ip_address, user_agent, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $event,
            $severityOk,
            $assignmentId,
            $userId,
            $ip,
            $ua !== '' ? $ua : null,
            $detailJson,
        ]);
    } catch (\Throwable $e) {
        // Best-effort: never let an audit failure crash the main request.
        error_log('auditLog() failed [' . $event . ']: ' . $e->getMessage());
    }
}

/**
 * Check whether an IP address has exceeded the allowed number of events
 * of a given type within a rolling time window.
 *
 * Returns true if the IP should be blocked (threshold reached or exceeded).
 *
 * @param string $event       The event type to count (e.g. 'quote_invalid_token').
 * @param string $ip          IP address to check.
 * @param int    $threshold   Maximum events allowed within the window.
 * @param int    $windowSecs  Rolling window in seconds (default 600 = 10 min).
 */
function auditIsRateLimited(
    string $event,
    string $ip,
    int    $threshold  = 20,
    int    $windowSecs = 600
): bool {
    try {
        $pdo   = getDB();
        $since = date('Y-m-d H:i:s', time() - $windowSecs);
        $stmt  = $pdo->prepare(
            'SELECT COUNT(*) FROM audit_log
              WHERE event = ? AND ip_address = ? AND created_at >= ?'
        );
        $stmt->execute([$event, $ip, $since]);
        return (int) $stmt->fetchColumn() >= $threshold;
    } catch (\Throwable $e) {
        // Fail open: a DB error must never block legitimate traffic.
        error_log('auditIsRateLimited() failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Return the real client IP address.
 *
 * Uses only REMOTE_ADDR (set by the web server) to prevent IP spoofing
 * via forged HTTP headers such as X-Forwarded-For.
 * If a trusted reverse-proxy is used, adjust this function accordingly.
 */
function _auditClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
