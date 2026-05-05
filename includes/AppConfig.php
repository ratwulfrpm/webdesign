<?php
/**
 * includes/AppConfig.php — Application environment configuration.
 *
 * Reads APP_ENV from the server / PHP environment (getenv / $_ENV).
 * Set it in your web-server config, php.ini, .htaccess, or MAMP's
 * environment-variable panel.
 *
 * Valid values: 'dev' | 'staging' | 'prod'
 * Default:      'prod'  (safe default — never expose debug info by accident)
 *
 * Example (.htaccess):
 *   SetEnv APP_ENV dev
 *
 * Example (php.ini / MAMP):
 *   env[APP_ENV] = dev
 *
 * Usage:
 *   AppConfig::env()       → 'dev' | 'staging' | 'prod'
 *   AppConfig::isDev()     → true in dev
 *   AppConfig::isStaging() → true in staging
 *   AppConfig::isProd()    → true in prod (also the safe default)
 */
class AppConfig
{
    private static ?string $envCache = null;

    /**
     * Return the current environment string.
     * Falls back to 'prod' if APP_ENV is missing or unrecognised.
     */
    public static function env(): string
    {
        if (self::$envCache !== null) {
            return self::$envCache;
        }

        // getenv() reads server/PHP env; $_ENV is populated when variables_order includes 'E'
        $raw = trim((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '')));
        $val = strtolower($raw);

        if (!in_array($val, ['dev', 'staging', 'prod'], true)) {
            $val = 'prod'; // safe default — assume production if unknown
        }

        self::$envCache = $val;
        return self::$envCache;
    }

    /** True only in development. */
    public static function isDev(): bool
    {
        return self::env() === 'dev';
    }

    /** True only in staging. */
    public static function isStaging(): bool
    {
        return self::env() === 'staging';
    }

    /**
     * True in production (or when APP_ENV is not set / unrecognised).
     * This is the conservative default — err on the side of security.
     */
    public static function isProd(): bool
    {
        return self::env() === 'prod';
    }
}
