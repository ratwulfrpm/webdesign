<?php
/**
 * /apple-login/index.php — Login page
 *
 * Features:
 *  - Login form: username/email + password + CSRF
 *  - Lockout handling (3 failed attempts → 1 hour block)
 *  - Account-inactive message
 *  - Idle-timeout / deactivation messages from query params
 *  - Language selector (ES / EN) via GET ?set_lang=xx
 *  - Role-based post-login redirect:
 *      owner            → /apple-login/owner/index.php
 *      admin            → /apple-login/admin/index.php
 *      supplier + first → /apple-login/supplier/profile.php
 *      supplier         → /apple-login/supplier/summary.php
 *      user             → /apple-login/user/dashboard.php
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,   // set true for HTTPS
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/lang.php';

// Language selection (PRG — returns if set_lang present)
initLang();

// Already logged in → send to the correct dashboard
if (isLoggedIn()) {
    redirectToHome();
    exit;
}

// ── Informational messages from redirects ─────────────────────
$info  = '';
$reason = $_GET['reason'] ?? '';
if ($reason === 'timeout') {
    $info = t('error_timeout');
} elseif ($reason === 'deactivated') {
    $info = t('error_deactivated');
}

// ── Handle POST (login attempt) ───────────────────────────────
$error      = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) CSRF check
    csrfValidate();

    // 2) Collect & sanitise input
    $identifier = trim(htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'));
    $password   = $_POST['password'] ?? '';

    // 3) Basic presence check
    if ($identifier === '' || $password === '') {
        $error = t('error_empty');
    } else {
        // 4) Attempt authentication
        $result = attemptLogin($identifier, $password);

        if (is_array($result)) {
            // Success — build session and redirect by role
            createSession($result);
            redirectToHome();
            exit;

        } elseif (strpos($result, 'LOCKED:') === 0) {
            $minutes = (int) substr($result, 7);
            $error   = t('error_locked', $minutes);

        } elseif ($result === AUTH_INACTIVE) {
            $error = t('error_inactive');

        } else {
            $error = t('error_invalid');
        }
    }
}

$lang = currentLang();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= t('page_title') ?></title>
    <link rel="stylesheet" href="/apple-login/css/style.css?v=4">
</head>
<body>

    <!-- Language selector -->
    <div class="lang-selector" role="navigation" aria-label="<?= t('language_label') ?>">
        <a href="?set_lang=es"
           class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>"
           hreflang="es"
           aria-current="<?= $lang === 'es' ? 'true' : 'false' ?>">ES</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=en"
           class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>"
           hreflang="en"
           aria-current="<?= $lang === 'en' ? 'true' : 'false' ?>">EN</a>
    </div>

    <!-- Brand mark -->
    <div class="brand">
        <span class="brand-icon">
            <svg viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M22 4C13.166 4 6 11.166 6 20c0 6.188 3.41 11.572 8.443 14.418
                         .518.288 1.098-.118 1.028-.704l-.584-4.867a.998.998 0 0
                         1 .456-.968C17.63 26.702 19.77 26 22 26s4.37.702 6.657 1.879a.998.998
                         0 0 1 .456.968l-.584 4.867c-.07.586.51.992 1.028.704C34.59 31.572 38
                         26.188 38 20c0-8.834-7.166-16-16-16Z"/>
            </svg>
        </span>
        <span class="brand-name">Local App</span>
    </div>

    <!-- Login card -->
    <div class="card" role="main">
        <h1 class="card-title"><?= t('sign_in') ?></h1>
        <p class="card-subtitle"><?= t('sign_in_subtitle') ?></p>

        <?php if ($info !== ''): ?>
        <div class="alert alert-info" role="status">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#0071e3" stroke-width="1.5"/>
                <line x1="8" y1="7" x2="8" y2="11.25" stroke="#0071e3" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="5" r=".75" fill="#0071e3"/>
            </svg>
            <span><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                <line x1="8" y1="4.75" x2="8" y2="8.75" stroke="#ff3b30" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
            </svg>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="/apple-login/index.php" novalidate autocomplete="on">
            <?= csrfField() ?>

            <div class="form-group">
                <!-- Username / email -->
                <div class="input-wrap">
                    <label for="identifier"><?= t('username_label') ?></label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="<?= t('username_placeholder') ?>"
                        autocomplete="username"
                        autofocus
                        required
                        maxlength="254"
                        spellcheck="false"
                        autocapitalize="none"
                    >
                </div>

                <!-- Password -->
                <div class="input-wrap">
                    <label for="password"><?= t('password_label') ?></label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                        maxlength="128"
                    >
                    <button type="button" class="toggle-pw"
                            aria-label="<?= t('show_password') ?>"
                            onclick="togglePassword()">
                        <svg id="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary"><?= t('btn_sign_in') ?></button>
        </form>

        <div class="card-footer">
            <a href="/apple-login/forgot_password.php"><?= t('forgot_password') ?></a>
        </div>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    const SHOW_LABEL = <?= json_encode(t('show_password')) ?>;
    const HIDE_LABEL = <?= json_encode(t('hide_password')) ?>;

    function togglePassword() {
        const input   = document.getElementById('password');
        const icon    = document.getElementById('eye-icon');
        const btn     = icon.closest('button');
        const showing = input.type === 'text';

        input.type = showing ? 'password' : 'text';
        btn.setAttribute('aria-label', showing ? SHOW_LABEL : HIDE_LABEL);

        icon.innerHTML = showing
            ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
              '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>' +
              '<line x1="1" y1="1" x2="23" y2="23"/>';
    }

    // Prevent double-submit
    document.querySelector('form').addEventListener('submit', function () {
        const btn = this.querySelector('.btn-primary');
        btn.disabled    = true;
        btn.textContent = '…';
    });
    </script>
</body>
</html>
