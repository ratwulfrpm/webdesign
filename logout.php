<?php
/**
 * /apple-login/logout.php — Destroys session and redirects to login
 */

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Only accept POST with valid CSRF token to prevent logout CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
}

destroySession();

header('Location: /apple-login/index.php');
exit;
