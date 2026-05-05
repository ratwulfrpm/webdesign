<?php
/**
 * /login/change_password.php — Forced password change
 *
 * Shown when a user must change their password before accessing the system.
 * This page is reached when:
 *   a) An admin/owner performed a password reset (must_change_password = 1).
 *   b) The user is redirected here by requireAuth() after any login.
 *
 * This page uses isLoggedIn() instead of requireAuth() to avoid the
 * redirect loop that would occur if requireAuth() redirected here while
 * this page itself called requireAuth().
 *
 * Password policy (permanent password):
 *   - 12–128 characters
 *   - At least one uppercase letter
 *   - At least one lowercase letter
 *   - At least one digit
 *   - Special characters permitted but not required
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/Validator.php';

// ── Lightweight auth guard (avoids redirect loop with requireAuth) ─
sendNoCacheHeaders();
if (!isLoggedIn()) {
    header('Location: /login/index.php');
    exit;
}
if (empty($_SESSION['must_change_password'])) {
    // Not forced — redirect to appropriate dashboard.
    redirectToHome();
    exit;
}

initLang();
$lang     = currentLang();
$userId   = (int) $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');

$formError   = '';
$formSuccess  = false;

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $newPassword     = $_POST['new_password']     ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Hard-cap at 128 chars (bcrypt DoS guard)
    if (strlen($newPassword) > 128) {
        $newPassword = substr($newPassword, 0, 128);
    }

    if ($newPassword === '') {
        $formError = t('change_pwd_err_empty');
    } elseif ($newPassword !== $confirmPassword) {
        $formError = t('change_pwd_err_mismatch');
    } else {
        $policy = Validator::validatePassword($newPassword);
        if (!$policy['ok']) {
            // Map first error key to a user-facing message.
            $formError = t($policy['errors'][0] ?? 'change_pwd_err_policy');
        }
    }

    if ($formError === '') {
        try {
            $pdo = getDB();
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            $pdo->prepare(
                'UPDATE users
                    SET password_hash                  = ?,
                        must_change_password            = 0,
                        temporary_password_created_at   = NULL,
                        temporary_password_expires_at   = NULL
                  WHERE id = ?'
            )->execute([$newHash, $userId]);

            auditLog('forced_password_change_completed', 'info', null, $userId, [
                'username' => $_SESSION['username'] ?? '',
            ]);

            // Clear the forced-change flag from the session.
            $_SESSION['must_change_password'] = 0;
            unset($_SESSION['must_change_password']);

            // Regenerate session ID after privilege change.
            session_regenerate_id(true);

            // Redirect to the appropriate dashboard.
            redirectToHome();
            exit;

        } catch (Throwable $e) {
            error_log('[change_password] DB error: ' . $e->getMessage());
            $formError = t('change_pwd_err_server');
        }
    }
}

$initialChar = strtoupper(substr($username, 0, 1));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('change_pwd_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body>

    <!-- Language selector -->
    <div class="lang-selector" role="navigation" aria-label="<?= t('language_label') ?>">
        <a href="?set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=zh" class="lang-btn<?= $lang === 'zh' ? ' active' : '' ?>" hreflang="zh">中文</a>
    </div>

    <!-- Brand -->
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

    <div class="card" role="main">
        <h1 class="card-title"><?= t('change_pwd_title') ?></h1>
        <p class="card-subtitle"><?= t('change_pwd_subtitle') ?></p>

        <?php if ($formError !== ''): ?>
        <div class="alert alert-error" role="alert">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                <line x1="8" y1="5" x2="8" y2="8.5" stroke="#ff3b30" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
            </svg>
            <span><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <div class="alert alert-info" role="note" style="margin-bottom:20px;">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#0071e3" stroke-width="1.5"/>
                <line x1="8" y1="7" x2="8" y2="11" stroke="#0071e3" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="5" r=".75" fill="#0071e3"/>
            </svg>
            <span><?= t('change_pwd_policy_hint') ?></span>
        </div>

        <form method="POST" action="/login/change_password.php" autocomplete="off">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="new_password" class="form-label">
                    <?= t('change_pwd_new_label') ?>
                </label>
                <div class="password-wrapper">
                    <input type="password" id="new_password" name="new_password"
                           class="form-input" autocomplete="new-password"
                           maxlength="128" required
                           placeholder="<?= htmlspecialchars(t('change_pwd_new_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="show-password-btn"
                            data-target="new_password"
                            aria-label="<?= htmlspecialchars(t('show_password'), ENT_QUOTES, 'UTF-8') ?>">
                        <svg viewBox="0 0 20 14" width="20" height="14" fill="none" aria-hidden="true">
                            <path d="M1 7s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="10" cy="7" r="2.5" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password" class="form-label">
                    <?= t('change_pwd_confirm_label') ?>
                </label>
                <div class="password-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-input" autocomplete="new-password"
                           maxlength="128" required
                           placeholder="<?= htmlspecialchars(t('change_pwd_confirm_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="show-password-btn"
                            data-target="confirm_password"
                            aria-label="<?= htmlspecialchars(t('show_password'), ENT_QUOTES, 'UTF-8') ?>">
                        <svg viewBox="0 0 20 14" width="20" height="14" fill="none" aria-hidden="true">
                            <path d="M1 7s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="10" cy="7" r="2.5" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary btn-full">
                <?= t('change_pwd_btn_submit') ?>
            </button>
        </form>
    </div>

    <script>
    // Toggle password visibility
    document.querySelectorAll('.show-password-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var input = document.getElementById(targetId);
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });
    </script>

</body>
</html>
