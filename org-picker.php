<?php
/**
 * /jshop/org-picker.php — Organization selection page.
 *
 * Shown when a user belongs to more than one organization.
 * The user clicks the org they want to enter; the system
 * completes the session with that org's role and redirects
 * to the appropriate panel.
 *
 * Requires a pending session (Phase 1 of login).
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
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/lang.php';

// Guard — requires an active pending (pre-org) session
requirePendingAuth();
initLang();

$orgs     = $_SESSION['pending_orgs']    ?? [];
$username = $_SESSION['pending_username'] ?? '';
$error    = '';

// ── Handle POST — user selected an org ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $orgId = (int) ($_POST['org_id'] ?? 0);

    if ($orgId > 0 && selectOrg($orgId)) {
        redirectToHome();
        exit;
    }
    $error = t('error_org_invalid');
}

$lang = currentLang();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('org_picker_title') ?></title>
    <link rel="stylesheet" href="/jshop/css/style.css?v=5">
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

    <!-- Org picker card -->
    <div class="card org-picker-card" role="main">
        <h1 class="card-title"><?= t('org_picker_heading') ?></h1>
        <p class="card-subtitle">
            <?= t('org_picker_subtitle', htmlspecialchars($username, ENT_QUOTES, 'UTF-8')) ?>
        </p>

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

        <!-- Org cards grid -->
        <div class="org-list" role="list">
            <?php foreach ($orgs as $org): ?>
            <form method="POST" action="/jshop/org-picker.php" role="listitem">
                <?= csrfField() ?>
                <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                <button type="submit" class="org-card">
                    <span class="org-card-avatar" aria-hidden="true">
                        <?= strtoupper(substr($org['name'], 0, 2)) ?>
                    </span>
                    <span class="org-card-info">
                        <span class="org-card-name"><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($org['description'])): ?>
                        <span class="org-card-desc"><?= htmlspecialchars($org['description'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <span class="org-card-role">
                            <?= t('role_label') ?>:
                            <span class="badge badge-<?= htmlspecialchars($org['role'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= t('role_' . $org['role']) ?>
                            </span>
                        </span>
                    </span>
                    <span class="org-card-arrow" aria-hidden="true">›</span>
                </button>
            </form>
            <?php endforeach; ?>
        </div>

        <!-- Cancel / sign-out link -->
        <div class="card-footer" style="margin-top:20px;">
            <a href="/jshop/logout.php?cancel=1"><?= t('org_picker_cancel') ?></a>
        </div>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

</body>
</html>
