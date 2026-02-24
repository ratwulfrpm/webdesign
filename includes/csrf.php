<?php
/**
 * CSRF token helpers — session-based single-use token.
 */

/**
 * Generates (or reuses) a CSRF token stored in the session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Renders a hidden CSRF input field.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validates the submitted CSRF token.
 * Regenerates the token after validation to prevent replay.
 *
 * @throws RuntimeException if the token is invalid.
 */
function csrfValidate(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $stored    = $_SESSION['csrf_token'] ?? '';

    if (!$submitted || !$stored || !hash_equals($stored, $submitted)) {
        // Regenerate so the form can be resubmitted
        unset($_SESSION['csrf_token']);
        http_response_code(403);
        die('Invalid security token. Please go back and try again.');
    }

    // Rotate token after each validated request
    unset($_SESSION['csrf_token']);
}
