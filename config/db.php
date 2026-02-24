<?php
/**
 * Database configuration (LOCAL ONLY — root/root is acceptable only in development)
 * For production, replace with a dedicated user with minimal privileges and a strong password.
 */

define('DB_HOST',     'localhost');
define('DB_PORT',     3306);        // MAMP default MySQL port. If you changed it in MAMP, update here.
define('DB_NAME',     'apple_login');
define('DB_USER',     'root');
define('DB_PASS',     'root');
define('DB_CHARSET',  'utf8mb4');

/**
 * Returns a PDO connection (singleton).
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never leak DB details to the browser
            error_log('DB connection failed: ' . $e->getMessage());
            die('A database error occurred. Please try again later.');
        }
    }

    return $pdo;
}
