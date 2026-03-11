<?php
/**
 * /login/user/dashboard.php — General user dashboard
 *
 * Access: role = 'user' only.
 * Features:
 *  - Displays welcome card with username, role badge, session info
 *  - Language selector
 *  - 30-min idle timeout enforced by requireAuth()
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

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/tabs.php';

// Auth + RBAC checks
requireAuth();
initLang();
requireRole(['user']);

$username = htmlspecialchars($_SESSION['username'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
$lang     = currentLang();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('user_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=5">
</head>
<body>

    <!-- Language selector -->
    <div class="lang-selector">
        <a href="?set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>">ES</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>">EN</a>
    </div>

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= t('user_title') ?>
                <span class="org-badge"><?= htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </div>
        <form method="POST" action="/login/logout.php" class="top-bar-logout">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-secondary btn-sm">
                <?= t('sign_out') ?>
            </button>
        </form>
    </div>

    <?= renderTabs('dashboard') ?>

    <div class="page-content">

        <!-- Welcome card -->
        <section class="panel-section">
            <div class="welcome-container" style="text-align:center;padding:48px 24px;">
                <div class="welcome-avatar" style="margin:0 auto 20px;"><?= $initial ?></div>
                <h1 class="welcome-name"><?= t('user_welcome', $username) ?></h1>
                <p style="margin:8px 0 0;">
                    <span class="badge badge-user"><?= t('role_user') ?></span>
                </p>
                <p class="text-muted" style="margin-top:24px;font-size:0.92rem;">
                    <?= t('user_session_info') ?>
                </p>
                <p class="text-muted" style="font-size:0.85rem;">
                    <?= t('user_idle_notice', (int)(IDLE_TIMEOUT / 60)) ?>
                </p>
            </div>
        </section>

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <!-- Idle-timeout warning -->
    <script>
    (function () {
        const TIMEOUT_MS  = <?= IDLE_TIMEOUT * 1000 ?>;
        const WARNING_MS  = TIMEOUT_MS - 5 * 60 * 1000;
        const LOGIN_URL   = '/login/index.php?reason=timeout';

        let lastActivity  = Date.now();
        let warnShown     = false;

        function resetTimer() { lastActivity = Date.now(); warnShown = false; }
        ['mousemove','keydown','click','scroll','touchstart'].forEach(ev =>
            document.addEventListener(ev, resetTimer, { passive: true })
        );

        setInterval(function () {
            const idle = Date.now() - lastActivity;
            if (idle >= TIMEOUT_MS) {
                window.location.href = LOGIN_URL;
            } else if (idle >= WARNING_MS && !warnShown) {
                warnShown = true;
                if (window.confirm('Su sesión cerrará pronto por inactividad. ¿Desea continuar?')) {
                    resetTimer();
                    fetch('/login/user/dashboard.php', { method: 'HEAD', credentials: 'same-origin' });
                }
            }
        }, 10000);
    })();
    </script>

</body>
</html>
