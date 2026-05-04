<?php
/**
 * /login/dashboard.php
 * Legacy entry point — redirects to the correct role-based screen.
 */
require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/includes/auth.php';

requireAuth();

if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'owner' || $_SESSION['role'] === 'support') {
    header('Location: /login/admin/products.php');
} elseif ($_SESSION['role'] === 'supplier' && (int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
} elseif ($_SESSION['role'] === 'supplier') {
    header('Location: /login/supplier/summary.php');
} else {
    destroySession();
    header('Location: /login/index.php?reason=unsupported_role');
}
exit;

