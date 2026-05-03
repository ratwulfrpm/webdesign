<?php
/**
 * /login/invitations.php — Supplier invitation management
 *
 * Access: admin, owner or support.
 * Features:
 *  - Generate a new invitation link (owner/admin only)
 *  - Optionally send the link by email immediately (if invited_email provided)
 *  - Revoke a pending invitation (owner/admin only)
 *  - List invitation history for all organizations the current user manages
 *
 * Token design:
 *  - plain_token = bin2hex(random_bytes(32))   [64 hex chars]
 *  - stored_hash = hash('sha256', plain_token) [64 hex chars, CHAR(64) in DB]
 *  - The plain token appears only in the enrollment URL; it is NEVER stored.
 *  - After the PRG redirect, the plain token is held in $_SESSION['inv_new_link']
 *    for one page load so the admin can copy/email it.
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
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tabs.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/org_scope.php';

requireAuth();
initLang();
requireRole(['admin', 'owner', 'support']);

$pdo     = getDB();
$userId  = (int) $_SESSION['user_id'];
$orgId   = (int) $_SESSION['org_id'];
$orgName = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');
$role    = $_SESSION['role'] ?? '';
$lang    = currentLang();
$accessibleOrgs = loadAccessibleOrganizations($pdo, $userId, $role);
$accessibleOrgIds = array_map('intval', array_column($accessibleOrgs, 'id'));

if ($role === 'support') {
    $accessibleOrgs = array_values(array_filter(
        $accessibleOrgs,
        fn($row) => (int) ($row['id'] ?? 0) === $orgId
    ));
    $accessibleOrgIds = array_map('intval', array_column($accessibleOrgs, 'id'));
}

// ── Helpers ───────────────────────────────────────────────────

/**
 * Build the absolute enrollment URL for a plain token.
 * Uses HTTP_HOST; HTTPS detection via HTTPS server var.
 */
function buildEnrollLink(string $plainToken): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/login/enroll.php?t=' . rawurlencode($plainToken);
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    if ($role === 'support') {
        $_SESSION['inv_feedback']      = t('error_unsupported_role');
        $_SESSION['inv_feedback_type'] = 'error';
        header('Location: /login/invitations.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Generate invitation ───────────────────────────────────
    if ($action === 'generate_invitation') {
        $invRole  = $_POST['inv_role'] ?? 'supplier';
        $invEmail = trim($_POST['invited_email'] ?? '');

        // Validate role hierarchy
        $allowedRoles = match ($role) {
            'owner'   => ['admin', 'support', 'supplier'],
            'admin'   => ['support', 'supplier'],
            default   => ['supplier'],
        };
        if (!in_array($invRole, $allowedRoles, true)) {
            $invRole = 'supplier';
        }

        // ── Org selection ─────────────────────────────────────
        // When owner invites an admin, multiple orgs can be selected via checkboxes.
        // For all other cases a single org_id dropdown is used.
        $extraOrgIds   = [];   // additional org IDs beyond the primary one
        $targetOrgId   = 0;

        if ($invRole === 'admin' && $role === 'owner') {
            $checkedIds = array_map('intval', (array) ($_POST['admin_org_ids'] ?? []));
            // Keep only org IDs this owner actually has access to
            $checkedIds = array_values(array_filter(
                $checkedIds,
                fn($id) => in_array($id, $accessibleOrgIds, true)
            ));

            if (empty($checkedIds)) {
                $_SESSION['inv_feedback']      = t('inv_err_no_org_selected');
                $_SESSION['inv_feedback_type'] = 'error';
                header('Location: /login/invitations.php');
                exit;
            }

            $targetOrgId = $checkedIds[0];
            $extraOrgIds = array_slice($checkedIds, 1);
        } else {
            $targetOrgId = (int) ($_POST['org_id'] ?? $orgId);
            if (!orgScopeContainsOrgId($accessibleOrgIds, $targetOrgId)) {
                $_SESSION['inv_feedback']      = t('error_no_org');
                $_SESSION['inv_feedback_type'] = 'error';
                header('Location: /login/invitations.php');
                exit;
            }
        }

        $orgLookup = [];
        foreach ($accessibleOrgs as $org) {
            $orgLookup[(int) $org['id']] = (string) $org['name'];
        }

        // Validate email format if provided
        if ($invEmail !== '' && !filter_var($invEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['inv_feedback']      = t('enroll_err_email');
            $_SESSION['inv_feedback_type'] = 'error';
            header('Location: /login/invitations.php');
            exit;
        }

        // Generate cryptographically random token
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $expiresAt  = date('Y-m-d H:i:s', time() + 72 * 3600);
        $enrollLink = buildEnrollLink($plainToken);

        // Insert invitation row
        $stmt = $pdo->prepare(
            'INSERT INTO supplier_invitations
                (token_hash, org_id, extra_org_ids, role, invited_email, status, expires_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, "pending", ?, ?)'
        );
        $stmt->execute([
            $tokenHash,
            $targetOrgId,
            !empty($extraOrgIds) ? json_encode(array_values($extraOrgIds)) : null,
            $invRole,
            $invEmail !== '' ? $invEmail : null,
            $expiresAt,
            $userId,
        ]);

        auditLog('invitation_created', 'info', (int) $pdo->lastInsertId(), $userId, [
            'org_id' => $targetOrgId,
            'org_name' => $orgLookup[$targetOrgId] ?? null,
            'role' => $invRole,
            'invited_email' => $invEmail !== '' ? $invEmail : null,
        ]);

        // Optionally send email immediately
        $sendEmail   = $invEmail !== '';
        $emailResult = null;
        if ($sendEmail) {
            $emailResult = sendInvitationEmail($invEmail, $enrollLink, $lang);
        }

        // Store link in session for one-time display after PRG redirect
        $_SESSION['inv_new_link']      = $enrollLink;
        $_SESSION['inv_new_inv_email'] = $invEmail !== '' ? $invEmail : null;
        $_SESSION['inv_email_result']  = $emailResult;
        $_SESSION['inv_feedback']      = t('inv_generated_success');
        $_SESSION['inv_feedback_type'] = 'success';

        header('Location: /login/invitations.php');
        exit;
    }

    // ── Revoke invitation ─────────────────────────────────────
    if ($action === 'revoke_invitation') {
        $invId = (int) ($_POST['inv_id'] ?? 0);
        if ($invId > 0) {
            if (!empty($accessibleOrgIds)) {
                $placeholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
                $params = array_merge([$invId], $accessibleOrgIds);
                $stmt = $pdo->prepare(
                    "UPDATE supplier_invitations
                        SET status = 'revoked', revoked_at = NOW()
                      WHERE id = ?
                        AND org_id IN ({$placeholders})
                        AND status = 'pending'"
                );
                $stmt->execute($params);

                if ($stmt->rowCount() > 0) {
                    auditLog('invitation_revoked', 'info', $invId, $userId);
                }
            }
        }
        $_SESSION['inv_feedback']      = t('inv_revoked_success');
        $_SESSION['inv_feedback_type'] = 'success';
        header('Location: /login/invitations.php');
        exit;
    }

    // Unknown action — just redirect
    header('Location: /login/invitations.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────

// Active organizations the current user may manage.
$orgs = $accessibleOrgs;

$invitations = [];
if (!empty($accessibleOrgIds)) {
    $placeholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT si.id, si.org_id, o.name AS org_name,
                si.extra_org_ids,
                si.role, si.invited_email, si.status,
                si.expires_at, si.created_at, si.used_at, si.revoked_at,
                cb.username AS created_by_username,
                ub.username AS used_by_username
           FROM supplier_invitations si
           JOIN organizations o  ON o.id  = si.org_id
           JOIN users cb         ON cb.id = si.created_by_user_id
           LEFT JOIN users ub    ON ub.id = si.used_by_user_id
          WHERE si.org_id IN ({$placeholders})
          ORDER BY si.created_at DESC
          LIMIT 200"
    );
    $stmt->execute($accessibleOrgIds);
    $invitations = $stmt->fetchAll();
}

// ── Consume one-time session vars ─────────────────────────────
$feedback      = $_SESSION['inv_feedback']      ?? '';
$feedbackType  = $_SESSION['inv_feedback_type'] ?? 'success';
$newLink       = $_SESSION['inv_new_link']       ?? '';
$newInvEmail   = $_SESSION['inv_new_inv_email']  ?? null;
$emailResult   = $_SESSION['inv_email_result']   ?? null;

unset(
    $_SESSION['inv_feedback'],
    $_SESSION['inv_feedback_type'],
    $_SESSION['inv_new_link'],
    $_SESSION['inv_new_inv_email'],
    $_SESSION['inv_email_result']
);

// ── HTML ──────────────────────────────────────────────────────
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('inv_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    data-inv-link-copied-label="<?= htmlspecialchars(t('inv_link_copied'), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= t('inv_title') ?>
                <?php if ($orgName !== ''): ?><span class="org-badge"><?= $orgName ?></span><?php endif; ?>
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
                <button type="submit" class="btn-secondary btn-sm">
                    <?= t('sign_out') ?>
                </button>
            </form>
        </div>
    </div>

    <?= renderTabs('invitations') ?>

    <div class="page-content">

        <!-- ── General feedback ───────────────────────────────── -->
        <?php if ($feedback !== ''): ?>
        <div class="alert alert-<?= $feedbackType === 'error' ? 'error' : ($feedbackType === 'warning' ? 'warning' : 'success') ?>"
             style="margin-bottom:20px;" role="status">
            <span><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <!-- ── Generated link banner (one-time display) ──────── -->
        <?php if ($newLink !== ''): ?>
        <div class="inv-link-banner" role="region" aria-label="<?= t('inv_link_banner_title') ?>">
            <div class="inv-link-banner-header">
                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="9" stroke="#34c759" stroke-width="1.5"/>
                    <polyline points="5.5,10 8.5,13 14.5,7" stroke="#34c759" stroke-width="1.5"
                              stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <strong><?= t('inv_link_banner_title') ?></strong>
            </div>
            <p class="inv-link-banner-desc"><?= t('inv_link_banner_desc') ?></p>
            <div class="inv-link-row">
                <input type="text" id="inv-link-input"
                       class="inv-link-input"
                       value="<?= htmlspecialchars($newLink, ENT_QUOTES, 'UTF-8') ?>"
                       readonly
                       aria-label="<?= t('inv_link_banner_title') ?>">
                <button type="button" class="btn-secondary btn-sm inv-copy-btn"
                        onclick="copyInvLink()"
                        id="inv-copy-btn">
                    <?= t('btn_copy_link') ?>
                </button>
            </div>

            <?php if ($emailResult !== null && $newInvEmail !== null): ?>
                <?php if ($emailResult['sent']): ?>
                <p class="inv-email-status inv-email-ok">
                    ✓ <?= t('inv_email_sent_success') ?>
                    (<?= htmlspecialchars($newInvEmail, ENT_QUOTES, 'UTF-8') ?>)
                </p>
                <?php else: ?>
                <p class="inv-email-status inv-email-fail">
                    ✗ <?= t('inv_email_send_failed') ?>
                    — <?= htmlspecialchars($newInvEmail, ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Generate invitation form ──────────────────────── -->
        <?php if ($role !== 'support'): ?>
        <section class="panel-section">
            <h2 class="section-title"><?= t('inv_form_title') ?></h2>

            <form method="POST" action="/login/invitations.php" class="inv-gen-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="generate_invitation">

                <div class="form-row">
                    <!-- Organization select (hidden for owner+admin; replaced by checkboxes) -->
                    <div class="input-wrap" id="inv-org-wrap">
                        <label for="inv-org"><?= t('inv_org_label') ?></label>
                        <select id="inv-org" name="org_id" class="input-select">
                            <?php foreach ($orgs as $o): ?>
                            <option value="<?= (int) $o['id'] ?>"
                                <?= (int) $o['id'] === $orgId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($role === 'owner'): ?>
                    <!-- Multi-org checkbox list — only shown when admin role is selected -->
                    <div class="input-wrap" id="inv-admin-orgs-wrap" style="display:none;">
                        <label><?= t('inv_admin_orgs_label') ?></label>
                        <p class="input-help" style="margin-bottom:8px;"><?= t('inv_admin_orgs_help') ?></p>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <?php foreach ($orgs as $o): ?>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer;">
                                <input type="checkbox"
                                       name="admin_org_ids[]"
                                       value="<?= (int) $o['id'] ?>"
                                       style="width:16px;height:16px;cursor:pointer;">
                                <?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Role select -->
                    <div class="input-wrap">
                        <label for="inv-role"><?= t('inv_role_label') ?></label>
                        <select id="inv-role" name="inv_role" class="input-select">
                            <option value="supplier" selected><?= t('role_supplier') ?></option>
                            <?php if (in_array($role, ['owner', 'admin'], true)): ?>
                            <option value="support"><?= t('role_support') ?></option>
                            <?php endif; ?>
                            <?php if ($role === 'owner'): ?>
                            <option value="admin"><?= t('role_admin') ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <!-- Optional invited email -->
                    <div class="input-wrap" style="flex:2">
                        <label for="inv-email"><?= t('inv_email_label') ?></label>
                        <input type="email"
                               id="inv-email"
                               name="invited_email"
                               placeholder="<?= t('inv_email_ph') ?>"
                               autocomplete="off"
                               maxlength="254">
                        <span class="input-help"><?= t('inv_email_help') ?></span>
                    </div>


                </div>

                <button type="submit" class="btn-primary" style="width:auto;padding:0 28px;">
                    <?= t('btn_generate_link') ?>
                </button>
            </form>
        </section>
        <?php endif; ?>

        <!-- ── Invitation history ─────────────────────────────── -->
        <section class="panel-section" style="margin-top:36px;">
            <h2 class="section-title"><?= t('inv_list_title') ?></h2>

            <?php if (empty($invitations)): ?>
            <p class="text-muted"><?= t('inv_no_invitations') ?></p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= t('inv_col_org') ?></th>
                            <th><?= t('inv_col_role') ?></th>
                            <th><?= t('inv_col_email') ?></th>
                            <th><?= t('inv_col_status') ?></th>
                            <th><?= t('inv_col_expires') ?></th>
                            <th><?= t('inv_col_created_by') ?></th>
                            <th><?= t('inv_col_used_by') ?></th>
                            <th><?= t('inv_col_created_at') ?></th>
                            <th><?= t('inv_col_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invitations as $inv):
                        // Lazily expire past-due pending invitations
                        $isExpiredNow = $inv['status'] === 'pending'
                            && strtotime($inv['expires_at']) < time();
                        if ($isExpiredNow) {
                            $pdo->prepare(
                                'UPDATE supplier_invitations SET status = "expired" WHERE id = ?'
                            )->execute([$inv['id']]);
                            $inv['status'] = 'expired';
                        }

                        $statusKey   = 'inv_status_' . $inv['status'];
                        $statusLabel = t($statusKey);
                        $revokeConfirm = $lang === 'en'
                            ? 'Revoke this invitation?'
                            : '¿Revocar esta invitación?';
                        $statusClass = match($inv['status']) {
                            'pending'  => 'badge-pending',
                            'used'     => 'badge-done',
                            'expired'  => 'badge-inactive',
                            'revoked'  => 'badge-inactive',
                            default    => '',
                        };
                    ?>
                        <tr>
                            <td><?= (int) $inv['id'] ?></td>
                            <td>
                                <?= htmlspecialchars($inv['org_name'], ENT_QUOTES, 'UTF-8') ?>
                                <?php
                                // Show additional orgs for admin invitations
                                $extraIds = json_decode($inv['extra_org_ids'] ?? 'null', true);
                                if (!empty($extraIds)):
                                    // Build a lookup of org names from accessible orgs
                                    $orgNamesById = array_column($orgs, 'name', 'id');
                                ?>
                                <span class="text-muted small" style="display:block;">
                                    <?php foreach ($extraIds as $eid): ?>
                                    + <?= htmlspecialchars($orgNamesById[$eid] ?? "Org #{$eid}", ENT_QUOTES, 'UTF-8') ?><br>
                                    <?php endforeach; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-supplier">
                                    <?= htmlspecialchars(t('role_' . $inv['role']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="text-muted small">
                                <?= $inv['invited_email']
                                    ? htmlspecialchars($inv['invited_email'], ENT_QUOTES, 'UTF-8')
                                    : '<em>' . t('inv_any_email') . '</em>' ?>
                            </td>
                            <td>
                                <span class="badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="text-muted small">
                                <?= htmlspecialchars($inv['expires_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= htmlspecialchars($inv['created_by_username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-muted small">
                                <?= $inv['used_by_username']
                                    ? htmlspecialchars($inv['used_by_username'], ENT_QUOTES, 'UTF-8')
                                    : '—' ?>
                            </td>
                            <td class="text-muted small">
                                <?= htmlspecialchars($inv['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="actions-cell">
                                <?php if ($role !== 'support' && $inv['status'] === 'pending'): ?>
                                <form method="POST" action="/login/invitations.php" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"  value="revoke_invitation">
                                    <input type="hidden" name="inv_id"  value="<?= (int) $inv['id'] ?>">
                                    <button type="submit" class="btn-tbl btn-danger"
                                            data-confirm="<?= htmlspecialchars($revokeConfirm, ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="return confirm(this.getAttribute('data-confirm'))">
                                        <?= t('btn_revoke') ?>
                                    </button>
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

    </div><!-- /.page-content -->

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
        setTimeout(function () {
            btn.textContent = original;
            btn.disabled = false;
        }, 2000);
    }).catch(function () {
        // Fallback for older browsers / HTTP
        input.select();
        document.execCommand('copy');
    });
}

// Toggle org selector vs admin multi-org checkboxes based on role selection.
// Only relevant for the owner role (the admin-orgs wrap only renders for owner).
(function () {
    var roleSelect    = document.getElementById('inv-role');
    var orgWrap       = document.getElementById('inv-org-wrap');
    var adminOrgsWrap = document.getElementById('inv-admin-orgs-wrap');

    if (!roleSelect || !adminOrgsWrap) return; // not owner — nothing to do

    function updateOrgVisibility() {
        if (roleSelect.value === 'admin') {
            orgWrap.style.display       = 'none';
            adminOrgsWrap.style.display = '';
        } else {
            orgWrap.style.display       = '';
            adminOrgsWrap.style.display = 'none';
        }
    }

    roleSelect.addEventListener('change', updateOrgVisibility);
    updateOrgVisibility(); // run on page load in case form was re-rendered with admin pre-selected
}());
</script>

</body>
</html>

