<?php
/**
 * includes/Validator.php — Centralized field limits and validation rules.
 *
 * Purpose:
 *   Single source of truth for:
 *   - Field length limits (to keep DB column sizes, Input.php, and API docs in sync)
 *   - Common validation rules (email, URL, phone, alphanumeric, etc.)
 *   - Business-logic constraints (price ranges, validity window, etc.)
 *
 * Usage:
 *   Validator::maxLen('product_name')            → 150
 *   Validator::email($raw)                       → bool
 *   Validator::inRange($value, 0, 100)           → bool
 *   Validator::validityDays($days)               → bool (1-7)
 *   Validator::isAlphanumericSlug($slug)         → bool
 */

final class Validator
{
    // ── Canonical field length limits ──────────────────────────
    // Keys mirror DB column names where applicable.
    // Update this table when changing DB schema or API limits.

    public const LIMITS = [
        // Users
        'username'              => 50,
        'email'                 => 254,
        'full_name'             => 100,
        'password'              => 72,   // bcrypt max effective length

        // Companies / organizations
        'company_name'          => 150,
        'org_name'              => 150,
        'org_slug'              => 80,
        'org_description'       => 500,
        'tax_id'                => 50,
        'legal_rep_name'        => 100,
        'legal_rep_id'          => 50,

        // Contact
        'phone_code'            => 10,
        'phone_number'          => 25,
        'country'               => 100,
        'addr_street'           => 255,
        'addr_city'             => 100,
        'addr_state'            => 100,
        'addr_zip'              => 20,

        // Products
        'product_name'          => 150,
        'product_code'          => 80,   // supplier_product_code, internal_product_code
        'technical_description' => 5000,

        // Assignments / Quotes
        'customer_name'         => 100,
        'special_conditions'    => 3000,
        'notes'                 => 2000,
        'keyword'               => 40,

        // Invitations
        'inv_email'             => 254,

        // Contracts
        'contract_notes'        => 2000,
        'review_comment'        => 1000,

        // Generic
        'short'                 => 255,
        'medium'                => 500,
    ];

    // ── Business-logic numeric limits ──────────────────────────

    public const PRICE_MIN           = 0.0;
    public const PRICE_MAX           = 9_999_999.99;
    public const PERCENTAGE_MIN      = 0.0;
    public const PERCENTAGE_MAX      = 999.0;   // profit % can exceed 100
    public const DISCOUNT_MIN        = 0.0;
    public const DISCOUNT_MAX        = 100.0;
    public const TRANSPORT_MAX       = 100.0;   // transport % max
    public const TAX_MAX             = 100.0;   // tax % max
    public const VALIDITY_DAYS_MIN   = 1;
    public const VALIDITY_DAYS_MAX   = 7;       // max link validity
    public const MAX_KEYWORDS        = 30;      // per product
    public const PAGINATION_MAX      = 500;     // max page size / offset guard

    // ── API response error codes ───────────────────────────────

    public const HTTP_BAD_REQUEST    = 400;
    public const HTTP_UNAUTHORIZED   = 401;
    public const HTTP_FORBIDDEN      = 403;
    public const HTTP_NOT_FOUND      = 404;
    public const HTTP_METHOD_NA      = 405;
    public const HTTP_UNPROCESSABLE  = 422;
    public const HTTP_TOO_MANY_REQ   = 429;
    public const HTTP_SERVER_ERROR   = 500;

    // ── Lookup methods ─────────────────────────────────────────

    /**
     * Return the max length for a named field.
     * Falls back to 255 if the key is not defined.
     *
     * @param  string $field  Key from LIMITS
     * @return int
     */
    public static function maxLen(string $field): int
    {
        return self::LIMITS[$field] ?? 255;
    }

    // ── Validation predicates ──────────────────────────────────

    /**
     * Validate an email address (RFC-5321 format).
     *
     * @param  mixed  $value
     * @return bool
     */
    public static function email(mixed $value): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }
        $v = trim((string) $value);
        if (strlen($v) > self::LIMITS['email']) {
            return false;
        }
        return (bool) filter_var($v, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Validate a numeric value is within [min, max] (inclusive).
     *
     * @param  mixed      $value
     * @param  float|null $min
     * @param  float|null $max
     * @return bool
     */
    public static function inRange(mixed $value, ?float $min = null, ?float $max = null): bool
    {
        if (!is_numeric($value)) return false;
        $n = (float) $value;
        if ($min !== null && $n < $min) return false;
        if ($max !== null && $n > $max) return false;
        return true;
    }

    /**
     * Validate a price value (positive decimal).
     *
     * @param  mixed $value
     * @return bool
     */
    public static function price(mixed $value): bool
    {
        return self::inRange($value, self::PRICE_MIN, self::PRICE_MAX);
    }

    /**
     * Validate a percentage used for discount (0-100).
     *
     * @param  mixed $value
     * @return bool
     */
    public static function discountPct(mixed $value): bool
    {
        return self::inRange($value, self::DISCOUNT_MIN, self::DISCOUNT_MAX);
    }

    /**
     * Validate a general percentage (0–999, e.g. profit margin).
     *
     * @param  mixed $value
     * @return bool
     */
    public static function percentage(mixed $value): bool
    {
        return self::inRange($value, self::PERCENTAGE_MIN, self::PERCENTAGE_MAX);
    }

    /**
     * Validate a quote validity window in days (1–7).
     *
     * @param  mixed $days
     * @return bool
     */
    public static function validityDays(mixed $days): bool
    {
        return self::inRange($days, self::VALIDITY_DAYS_MIN, self::VALIDITY_DAYS_MAX)
            && is_numeric($days)
            && (int) $days == $days;  // must be integer
    }

    /**
     * Validate a slug: lowercase alphanumeric and hyphens only.
     *
     * @param  string $slug
     * @return bool
     */
    public static function isSlug(string $slug): bool
    {
        return $slug !== ''
            && strlen($slug) <= self::LIMITS['org_slug']
            && (bool) preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug);
    }

    /**
     * Validate a username: 3-50 chars, letters/digits/underscore/hyphen.
     *
     * @param  string $username
     * @return bool
     */
    public static function isUsername(string $username): bool
    {
        $len = mb_strlen($username, 'UTF-8');
        return $len >= 3
            && $len <= self::LIMITS['username']
            && (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $username);
    }

    /**
     * Validate a token: exactly 64 lowercase hex characters (SHA-256 hex input).
     *
     * @param  string $token
     * @return bool
     */
    public static function isHexToken64(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $token);
    }

    /**
     * Validate a single keyword: max LEN_KEYWORD chars, alphanumeric + dash/underscore.
     *
     * @param  string $kw
     * @return bool
     */
    public static function isKeyword(string $kw): bool
    {
        $len = mb_strlen($kw, 'UTF-8');
        return $len >= 1
            && $len <= self::LIMITS['keyword']
            && (bool) preg_match('/^[a-z0-9_\-]+$/i', $kw);
    }

    /**
     * Validate that a string does not contain raw HTML tags.
     * Use for fields that should be plain text only.
     *
     * @param  string $value
     * @return bool   True if NO HTML tags found
     */
    public static function noHtml(string $value): bool
    {
        return strip_tags($value) === $value;
    }

    // ── Password policy ───────────────────────────────────────

    /** Minimum length for a permanent (user-defined) password. */
    public const PASSWORD_MIN_LEN = 12;
    /** Maximum length accepted before bcrypt DoS cap. */
    public const PASSWORD_MAX_LEN = 128;

    /**
     * Validate a permanent password against the application policy.
     *
     * Rules:
     *   - 12–128 characters
     *   - At least one uppercase ASCII letter (A-Z)
     *   - At least one lowercase ASCII letter (a-z)
     *   - At least one ASCII digit (0-9)
     *   - Special characters are allowed but not required
     *   - Empty string is rejected
     *
     * Does NOT apply to the 36-char alphanumeric temporary passwords generated
     * by admin/owner; those follow their own generation rule.
     *
     * @param  string $password  The plaintext password to validate
     * @return array{ok: bool, errors: string[]}
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];

        if ($password === '') {
            $errors[] = 'password_empty';
            return ['ok' => false, 'errors' => $errors];
        }

        $len = strlen($password);   // byte length for bcrypt compatibility check

        if ($len < self::PASSWORD_MIN_LEN) {
            $errors[] = 'password_too_short';
        }
        if ($len > self::PASSWORD_MAX_LEN) {
            $errors[] = 'password_too_long';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'password_no_upper';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'password_no_lower';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'password_no_digit';
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }

    /**
     * Generate a cryptographically random 36-character alphanumeric
     * temporary password.
     *
     * Alphabet: uppercase A-Z (without I, O), lowercase a-z (without l),
     * digits 2-9 (without 0, 1) — avoids visually ambiguous characters.
     * Length: 36 chars → ~208 bits of entropy from this 57-symbol alphabet.
     *
     * SECURITY:
     *   - Uses random_int() (CSPRNG) for every character.
     *   - Never call this function more than once per reset — store only the hash.
     *   - Never log the return value.
     *
     * @return string  36-character alphanumeric temporary password (plain text)
     */
    public static function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $len      = strlen($alphabet);
        $password = '';
        for ($i = 0; $i < 36; $i++) {
            $password .= $alphabet[random_int(0, $len - 1)];
        }
        return $password;
    }
}
