<?php
/**
 * /login/dashboard.php
 * Legacy entry point — redirects to the correct role-based screen.
 */
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/includes/auth.php';

requireAuth();

if ($_SESSION['role'] === 'admin') {
    header('Location: /login/admin/index.php');
} elseif ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
} else {
    header('Location: /login/supplier/summary.php');
}
exit;
