<?php
/**
 * /apple-login/dashboard.php — Protected area
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/includes/auth.php';

// Protect this page — redirect if not authenticated
requireAuth();

$username = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
$loginAt  = date('F j, Y \a\t g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!-- Prevent caching of authenticated page -->
    <meta http-equiv="Cache-Control" content="no-store">
    <title>Dashboard — Local App</title>
    <link rel="stylesheet" href="/apple-login/css/style.css">
</head>
<body>

    <div class="card dashboard-card" role="main">

        <!-- Avatar with initial -->
        <div class="welcome-avatar" aria-hidden="true"><?= $initial ?></div>

        <h1 class="welcome-title">Welcome, <?= $username ?></h1>
        <p class="welcome-subtitle">
            You have successfully signed in to your local account.
        </p>

        <!-- Session meta -->
        <div class="meta-list" aria-label="Session details">
            <div><strong>Status</strong> &nbsp; Active session</div>
            <div><strong>Signed in</strong> &nbsp; <?= $loginAt ?></div>
            <div><strong>User ID</strong> &nbsp; #<?= (int) ($_SESSION['user_id'] ?? 0) ?></div>
        </div>

        <!-- Sign out -->
        <form method="POST" action="/apple-login/logout.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(
                       (function () {
                           if (empty($_SESSION['csrf_token'])) {
                               $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                           }
                           return $_SESSION['csrf_token'];
                       })(),
                       ENT_QUOTES, 'UTF-8'
                   ) ?>">
            <button type="submit" class="btn-secondary" style="margin:0 auto;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Sign out
            </button>
        </form>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

</body>
</html>
