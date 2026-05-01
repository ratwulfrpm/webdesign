<?php
/**
 * /login/supplier/summary.php — Supplier main dashboard
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
require_once __DIR__ . '/../includes/tabs.php';

requireAuth();
initLang();
requireRole(['supplier']);

// First-login guard — must complete profile first
if ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
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
            u.email_pending, u.email_verify_expires,
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
    header('Location: /login/index.php');
    exit;
}

// Load current (primary) contract for the sidebar widget
$contractStmt = $pdo->prepare(
    'SELECT id, original_filename, signed_date, effective_start_date,
            effective_end_date, created_at
       FROM supplier_contracts
      WHERE supplier_id = ? AND is_primary = 1
      LIMIT 1'
);
$contractStmt->execute([(int) $_SESSION['user_id']]);
$primaryContract = $contractStmt->fetch() ?: null;

// Show "saved" confirmation if redirected from profile.php
$saved = isset($_GET['saved']);

$username = htmlspecialchars($profile['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
$lang     = currentLang();

$esc = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

// ── Email verification state for summary ─────────────────────
$evPending = !empty($profile['email_pending'])
             && !empty($profile['email_verify_expires'])
             && strtotime($profile['email_verify_expires']) > time();
$evExpired = !empty($profile['email_pending'])
             && (!empty($profile['email_verify_expires'])
                 ? strtotime($profile['email_verify_expires']) <= time()
                 : true);
// Display email: always show the active (verified) email in the main profile block
$displayEmail = $esc($profile['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('summary_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=13">
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= $username ?>
                <span class="org-badge"><?= htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </div>
        <div class="top-bar-right">
            <nav class="top-bar-lang" aria-label="<?= t('language_label') ?>">
                <a href="?set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=zh" class="lang-btn<?= $lang === 'zh' ? ' active' : '' ?>" hreflang="zh">中文</a>
            </nav>
            <form method="POST" action="/login/logout.php" class="top-bar-logout">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
            </form>
        </div>
    </div>

    <?= renderTabs('summary') ?>

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

        <!-- ── Two-column layout: profile + contract widget ── -->
        <div class="summary-layout">

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
                    &nbsp;&nbsp;<?= $displayEmail ?>
                    <?php if ($evPending): ?>
                    <span class="badge badge-warning" style="font-size:.72rem;vertical-align:middle;margin-left:6px;">
                        <?= t('email_badge_pending') ?>
                    </span>
                    <?php endif; ?>
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
                <a href="/login/supplier/profile.php" class="btn-secondary">
                    <?= t('edit_profile') ?>
                </a>
                <?php if ($evPending): ?>
                <a href="/login/supplier/profile.php#verify_code" class="btn-primary btn-sm">
                    <?= t('email_verify_btn') ?>
                </a>
                <?php endif; ?>
            </div>

        </div><!-- /dashboard-card -->

        <!-- ── Contract widget (right column) ───────────────── -->
        <div class="summary-contract-widget">
            <div class="card" style="text-align:left;">
                <h2 class="card-title" style="font-size:1rem;margin-bottom:6px;">
                    <?= t('contract_current_label') ?>
                </h2>
                <?php if ($primaryContract): ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
                    <span class="status-badge status-badge--active" style="font-size:.72rem;">
                        <?= t('contract_primary_badge') ?>
                    </span>
                    <span style="font-size:.875rem;font-weight:600;word-break:break-all;">
                        <?= htmlspecialchars($primaryContract['original_filename'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div style="font-size:.8rem;color:var(--color-text-muted);line-height:1.8;margin-bottom:14px;">
                    <?php if ($primaryContract['signed_date']): ?>
                    <div><strong><?= t('col_contract_signed_date') ?>:</strong>
                        <?= htmlspecialchars($primaryContract['signed_date'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($primaryContract['effective_start_date']): ?>
                    <div><strong><?= t('col_contract_start') ?>:</strong>
                        <?= htmlspecialchars($primaryContract['effective_start_date'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($primaryContract['effective_end_date']): ?>
                    <div><strong><?= t('col_contract_end') ?>:</strong>
                        <?= htmlspecialchars($primaryContract['effective_end_date'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div><strong><?= t('col_contract_uploaded_at') ?>:</strong>
                        <?= htmlspecialchars(substr($primaryContract['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <a href="/login/supplier/documents.php"
                   class="btn-secondary btn-sm" style="display:inline-block;">
                    <?= t('summary_view_documents') ?>
                </a>
                <?php else: ?>
                <p class="text-muted" style="font-size:.875rem;margin-bottom:14px;">
                    <?= t('no_contracts') ?>
                </p>
                <a href="/login/supplier/documents.php"
                   class="btn-secondary btn-sm" style="display:inline-block;">
                    <?= t('tab_documents') ?>
                </a>
                <?php endif; ?>
            </div>
        </div><!-- /contract widget -->

        </div><!-- /summary-layout -->

        <?php if ($evExpired && !$evPending): ?>
        <!-- ════════════════ SECCIÓN BORRADOR ════════════════ -->
        <div class="draft-section card" role="region" aria-label="<?= t('draft_section_title') ?>">
            <div class="draft-section-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"
                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"
                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>
                <h2 class="draft-section-title"><?= t('draft_section_title') ?></h2>
            </div>
            <p class="draft-section-subtitle"><?= t('draft_section_subtitle') ?></p>

            <div class="draft-item">
                <div class="draft-item-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="2" y="4" width="20" height="16" rx="3"
                              stroke="currentColor" stroke-width="1.5"/>
                        <path d="M2 8l10 7 10-7"
                              stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="draft-item-body">
                    <strong><?= t('draft_email_change_title') ?></strong>
                    <p class="draft-item-desc">
                        <?= sprintf(t('draft_email_change_desc'), '<strong>' . $esc($profile['email_pending']) . '</strong>') ?>
                    </p>
                    <p class="draft-item-meta"><?= t('draft_email_change_hint') ?></p>
                </div>
                <div class="draft-item-actions">
                    <form method="POST" action="/login/supplier/profile.php">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="resend_verify">
                        <button type="submit" class="btn-primary btn-sm">
                            <?= t('email_verify_resend') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /page-content -->

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
                window.location.href = '/login/index.php?reason=timeout';
            }
        }, 10000);
    })();
    </script>

</body>
</html>

