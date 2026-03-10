<?php
/**
 * includes/lang.php — Language / i18n helper.
 *
 * Supported languages: 'es' (default), 'en'
 * Usage:
 *   require_once __DIR__ . '/lang.php';
 *   initLang();          // call once after session_start()
 *   echo t('sign_in');   // outputs translated string
 */

define('SUPPORTED_LANGS', ['es', 'en']);
define('DEFAULT_LANG',    'es');

/**
 * Initialises the session language preference.
 *
 * Priority order:
 *  1. GET ?set_lang=xx  → stores in session (and DB if logged in), then redirects (PRG).
 *  2. Existing $_SESSION['lang']
 *  3. DEFAULT_LANG ('es')
 */
function initLang(): void
{
    // Language-change request via GET (PRG pattern — avoids POST conflicts)
    if (!empty($_GET['set_lang']) && in_array($_GET['set_lang'], SUPPORTED_LANGS, true)) {
        $_SESSION['lang'] = $_GET['set_lang'];

        // Persist to DB if a user is already authenticated
        if (!empty($_SESSION['user_id'])) {
            try {
                require_once __DIR__ . '/../config/db.php';
                $pdo  = getDB();
                $stmt = $pdo->prepare('UPDATE users SET preferred_language = ? WHERE id = ?');
                $stmt->execute([$_SESSION['lang'], (int) $_SESSION['user_id']]);
            } catch (PDOException $e) {
                error_log('lang persist error: ' . $e->getMessage());
            }
        }

        // Redirect to clean URL (remove set_lang param)
        $base = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $base);
        exit;
    }

    // Ensure session has a valid language
    if (empty($_SESSION['lang']) || !in_array($_SESSION['lang'], SUPPORTED_LANGS, true)) {
        $_SESSION['lang'] = DEFAULT_LANG;
    }
}

/**
 * Returns the translation strings array for the active language.
 */
function getLangStrings(): array
{
    $lang = $_SESSION['lang'] ?? DEFAULT_LANG;
    $file = __DIR__ . "/../lang/{$lang}.php";

    if (!file_exists($file)) {
        $file = __DIR__ . '/../lang/' . DEFAULT_LANG . '.php';
    }

    return require $file;
}

/**
 * Translates a single key, with optional sprintf formatting.
 *
 * @param  string $key   Translation key
 * @param  mixed  ...$args  Values for sprintf placeholders
 * @return string
 */
function t(string $key, ...$args): string
{
    static $strings = null;
    if ($strings === null) {
        $strings = getLangStrings();
    }

    $val = $strings[$key] ?? $key;
    return $args ? vsprintf($val, $args) : $val;
}

/**
 * Returns the current active language code.
 */
function currentLang(): string
{
    return $_SESSION['lang'] ?? DEFAULT_LANG;
}
