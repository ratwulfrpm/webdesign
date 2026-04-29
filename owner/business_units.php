<?php
/**
 * /owner/business_units.php — Business Units management for Owner
 *
 * Access: role = 'owner' only.
 * Features:
 *  - List all business units (organizations)
 *  - Create new business unit
 *  - Toggle active/inactive
 *  - Assign admins to a business unit
 *  - View admins per business unit
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
requireRole(['owner']);

$pdo      = getDB();
$feedback = '';
$error    = '';
$orgName  = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');

// ── Slug sanitizer ────────────────────────────────────────────
function sanitizeSlug(string $raw): string
{
    $slug = strtolower(trim($raw));
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return mb_substr($slug, 0, 60);
}

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'create_bu':
            $name        = mb_substr(trim($_POST['bu_name']        ?? ''), 0, 200);
            $slug        = sanitizeSlug($_POST['bu_slug']          ?? '');
            $description = mb_substr(trim($_POST['bu_description'] ?? ''), 0, 500);

            if ($name === '' || $slug === '') {
                $error = t('bu_error_name_slug_required');
                break;
            }

            // Check slug uniqueness
            $chk = $pdo->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
            $chk->execute([$slug]);
            if ($chk->fetch()) {
                $error = sprintf(t('bu_error_slug_taken'), htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'));
                break;
            }

            try {
                $ins = $pdo->prepare(
                    'INSERT INTO organizations (slug, name, description, is_active)
                     VALUES (?, ?, ?, 1)'
                );
                $ins->execute([$slug, $name, $description ?: null]);
                $newOrgId = (int) $pdo->lastInsertId();

                // Auto-add creating owner as member
                $pdo->prepare(
                    'INSERT INTO org_members (user_id, org_id, role)
                     VALUES (?, ?, "owner")
                     ON DUPLICATE KEY UPDATE is_active = 1, role = "owner"'
                )->execute([(int) $_SESSION['user_id'], $newOrgId]);

                $feedback = sprintf(t('bu_feedback_created'), htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = sprintf(t('bu_error_slug_taken'), htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'));
                } else {
                    error_log('create_bu error: ' . $e->getMessage());
                    $error = t('bu_error_save_failed');
                }
            }
            break;

        case 'toggle_active':
            $buId      = (int) ($_POST['bu_id']  ?? 0);
            $newStatus = (int) ($_POST['new_status'] ?? 0);
            if ($buId > 0) {
                $pdo->prepare('UPDATE organizations SET is_active = ? WHERE id = ?')
                    ->execute([$newStatus ? 1 : 0, $buId]);
                $feedback = $newStatus ? t('bu_feedback_activated') : t('bu_feedback_deactivated');
            }
            break;

        case 'assign_admin':
            $buId      = (int) ($_POST['bu_id']   ?? 0);
            $adminId   = (int) ($_POST['admin_id'] ?? 0);
            $role      = in_array($_POST['admin_role'] ?? '', ['admin', 'owner'], true)
                       ? $_POST['admin_role'] : 'admin';

            if ($buId <= 0 || $adminId <= 0) {
                $error = t('bu_error_invalid_selection');
                break;
            }
            // Cannot assign self via this form
            if ($adminId === (int) $_SESSION['user_id']) {
                $error = t('bu_error_assign_self');
                break;
            }

            // Verify user is active
            $chk = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $chk->execute([$adminId]);
            if (!$chk->fetch()) {
                $error = t('bu_error_user_not_found');
                break;
            }

            $pdo->prepare(
                'INSERT INTO org_members (user_id, org_id, role, is_active)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), is_active = 1'
            )->execute([$adminId, $buId, $role]);
            $feedback = t('bu_feedback_admin_assigned');
            break;

        case 'remove_admin':
            $buId    = (int) ($_POST['bu_id']    ?? 0);
            $adminId = (int) ($_POST['admin_id'] ?? 0);

            if ($buId <= 0 || $adminId <= 0) {
                $error = t('bu_error_invalid_selection');
                break;
            }
            if ($adminId === (int) $_SESSION['user_id']) {
                $error = t('bu_error_assign_self');
                break;
            }

            $pdo->prepare(
                "UPDATE org_members SET is_active = 0
                  WHERE org_id = ? AND user_id = ? AND role IN ('admin','owner')"
            )->execute([$buId, $adminId]);
            $feedback = t('bu_feedback_admin_removed');
            break;
    }

    // PRG — prevent re-submit on refresh
    $qs = $feedback !== '' ? '?ok=1' : ($error !== '' ? '?err=' . urlencode($error) : '');
    header('Location: /login/owner/business_units.php' . $qs);
    exit;
}

// Handle redirected messages
if (isset($_GET['ok']) && $feedback === '') {
    $feedback = t('bu_feedback_saved');
}
if (isset($_GET['err']) && $error === '') {
    $error = htmlspecialchars(urldecode($_GET['err']), ENT_QUOTES, 'UTF-8');
}

// ── Fetch data ────────────────────────────────────────────────
$businessUnits = $pdo->query(
    "SELECT o.id, o.slug, o.name, o.description, o.is_active, o.created_at,
            COUNT(DISTINCT om.user_id) AS member_count
       FROM organizations o
       LEFT JOIN org_members om ON om.org_id = o.id AND om.is_active = 1
      GROUP BY o.id
      ORDER BY o.name ASC"
)->fetchAll();

// Load admins per BU (for display)
$buAdmins = [];
if (!empty($businessUnits)) {
    $buIds = array_column($businessUnits, 'id');
    $inSql = implode(',', array_fill(0, count($buIds), '?'));
    $admStmt = $pdo->prepare(
        "SELECT om.org_id, u.id AS user_id, u.username, u.email, om.role, u.is_active
           FROM org_members om
           JOIN users u ON u.id = om.user_id
          WHERE om.org_id IN ({$inSql})
            AND om.role IN ('admin','owner')
            AND om.is_active = 1
          ORDER BY om.role DESC, u.username ASC"
    );
    $admStmt->execute($buIds);
    foreach ($admStmt->fetchAll() as $row) {
        $buAdmins[(int) $row['org_id']][] = $row;
    }
}

// Load all admin/owner users (to populate assignment dropdown)
$allAdmins = $pdo->query(
    "SELECT u.id, u.username, u.email, u.full_name
       FROM users u
      WHERE u.is_active = 1
        AND EXISTS (
            SELECT 1 FROM org_members om
             WHERE om.user_id = u.id AND om.role IN ('admin','owner') AND om.is_active = 1
        )
      ORDER BY u.username ASC"
)->fetchAll();

$username  = htmlspecialchars($_SESSION['username'] ?? 'Owner', ENT_QUOTES, 'UTF-8');
$initial   = strtoupper(substr($username, 0, 1));
$lang      = currentLang();
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
$esc       = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('bu_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=12">
    <style>
        .bu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); gap: 20px; margin-top: 20px; }
        .bu-card { background: var(--card-bg, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 20px; }
        .bu-card__header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
        .bu-card__name { font-weight:700; font-size:1.05rem; }
        .bu-card__slug { font-size:0.78rem; color:#6b7280; font-family:monospace; }
        .bu-card__meta { font-size:0.83rem; color:#6b7280; margin-bottom:12px; }
        .bu-card__admins { margin-top:12px; }
        .bu-card__admins h4 { font-size:0.85rem; font-weight:600; margin-bottom:6px; color:#374151; }
        .admin-chip { display:inline-flex; align-items:center; gap:6px; background:#f3f4f6; border-radius:20px; padding:3px 10px; margin:2px; font-size:0.8rem; }
        .admin-chip form { display:inline; }
        .admin-chip .remove-btn { background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.9rem; padding:0 2px; line-height:1; }
        .badge-active   { background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:12px; font-size:0.75rem; font-weight:600; }
        .badge-inactive { background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:12px; font-size:0.75rem; font-weight:600; }
        .create-form { background: var(--card-bg, #fff); border: 1px solid var(--border, #e5e7eb); border-radius:10px; padding:20px; margin-bottom:24px; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:600px){ .form-row { grid-template-columns:1fr; } }
        .assign-section { margin-top:12px; padding-top:12px; border-top:1px solid #f0f0f0; }
        .assign-section select, .assign-section .btn-sm { vertical-align:middle; }
        .org-badge-pill { background:#ede9fe; color:#5b21b6; padding:2px 8px; border-radius:12px; font-size:0.75rem; font-weight:600; margin-left:6px; }
    </style>
</head>
<body class="wide-layout">

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= t('owner_title') ?>
                <span class="org-badge"><?= $orgName ?></span>
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
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
            </form>
        </div>
    </div>

    <?= renderTabs('business_units') ?>

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

        <?php if ($error !== ''): ?>
        <div class="alert alert-danger" style="margin-bottom:20px;" role="alert">
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <!-- ── Create new Business Unit ──────────────────── -->
        <section class="panel-section">
            <h2 class="section-title"><?= t('bu_create_title') ?></h2>

            <div class="create-form">
                <form method="POST" action="/login/owner/business_units.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action"     value="create_bu">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="bu_name"><?= t('bu_field_name') ?> *</label>
                            <input type="text" id="bu_name" name="bu_name" class="form-input"
                                   placeholder="<?= t('bu_field_name_ph') ?>"
                                   maxlength="200" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bu_slug"><?= t('bu_field_slug') ?> *</label>
                            <input type="text" id="bu_slug" name="bu_slug" class="form-input"
                                   placeholder="<?= t('bu_field_slug_ph') ?>"
                                   pattern="[a-z0-9\-]+" maxlength="60"
                                   title="<?= t('bu_field_slug_hint') ?>" required>
                            <small class="form-hint"><?= t('bu_field_slug_hint') ?></small>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" for="bu_description"><?= t('bu_field_description') ?></label>
                        <input type="text" id="bu_description" name="bu_description" class="form-input"
                               placeholder="<?= t('bu_field_description_ph') ?>"
                               maxlength="500">
                    </div>

                    <div style="margin-top:16px;">
                        <button type="submit" class="btn-primary"><?= t('bu_btn_create') ?></button>
                    </div>
                </form>
            </div>
        </section>

        <!-- ── Business Units list ───────────────────────── -->
        <section class="panel-section">
            <h2 class="section-title">
                <?= t('bu_list_title') ?>
                <span class="org-badge-pill"><?= count($businessUnits) ?> <?= t('bu_count_label') ?></span>
            </h2>

            <?php if (empty($businessUnits)): ?>
                <p class="text-muted"><?= t('bu_no_units') ?></p>
            <?php else: ?>
            <div class="bu-grid">
            <?php foreach ($businessUnits as $bu): ?>
                <div class="bu-card">
                    <div class="bu-card__header">
                        <div>
                            <div class="bu-card__name"><?= $esc($bu['name']) ?></div>
                            <div class="bu-card__slug">/<?= $esc($bu['slug']) ?></div>
                        </div>
                        <div>
                            <?php if ($bu['is_active']): ?>
                                <span class="badge-active"><?= t('status_active') ?></span>
                            <?php else: ?>
                                <span class="badge-inactive"><?= t('status_inactive') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($bu['description']): ?>
                    <p class="bu-card__meta"><?= $esc($bu['description']) ?></p>
                    <?php endif; ?>

                    <div class="bu-card__meta">
                        <?= t('bu_members_label') ?>: <strong><?= (int)$bu['member_count'] ?></strong>
                        &nbsp;|&nbsp; <?= t('bu_created_label') ?>:
                        <strong><?= date('Y-m-d', strtotime($bu['created_at'])) ?></strong>
                    </div>

                    <!-- Toggle active -->
                    <form method="POST" action="/login/owner/business_units.php" style="display:inline;">
                        <input type="hidden" name="csrf_token"  value="<?= $csrfToken ?>">
                        <input type="hidden" name="action"      value="toggle_active">
                        <input type="hidden" name="bu_id"       value="<?= (int)$bu['id'] ?>">
                        <input type="hidden" name="new_status"  value="<?= $bu['is_active'] ? '0' : '1' ?>">
                        <button type="submit" class="btn-secondary btn-sm"
                                <?= $bu['is_active'] ? '' : '' ?>>
                            <?= $bu['is_active'] ? t('btn_deactivate') : t('btn_activate') ?>
                        </button>
                    </form>

                    <!-- Assigned admins -->
                    <div class="bu-card__admins">
                        <h4><?= t('bu_admins_label') ?></h4>
                        <?php $admins = $buAdmins[(int)$bu['id']] ?? []; ?>
                        <?php if (empty($admins)): ?>
                            <p class="text-muted" style="font-size:0.82rem;"><?= t('bu_no_admins') ?></p>
                        <?php else: ?>
                            <?php foreach ($admins as $adm): ?>
                            <span class="admin-chip">
                                <?= $esc($adm['username']) ?>
                                <small>(<?= $esc($adm['role']) ?>)</small>
                                <?php if ((int)$adm['user_id'] !== (int)$_SESSION['user_id']): ?>
                                <form method="POST" action="/login/owner/business_units.php">
                                    <input type="hidden" name="csrf_token"  value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action"      value="remove_admin">
                                    <input type="hidden" name="bu_id"       value="<?= (int)$bu['id'] ?>">
                                    <input type="hidden" name="admin_id"    value="<?= (int)$adm['user_id'] ?>">
                                    <button type="submit" class="remove-btn" title="<?= t('bu_btn_remove_admin') ?>"
                                            onclick="return confirm('<?= t('bu_confirm_remove_admin') ?>')">✕</button>
                                </form>
                                <?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Assign admin -->
                        <div class="assign-section">
                            <form method="POST" action="/login/owner/business_units.php"
                                  style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action"     value="assign_admin">
                                <input type="hidden" name="bu_id"      value="<?= (int)$bu['id'] ?>">

                                <select name="admin_id" class="form-input" style="flex:1;min-width:140px;padding:6px 8px;">
                                    <option value=""><?= t('bu_select_admin') ?></option>
                                    <?php foreach ($allAdmins as $a):
                                        $currentAdmIds = array_column($admins, 'user_id');
                                        if (in_array($a['id'], $currentAdmIds)) continue;
                                        if ((int)$a['id'] === (int)$_SESSION['user_id']) continue;
                                    ?>
                                    <option value="<?= (int)$a['id'] ?>">
                                        <?= $esc($a['username']) ?>
                                        <?= $a['full_name'] ? '(' . $esc($a['full_name']) . ')' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="admin_role" class="form-input" style="width:90px;padding:6px 8px;">
                                    <option value="admin">Admin</option>
                                    <option value="owner">Owner</option>
                                </select>

                                <button type="submit" class="btn-primary btn-sm"><?= t('bu_btn_assign') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

    </div><!-- /.page-content -->

    <script>
    // Auto-generate slug from name
    document.getElementById('bu_name').addEventListener('input', function() {
        const slugField = document.getElementById('bu_slug');
        if (slugField.dataset.manual) return;
        slugField.value = this.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .substring(0, 60);
    });
    document.getElementById('bu_slug').addEventListener('input', function() {
        this.dataset.manual = '1';
    });
    </script>

</body>
</html>
