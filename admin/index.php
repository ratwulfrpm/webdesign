<?php
/**
 * /apple-login/admin/index.php — System administration panel
 *
 * Access: role = 'admin' only.
 * Features:
 *  - User list with activate / deactivate / unlock actions
 *  - Password-request queue with resolve action
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
require_once __DIR__ . '/../config/db.php';

// Auth checks
requireAuth();
initLang();

if ($_SESSION['role'] !== 'admin') {
    header('Location: /apple-login/index.php');
    exit;
}

$pdo      = getDB();
$feedback = '';

// ── Handle admin POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = $_POST['action'] ?? '';
    $uid    = (int) ($_POST['user_id']    ?? 0);
    $rid    = (int) ($_POST['request_id'] ?? 0);

    switch ($action) {
        case 'activate':
            if ($uid > 0) {
                $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$uid]);
                $feedback = 'Usuario activado.';
            }
            break;

        case 'deactivate':
            // Prevent admin from deactivating themselves
            if ($uid > 0 && $uid !== (int) $_SESSION['user_id']) {
                $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
                $feedback = 'Usuario desactivado.';
            }
            break;

        case 'unlock':
            if ($uid > 0) {
                $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
                    ->execute([$uid]);
                $feedback = 'Cuenta desbloqueada.';
            }
            break;

        case 'resolve_request':
            if ($rid > 0) {
                $pdo->prepare(
                    'UPDATE password_requests SET status = "resolved", resolved_at = NOW() WHERE id = ?'
                )->execute([$rid]);
                $feedback = 'Solicitud marcada como resuelta.';
            }
            break;
    }

    // PRG — prevent re-submit on refresh
    header('Location: /apple-login/admin/index.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────
$users = $pdo->query(
    'SELECT id, username, email, role, is_active,
            first_login, failed_attempts, locked_until
       FROM users
      ORDER BY role ASC, username ASC'
)->fetchAll();

$requests = $pdo->query(
    'SELECT id, company_name, email, username, notes, status, requested_at
       FROM password_requests
      ORDER BY status ASC, requested_at DESC'
)->fetchAll();

$username = htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
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
    <title><?= t('admin_page_title') ?></title>
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
            <span class="top-bar-title"><?= t('admin_title') ?></span>
        </div>
        <form method="POST" action="/apple-login/logout.php" class="top-bar-logout">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-secondary btn-sm">
                <?= t('sign_out') ?>
            </button>
        </form>
    </div>

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

        <!-- ── User management ────────────────────────────── -->
        <section class="panel-section">
            <h2 class="section-title"><?= t('user_management') ?></h2>

            <?php if (empty($users)): ?>
                <p class="text-muted"><?= t('no_users') ?></p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= t('col_id') ?></th>
                            <th><?= t('col_username') ?></th>
                            <th><?= t('col_email') ?></th>
                            <th><?= t('col_role') ?></th>
                            <th><?= t('col_status') ?></th>
                            <th><?= t('col_first_login') ?></th>
                            <th><?= t('col_attempts') ?></th>
                            <th><?= t('col_locked_until') ?></th>
                            <th><?= t('col_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                            $isLocked  = !empty($u['locked_until']) && strtotime($u['locked_until']) > time();
                            $isSelf    = (int) $u['id'] === (int) $_SESSION['user_id'];
                        ?>
                        <tr>
                            <td><?= (int) $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-supplier' ?>">
                                    <?= $u['role'] === 'admin' ? t('role_admin') : t('role_supplier') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= (int) $u['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= (int) $u['is_active'] ? t('status_active') : t('status_inactive') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= (int) $u['first_login'] ? 'badge-pending' : 'badge-done' ?>">
                                    <?= (int) $u['first_login'] ? t('first_login_yes') : t('first_login_no') ?>
                                </span>
                            </td>
                            <td><?= (int) $u['failed_attempts'] ?></td>
                            <td class="text-muted small">
                                <?= $isLocked
                                    ? htmlspecialchars($u['locked_until'], ENT_QUOTES, 'UTF-8')
                                    : '—' ?>
                            </td>
                            <td class="actions-cell">
                                <?php if (!$isSelf): ?>
                                <form method="POST" action="/apple-login/admin/index.php" style="display:inline">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <?php if ((int) $u['is_active']): ?>
                                    <input type="hidden" name="action" value="deactivate">
                                    <button type="submit" class="btn-tbl btn-danger"><?= t('btn_deactivate') ?></button>
                                    <?php else: ?>
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="btn-tbl btn-success"><?= t('btn_activate') ?></button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>

                                <?php if ($isLocked): ?>
                                <form method="POST" action="/apple-login/admin/index.php" style="display:inline">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="action" value="unlock">
                                    <button type="submit" class="btn-tbl btn-secondary"><?= t('btn_unlock') ?></button>
                                </form>
                                <?php endif; ?>

                                <?php if ($isSelf): ?><span class="text-muted small">(<?= t('session_active') ?>)</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── Password requests ──────────────────────────── -->
        <section class="panel-section" style="margin-top:36px;">
            <h2 class="section-title"><?= t('col_requests') ?></h2>

            <?php if (empty($requests)): ?>
                <p class="text-muted"><?= t('no_requests') ?></p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= t('req_company') ?></th>
                            <th><?= t('req_email') ?></th>
                            <th><?= t('req_user') ?></th>
                            <th><?= t('req_notes') ?></th>
                            <th><?= t('req_date') ?></th>
                            <th><?= t('req_status') ?></th>
                            <th><?= t('col_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?= (int) $r['id'] ?></td>
                            <td><?= htmlspecialchars($r['company_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $r['username'] ? htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            <td class="small text-muted"><?= $r['notes'] ? htmlspecialchars($r['notes'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($r['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge <?= $r['status'] === 'pending' ? 'badge-pending' : 'badge-done' ?>">
                                    <?= $r['status'] === 'pending' ? t('req_pending') : t('req_resolved') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                <form method="POST" action="/apple-login/admin/index.php">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="action" value="resolve_request">
                                    <button type="submit" class="btn-tbl btn-success"><?= t('btn_resolve') ?></button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <!-- Idle-timeout warning at 25 min (300 seconds before 30-min cutoff) -->
    <script>
    (function () {
        const TIMEOUT_MS  = <?= IDLE_TIMEOUT * 1000 ?>;
        const WARNING_MS  = TIMEOUT_MS - 5 * 60 * 1000; // warn 5 min early
        const LOGIN_URL   = '/apple-login/index.php?reason=timeout';

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
                    // Ping the server to reset the PHP idle timer
                    fetch('/apple-login/admin/index.php', { method: 'HEAD', credentials: 'same-origin' });
                }
            }
        }, 10000);
    })();
    </script>

</body>
</html>
