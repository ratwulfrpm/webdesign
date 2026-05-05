<?php
/**
 * includes/Input.php — Centralized secure input handling.
 *
 * Purpose:
 *   Provide a single point of entry for reading, sanitizing, trimming,
 *   and length-capping all external input ($_GET, $_POST, raw values).
 *
 * Design decisions:
 *   - All methods are static (no instantiation needed).
 *   - Arrays passed where a scalar is expected are rejected and return ''/0/null.
 *   - Strings are always trim()ed and mb_substr()-capped to the limit.
 *   - Encoding is validated/forced to UTF-8; invalid byte sequences are stripped.
 *   - No HTML encoding is performed here — that is Escape::html() / e()'s job.
 *   - Numeric helpers validate ranges and return null on invalid input.
 *   - Enum helpers validate against an explicit whitelist (no open sets).
 *
 * Usage examples:
 *   $name   = Input::postString('customer_name', 100, required: true);
 *   $page   = Input::getInt('page', min: 1, max: 9999);
 *   $type   = Input::postEnum('base_type', ['fob', 'cif'], required: true);
 *   $pct    = Input::postDecimal('discount', min: 0.0, max: 100.0);
 *   $desc   = Input::postText('technical_description', 5000);
 *   $kw     = Input::cleanString($raw, 40);
 */

final class Input
{
    // ── Field length limits (canonical reference) ─────────────
    // These constants mirror Validator::LIMITS and can be used directly.

    public const LEN_USERNAME        = 50;
    public const LEN_EMAIL           = 254;
    public const LEN_FULL_NAME       = 100;
    public const LEN_COMPANY_NAME    = 150;
    public const LEN_PRODUCT_NAME    = 150;
    public const LEN_PRODUCT_CODE    = 80;
    public const LEN_PHONE           = 25;
    public const LEN_COUNTRY         = 100;
    public const LEN_ADDRESS         = 255;
    public const LEN_CITY            = 100;
    public const LEN_STATE           = 100;
    public const LEN_ZIP             = 20;
    public const LEN_DESCRIPTION     = 5000;
    public const LEN_SPECIAL_COND    = 3000;
    public const LEN_NOTES           = 2000;
    public const LEN_KEYWORD         = 40;
    public const LEN_SLUG            = 80;
    public const LEN_SHORT           = 255;   // generic short field
    public const LEN_MEDIUM          = 500;   // generic medium field

    // ── POST input ─────────────────────────────────────────────

    /**
     * Read a string from $_POST[].
     * Returns '' (or throws on $required) if missing/empty/array.
     *
     * @param  string   $key      POST key
     * @param  int      $maxLen   Maximum byte/char length after trim (default 255)
     * @param  bool     $required Trigger a validation error flag via Input::$errors
     * @return string
     */
    public static function postString(string $key, int $maxLen = 255, bool $required = false): string
    {
        return self::_string($_POST, $key, $maxLen, $required);
    }

    /**
     * Read a multi-line text from $_POST[]. Allows newlines; trims outer whitespace.
     *
     * @param  string  $key
     * @param  int     $maxLen  default 5000
     * @param  bool    $required
     * @return string
     */
    public static function postText(string $key, int $maxLen = 5000, bool $required = false): string
    {
        return self::_string($_POST, $key, $maxLen, $required);
    }

    /**
     * Read an integer from $_POST[].
     * Returns null if missing/non-numeric/out-of-range.
     *
     * @param  string    $key
     * @param  int|null  $min
     * @param  int|null  $max
     * @param  bool      $required  If true, pushes a validation flag
     * @return int|null
     */
    public static function postInt(string $key, ?int $min = null, ?int $max = null, bool $required = false): ?int
    {
        return self::_int($_POST, $key, $min, $max, $required);
    }

    /**
     * Read a decimal/float from $_POST[].
     * Accepts comma as decimal separator (e.g. "1.234,56" → 1234.56 handled by normalization).
     * Returns null if missing/non-numeric/out-of-range.
     *
     * @param  string     $key
     * @param  float|null $min
     * @param  float|null $max
     * @param  bool       $required
     * @return float|null
     */
    public static function postDecimal(string $key, ?float $min = null, ?float $max = null, bool $required = false): ?float
    {
        return self::_decimal($_POST, $key, $min, $max, $required);
    }

    /**
     * Read an enum value from $_POST[]. Returns '' if not in $allowed.
     *
     * @param  string   $key
     * @param  string[] $allowed  Whitelist of accepted string values
     * @param  bool     $required
     * @return string   The matched value or ''
     */
    public static function postEnum(string $key, array $allowed, bool $required = false): string
    {
        return self::_enum($_POST, $key, $allowed, $required);
    }

    // ── GET input ──────────────────────────────────────────────

    /**
     * Read a string from $_GET[].
     */
    public static function getString(string $key, int $maxLen = 255, bool $required = false): string
    {
        return self::_string($_GET, $key, $maxLen, $required);
    }

    /**
     * Read an integer from $_GET[].
     */
    public static function getInt(string $key, ?int $min = null, ?int $max = null, bool $required = false): ?int
    {
        return self::_int($_GET, $key, $min, $max, $required);
    }

    /**
     * Read an enum from $_GET[].
     */
    public static function getEnum(string $key, array $allowed, string $default = ''): string
    {
        $v = self::_string($_GET, $key, 60);
        return in_array($v, $allowed, true) ? $v : $default;
    }

    // ── Raw value helpers ──────────────────────────────────────

    /**
     * Sanitize an arbitrary string value (trim, length cap, UTF-8 enforce).
     * Use this when you already have a value (not reading from superglobals).
     *
     * @param  mixed  $value   Any value; arrays are converted to ''
     * @param  int    $maxLen
     * @return string
     */
    public static function cleanString(mixed $value, int $maxLen = 255): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $s = trim((string) $value);
        $s = self::_enforceUtf8($s);
        return mb_substr($s, 0, $maxLen, 'UTF-8');
    }

    /**
     * Normalize a search keyword:
     *  - lowercase
     *  - strip anything that isn't alphanumeric, hyphen, or underscore
     *  - trim + cap to LEN_KEYWORD
     *
     * @param  mixed $value
     * @return string  Empty string if the result is invalid/empty
     */
    public static function normalizeKeyword(mixed $value): string
    {
        $s = strtolower(self::cleanString($value, self::LEN_KEYWORD + 10));
        $s = preg_replace('/[^a-z0-9\-_]/', '', $s);
        return mb_substr(trim($s), 0, self::LEN_KEYWORD, 'UTF-8');
    }

    /**
     * Sanitize a slug: lowercase alphanumeric + hyphens only.
     *
     * @param  mixed $value
     * @return string
     */
    public static function cleanSlug(mixed $value): string
    {
        $s = strtolower(self::cleanString($value, self::LEN_SLUG + 10));
        $s = preg_replace('/[^a-z0-9\-]/', '', $s);
        return mb_substr(trim($s, '-'), 0, self::LEN_SLUG, 'UTF-8');
    }

    /**
     * Normalize a decimal string: accepts comma and space as thousands separators,
     * or comma as decimal separator (European format).
     * Returns null on invalid input.
     *
     * @param  mixed     $value
     * @param  float|null $min
     * @param  float|null $max
     * @return float|null
     */
    public static function toDecimal(mixed $value, ?float $min = null, ?float $max = null): ?float
    {
        if (is_array($value) || $value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        // Normalize: remove spaces and currency symbols
        $s = str_replace([' ', '$', '€', '£', '¥'], '', $s);
        // If comma used as thousands separator (e.g. "1,234.56") → remove commas
        if (preg_match('/\d,\d{3}\./', $s)) {
            $s = str_replace(',', '', $s);
        } else {
            // European format: "1.234,56" → "1234.56"
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        if (!is_numeric($s)) {
            return null;
        }
        $n = (float) $s;
        if ($min !== null && $n < $min) return null;
        if ($max !== null && $n > $max) return null;
        return $n;
    }

    // ── Validation error accumulator ───────────────────────────
    // Simple per-request accumulator. Use in forms to collect errors.

    /** @var array<string, string> */
    private static array $errors = [];

    /**
     * Record a validation error for a specific field.
     */
    public static function addError(string $field, string $message): void
    {
        self::$errors[$field] = $message;
    }

    /**
     * Returns true if no errors have been recorded.
     */
    public static function isValid(): bool
    {
        return empty(self::$errors);
    }

    /**
     * Returns the full errors array (field → message).
     * @return array<string, string>
     */
    public static function errors(): array
    {
        return self::$errors;
    }

    /**
     * Reset the error accumulator (call at the start of each POST handler).
     */
    public static function resetErrors(): void
    {
        self::$errors = [];
    }

    // ── Private helpers ────────────────────────────────────────

    /** @param array<string,mixed> $source */
    private static function _string(array $source, string $key, int $maxLen, bool $required): string
    {
        $raw = $source[$key] ?? '';

        // Reject array injection
        if (is_array($raw) || is_object($raw)) {
            if ($required) {
                self::$errors[$key] = "Field '{$key}' must be a string.";
            }
            return '';
        }

        $v = trim((string) $raw);
        $v = self::_enforceUtf8($v);
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');

        if ($required && $v === '') {
            self::$errors[$key] = "Field '{$key}' is required.";
        }

        return $v;
    }

    /** @param array<string,mixed> $source */
    private static function _int(array $source, string $key, ?int $min, ?int $max, bool $required): ?int
    {
        $raw = $source[$key] ?? '';

        if (is_array($raw) || $raw === '' || $raw === null) {
            if ($required) {
                self::$errors[$key] = "Field '{$key}' is required.";
            }
            return null;
        }

        $s = trim((string) $raw);
        if (!ctype_digit(ltrim($s, '-')) || $s === '-') {
            if ($required) {
                self::$errors[$key] = "Field '{$key}' must be an integer.";
            }
            return null;
        }

        $n = (int) $s;
        if ($min !== null && $n < $min) {
            if ($required) {
                self::$errors[$key] = "Field '{$key}' must be ≥ {$min}.";
            }
            return null;
        }
        if ($max !== null && $n > $max) {
            if ($required) {
                self::$errors[$key] = "Field '{$key}' must be ≤ {$max}.";
            }
            return null;
        }

        return $n;
    }

    /** @param array<string,mixed> $source */
    private static function _decimal(array $source, string $key, ?float $min, ?float $max, bool $required): ?float
    {
        $raw = $source[$key] ?? '';
        if (is_array($raw) || $raw === '' || $raw === null) {
            if ($required) {
                self::$errors[$key] = "Field '{$key}' is required.";
            }
            return null;
        }
        $n = self::toDecimal($raw, $min, $max);
        if ($n === null && $required) {
            self::$errors[$key] = "Field '{$key}' has an invalid numeric value.";
        }
        return $n;
    }

    /** @param array<string,mixed> $source */
    private static function _enum(array $source, string $key, array $allowed, bool $required): string
    {
        $v = self::_string($source, $key, 100, $required);
        if ($v !== '' && !in_array($v, $allowed, true)) {
            self::$errors[$key] = "Field '{$key}' has an invalid value.";
            return '';
        }
        return $v;
    }

    /**
     * Enforce UTF-8 encoding: strip invalid byte sequences.
     */
    private static function _enforceUtf8(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return $s;
    }
}
