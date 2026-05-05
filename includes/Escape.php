<?php
/**
 * includes/Escape.php — Centralized output escaping (XSS prevention).
 *
 * Purpose:
 *   Every piece of user-controlled or database-sourced data that is
 *   rendered into HTML MUST pass through one of these functions.
 *
 * Functions:
 *   e($v)            — HTML entities (for HTML content and attribute values)
 *   Escape::html()   — same as e()
 *   Escape::attr()   — double-quoted attribute value (stricter subset of html)
 *   Escape::url()    — URL value safe to embed in href/src
 *   Escape::js()     — JSON-encode a PHP value for inline JS assignment
 *   Escape::css()    — strip dangerous chars from CSS values
 *
 * Never:
 *   - Use nl2br()/htmlspecialchars() scattered across templates.
 *   - Echo raw DB values.
 *   - Trust values that came from superglobals without escaping.
 *
 * Usage:
 *   <?= e($user['username']) ?>
 *   <?= Escape::html($product['product_name']) ?>
 *   href="<?= Escape::url($row['url']) ?>"
 *   data-value="<?= Escape::attr($value) ?>"
 *   <script>const name = <?= Escape::js($name) ?>;</script>
 */

// ── Global shorthand ───────────────────────────────────────────

/**
 * Escape a value for safe HTML output (content or attribute).
 * This is the canonical XSS escape used in templates.
 *
 * @param  mixed  $value  Any printable value; nulls → ''
 * @return string         HTML-entity-encoded string
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Class interface ────────────────────────────────────────────

final class Escape
{
    /**
     * Escape for HTML content and attributes.
     * Identical to the global e() shorthand.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function html(mixed $value): string
    {
        return e($value);
    }

    /**
     * Escape for use inside a double-quoted HTML attribute.
     * ENT_QUOTES ensures both ' and " are encoded.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function attr(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a URL for use in href, src, action, etc.
     *
     * Rules:
     *   - Whitelist scheme: only http, https, mailto, or relative paths.
     *   - All other characters are percent-encoded via rawurlencode on the
     *     dangerous characters only; the URL structure is preserved.
     *   - Returns '#' for any URL with a disallowed scheme (javascript:, data:, vbscript:).
     *
     * @param  mixed  $value
     * @return string  HTML-safe URL
     */
    public static function url(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') return '';

        // Reject javascript:, data:, vbscript:, etc.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'ftp'], true)) {
            return '#';
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Encode a PHP value for safe inline JavaScript assignment.
     * The result is valid JSON, safe for direct embedding in a <script> block.
     *
     * Usage:
     *   <script>const items = <?= Escape::js($items) ?>;</script>
     *
     * @param  mixed  $value  Any JSON-serializable value
     * @return string         JSON string, with HTML-dangerous chars escaped
     */
    public static function js(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Strip CSS-injection characters from a CSS property value.
     * Use only when you must embed a value in a style attribute or <style> block.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function css(mixed $value): string
    {
        // Remove characters that could escape a CSS context
        return preg_replace('/[^\w\s\-.,#%()]/', '', (string) ($value ?? ''));
    }

    /**
     * Escape for use in a LIKE SQL pattern (escapes %, _, \).
     * NOT an XSS function — use before building LIKE parameters.
     * Still bind the result via prepared statements.
     *
     * @param  string $v
     * @return string  Pattern wrapped in %...%
     */
    public static function likePattern(string $v): string
    {
        return '%' . addcslashes($v, '%_\\') . '%';
    }

    /**
     * Prepare an integer for safe embedding in numeric HTML contexts
     * (e.g. data attributes, URL query params).
     *
     * @param  mixed  $value
     * @return int
     */
    public static function int(mixed $value): int
    {
        return (int) $value;
    }
}
