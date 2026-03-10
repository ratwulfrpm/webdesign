<?php
/**
 * /apple-login/supplier/summary.php — Supplier main dashboard
 *
 * Access: role = 'supplier' and first_login = 0 only.
 * If first_login is still 1, redirects to profile.php.
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
require_once __DIR__ . '/../config/db.php';

requireAuth();
initLang();
requireRole(['supplier']);

// First-login guard — must complete profile first
if ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /apple-login/supplier/profile.php');
    exit;
}

// Load fresh profile data from DB
$pdo  = getDB();
$stmt = $pdo->prepare(
    'SELECT u.username, u.email, u.full_name, u.company_name, u.phone,
            u.tax_id, u.legal_rep_name, u.legal_rep_id,
            u.company_phone_code, u.company_phone_number,
            u.legal_rep_phone_code, u.legal_rep_phone_number,
            u.addr_street, u.addr_city, u.addr_state, u.addr_zip,
            u.factory_street, u.factory_city, u.factory_state, u.factory_zip,
            u.preferred_language,
            co_addr.name_es    AS addr_country_name,
            co_fact.name_es    AS factory_country_name
       FROM users u
       LEFT JOIN countries co_addr ON co_addr.id = u.addr_country_id
       LEFT JOIN countries co_fact ON co_fact.id = u.factory_country_id
      WHERE u.id = ?
      LIMIT 1'
);
$stmt->execute([(int) $_SESSION['user_id']]);
$profile = $stmt->fetch();

if (!$profile) {
    destroySession();
    header('Location: /apple-login/index.php');
    exit;
}

// Show "saved" confirmation if redirected from profile.php
$saved = isset($_GET['saved']);

$username = htmlspecialchars($profile['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
$lang     = currentLang();

$esc = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('summary_page_title') ?></title>
    <link rel="stylesheet" href="/apple-login/css/style.css">
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
            <span class="top-bar-title"><?= $username ?></span>
        </div>
        <form method="POST" action="/apple-login/logout.php" class="top-bar-logout">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
        </form>
    </div>

    <div class="page-content">

        <?php if ($saved): ?>
        <div class="alert alert-success" style="margin-bottom:20px;" role="status">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#34c759" stroke-width="1.5"/>
                <polyline points="4.5,8 7,10.5 11.5,5.5" stroke="#34c759" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?= t('profile_success') ?></span>
        </div>
        <?php endif; ?>

        <div class="card dashboard-card" role="main">

            <div class="welcome-avatar" aria-hidden="true"><?= $initial ?></div>
            <h1 class="welcome-title"><?= t('welcome') ?>, <?= $esc($profile['full_name'] ?: $username) ?>!</h1>
            <p class="welcome-subtitle"><?= t('summary_subtitle') ?></p>

            <!-- Profile summary -->
            <div class="meta-list" aria-label="<?= t('your_profile') ?>">
                <div>
                    <strong><?= t('field_full_name') ?></strong>
                    &nbsp;&nbsp;<?= $profile['full_name'] ? $esc($profile['full_name']) : '<em class="text-muted">' . t('not_provided') . '</em>' ?>
                </div>
                <div>
                    <strong><?= t('field_company') ?></strong>
                    &nbsp;&nbsp;<?= $profile['company_name'] ? $esc($profile['company_name']) : '<em class="text-muted">' . t('not_provided') . '</em>' ?>
                </div>
                <?php if (!empty($profile['tax_id'])): ?>
                <div>
                    <strong><?= t('tax_id_label') ?></strong>
                    &nbsp;&nbsp;<?= $esc($profile['tax_id']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($profile['legal_rep_name'])): ?>
                <div>
                    <strong><?= t('legal_rep_name_label') ?></strong>
                    &nbsp;&nbsp;<?= $esc($profile['legal_rep_name']) ?>
                    <?= !empty($profile['legal_rep_id']) ? ' &mdash; <span class="text-muted">' . $esc($profile['legal_rep_id']) . '</span>' : '' ?>
                </div>
                <?php endif; ?>
                <div>
                    <strong><?= t('company_phone_label') ?></strong>
                    &nbsp;&nbsp;
                    <?php
                    $compPh = trim(($profile['company_phone_code'] ?? '') . ' ' . ($profile['company_phone_number'] ?? ''));
                    echo $compPh !== ''
                        ? $esc($compPh)
                        : '<em class="text-muted">' . t('not_provided') . '</em>';
                    ?>
                </div>
                <?php if (!empty($profile['addr_street'])): ?>
                <div>
                    <strong><?= t('section_addr_company') ?></strong>
                    &nbsp;&nbsp;
                    <?php
                    $addr = array_filter([
                        $profile['addr_street'],
                        $profile['addr_city'],
                        $profile['addr_state'],
                        $profile['addr_zip'],
                        $profile['addr_country_name'] ?? null,
                    ]);
                    echo $esc(implode(', ', $addr));
                    ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($profile['factory_street'])): ?>
                <div>
                    <strong><?= t('section_addr_factory') ?></strong>
                    &nbsp;&nbsp;
                    <?php
                    $fact = array_filter([
                        $profile['factory_street'],
                        $profile['factory_city'],
                        $profile['factory_state'],
                        $profile['factory_zip'],
                        $profile['factory_country_name'] ?? null,
                    ]);
                    echo $esc(implode(', ', $fact));
                    ?>
                </div>
                <?php endif; ?>
                <div>
                    <strong><?= t('field_email') ?></strong>
                    &nbsp;&nbsp;<?= $esc($profile['email']) ?>
                </div>
                <div>
                    <strong><?= t('role_label') ?></strong>
                    &nbsp;&nbsp;
                    <span class="badge badge-supplier"><?= t('role_supplier') ?></span>
                </div>
                <div>
                    <strong><?= t('session_active') ?></strong>
                    &nbsp;&nbsp;<?= t('signed_in_at') ?>
                    <?= date('d/m/Y H:i') ?>
                </div>
            </div>

            <!-- Actions row -->
            <div style="display:flex; gap:12px; flex-wrap:wrap; justify-content:center; margin-top:4px;">
                <a href="/apple-login/supplier/profile.php" class="btn-secondary">
                    <?= t('edit_profile') ?>
                </a>
            </div>

        </div>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <!-- Client-side idle-timeout mirror -->
    <script>
    (function () {
        const TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        let last = Date.now();
        ['mousemove','keydown','click','scroll'].forEach(ev =>
            document.addEventListener(ev, () => { last = Date.now(); }, { passive: true })
        );
        setInterval(() => {
            if (Date.now() - last >= TIMEOUT_MS) {
                window.location.href = '/apple-login/index.php?reason=timeout';
            }
        }, 10000);
    })();
    </script>

</body>
</html>
