<?php
/**
 * includes/SafeLogger.php — Environment-aware secure logging helper.
 *
 * Rules (OWASP A09 — Logging & Monitoring Failures):
 *   PROD  → never log passwords, tokens, verification codes, or full email bodies.
 *           log only event metadata (who, what, when) with masked identifiers.
 *   DEV   → allows more detail; every sensitive entry is prefixed "[DEV ONLY]"
 *           so it is instantly recognisable and easy to grep/exclude.
 *
 * All writes go to logs/app.log (separate from mail.log).
 *
 * Usage:
 *   SafeLogger::info('user_login | user_id=42');
 *   SafeLogger::error('smtp_failure | detail=' . $e->getMessage());
 *   SafeLogger::debug('[DEV ONLY] raw payload = ' . json_encode($data));
 *   SafeLogger::secureEvent('password_reset_sent',
 *       ['user_id' => 42, 'email' => 'u***@example.com'],
 *       ['temp_password' => $plain]   // only logged in DEV
 *   );
 */

require_once __DIR__ . '/AppConfig.php';

class SafeLogger
{
    private static string $logDir = '';

    // ── Logging directory ─────────────────────────────────────

    private static function logDir(): string
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
        }
        return self::$logDir;
    }

    // ── Internal write ────────────────────────────────────────

    private static function write(string $level, string $msg): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] [%-12s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $msg
        );
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND | LOCK_EX);
    }

    // ── Public API ────────────────────────────────────────────

    /** Log an informational message (all environments). */
    public static function info(string $msg): void
    {
        self::write('INFO', $msg);
    }

    /** Log an error (all environments). */
    public static function error(string $msg): void
    {
        self::write('ERROR', $msg);
    }

    /**
     * Log a debug message — silenced in PROD, written in DEV/staging.
     * Always prefix sensitive content with "[DEV ONLY]".
     */
    public static function debug(string $msg): void
    {
        if (!AppConfig::isProd()) {
            self::write('DEBUG [DEV ONLY]', $msg);
        }
    }

    /**
     * Log a security-sensitive event safely.
     *
     * @param string $event   Short event name (e.g. 'password_reset_sent')
     * @param array  $safe    Fields safe to log in ALL environments
     *                        (user_id, masked email, boolean flags, etc.)
     * @param array  $devOnly Fields logged ONLY in DEV — passwords, tokens,
     *                        verification codes, full email bodies.
     *                        Silently discarded in staging and prod.
     */
    public static function secureEvent(
        string $event,
        array  $safe    = [],
        array  $devOnly = []
    ): void {
        $msg = $event;

        if (!empty($safe)) {
            $msg .= ' | ' . self::formatFields($safe);
        }

        if (!empty($devOnly) && AppConfig::isDev()) {
            $msg .= ' | [DEV ONLY] ' . self::formatFields($devOnly);
        }

        self::info($msg);
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Mask an email address for safe logging.
     * "user@example.com" → "u***@example.com"
     */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return '***';
        }
        $local  = $parts[0];
        $masked = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1));
        return $masked . '@' . $parts[1];
    }

    /**
     * Mask a token/URL for safe logging (show only first 6 chars).
     * "abc123defxyz..." → "abc123***"
     */
    public static function maskToken(string $token): string
    {
        return substr($token, 0, 6) . '***';
    }

    private static function formatFields(array $fields): string
    {
        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
        }
        return implode(', ', $parts);
    }
}
