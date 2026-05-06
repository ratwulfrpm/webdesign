<?php
/**
 * includes/views/users/users_page.php — Shared user management page shell.
 *
 * Rendered by admin/users.php and owner/users.php after they have:
 *   1. Authenticated and role-checked the actor.
 *   2. Handled any POST via UserManagementService::handleAction().
 *   3. Fetched all data and resolved session flash variables.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ Expected variables (provided by the entrypoint)                     │
 * │                                                                     │
 * │ string $actorRole       — 'admin'|'owner'|'support'                 │
 * │ string $actionUrl       — Base form action URL (no anchor)          │
 * │ string $pageTitle       — <title> text                              │
 * │ string $headerTitle     — Top-bar heading text                      │
 * │ string $orgName         — Org badge HTML (empty for global/owner)   │
 * │ string $username        — Actor username (HTML-safe)                │
 * │ string $initial         — First letter of username                  │
 * │ string $lang            — Current language code                     │
 * │ string $feedback        — General action feedback (may be empty)    │
 * │ string|null $devTempPassword — DEV-ONLY temp password display       │
 * │ array  $users           — User rows                                 │
 * │ int    $usersPage       — Current page (1-based)                    │
 * │ int    $usersPages      — Total pages                               │
 * │ int    $usersTotal      — Total user count                          │
 * │ array  $requests        — Password-request rows                     │
 * │ array  $validityRequests — Contract-validity request rows           │
 * │ array  $invitations     — Invitation rows                           │
 * │ array  $orgs            — Accessible org rows [{id, name}]          │
 * │ array  $accessibleOrgIds — Accessible org ID list                   │
 * │ int    $orgId           — Session org_id (admin preselect; 0 owner) │
 * │ string $invFeedback     — Invitation-specific feedback              │
 * │ string $invFeedbackType — 'success'|'error'|'warning'               │
 * │ string $invNewLink      — New invitation link (after generate)      │
 * │ string|null $invNewEmail — Invited email address (nullable)         │
 * │ array|null $invEmailResult — Email send result (nullable)           │
 * │ bool   $canChangeRole   — true for owner, false otherwise           │
 * │ bool   $isOwner         — true for owner                            │
 * │ string $role            — Same as $actorRole (legacy alias)         │
 * │ array  $scopedOrgIds    — Scoped org IDs (used by sub-partials)     │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Security note:
 *   This view ONLY renders what the entrypoint authorised.
 *   It does NOT re-evaluate permissions — the service and entrypoint do that.
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body class="wide-layout role-<?= htmlspecialchars($actorRole, ENT_QUOTES, 'UTF-8') ?>"
      data-inv-link-copied-label="<?= htmlspecialchars(t('inv_link_copied'), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
            <span class="top-bar-title">
                <?= htmlspecialchars($headerTitle, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($orgName !== ''): ?><span class="org-badge"><?= $orgName ?></span><?php endif; ?>
            </span>
        </div>
        <div class="top-bar-right">
            <nav class="top-bar-lang" aria-label="<?= htmlspecialchars(t('language_label'), ENT_QUOTES, 'UTF-8') ?>">
                <a href="?set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=zh" class="lang-btn<?= $lang === 'zh' ? ' active' : '' ?>" hreflang="zh">中文</a>
            </nav>
            <form method="POST" action="/login/logout.php" class="top-bar-logout">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn-secondary btn-sm">
                    <?= htmlspecialchars(t('sign_out'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </div>
    </div>

    <?= renderTabs('users') ?>

    <div class="page-content">

        <?php if ($feedback !== ''): ?>
        <div class="alert alert-success" style="margin-bottom:20px;" role="status">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#34c759" stroke-width="1.5"/>
                <polyline points="4.5,8 7,10.5 11.5,5.5" stroke="#34c759" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <?php if ($devTempPassword !== null && AppConfig::isDev()): ?>
        <!-- ⚠ DEV-ONLY BLOCK — Temporary password display (never shown in production).
             Suppressed when APP_ENV=prod or when MAIL_USER/MAIL_PASS are configured. -->
        <div class="alert" style="margin-bottom:20px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:16px 20px;" role="status">
            <strong style="color:#856404;">[DEV] <?= htmlspecialchars(t('reset_pwd_dev_notice'), ENT_QUOTES, 'UTF-8') ?></strong><br>
            <code style="font-size:1rem;letter-spacing:.06em;color:#1d1d1f;"><?= htmlspecialchars($devTempPassword, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
        <?php endif; ?>

        <!-- User management table -->
        <?php require __DIR__ . '/users_table.php'; ?>

        <!-- Password requests -->
        <?php require __DIR__ . '/../../views/password_requests_section.php'; ?>

        <!-- Contract validity requests -->
        <?php require __DIR__ . '/../../views/contract_validity_section.php'; ?>

        <!-- Invitations -->
        <?php require __DIR__ . '/invitation_section.php'; ?>

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    // Copy invitation link to clipboard
    function copyInvLink() {
        var input = document.getElementById('inv-link-input');
        var btn   = document.getElementById('inv-copy-btn');
        var copiedLabel = document.body.getAttribute('data-inv-link-copied-label') || 'Copied';
        if (!input || !btn) return;
        navigator.clipboard.writeText(input.value).then(function () {
            var original = btn.textContent;
            btn.textContent = copiedLabel;
            btn.disabled = true;
            setTimeout(function () { btn.textContent = original; btn.disabled = false; }, 2000);
        }).catch(function () { input.select(); document.execCommand('copy'); });
    }

    <?php if ($isOwner): ?>
    // Toggle single-org selector vs multi-org checkboxes when inviting admin (owner only).
    (function () {
        var roleSelect    = document.getElementById('inv-role');
        var orgWrap       = document.getElementById('inv-org-wrap');
        var adminOrgsWrap = document.getElementById('inv-admin-orgs-wrap');
        if (!roleSelect || !adminOrgsWrap) return;
        function toggleOrgPicker() {
            var isAdmin = roleSelect.value === 'admin';
            if (orgWrap) orgWrap.style.display = isAdmin ? 'none' : '';
            adminOrgsWrap.style.display = isAdmin ? '' : 'none';
        }
        roleSelect.addEventListener('change', toggleOrgPicker);
        toggleOrgPicker();
    })();
    <?php endif; ?>

    // Idle-timeout warning at 25 min (5 min before 30-min cutoff).
    (function () {
        const TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        const WARNING_MS = TIMEOUT_MS - 5 * 60 * 1000;
        const LOGIN_URL  = '/login/index.php?reason=timeout';

        let lastActivity = Date.now();
        let warnShown    = false;

        function resetTimer() { lastActivity = Date.now(); warnShown = false; }
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(ev =>
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
                    // Ping the server to reset the PHP idle timer.
                    fetch(<?= json_encode($actionUrl) ?>, { method: 'HEAD', credentials: 'same-origin' });
                }
            }
        }, 10000);
    })();
    </script>

</body>
</html>
