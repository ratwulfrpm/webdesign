<?php
/**
 * /login/logout.php — Destroys session and redirects to login
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

// Normal logout: POST with CSRF token
// Cancel from org-picker: GET with ?cancel=1 (pending session only — no CSRF needed)
$isCancel = ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['cancel'] ?? '') === '1');

if ($isCancel) {
    // Only allow cancellation if there is a pending (not fully logged-in) session
    if (!isPendingLogin()) {
        header('Location: /login/index.php');
        exit;
    }
} else {
    csrfValidate();
}

destroySession();

header('Location: /login/index.php');
exit;
