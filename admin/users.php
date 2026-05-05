<?php
/**
 * /login/admin/users.php — System administration panel
 *
 * Access: role = 'admin' only.
 * Features:
 *  - User list (supplier role only — admin cannot manage owner/admin)
 *  - Activate / deactivate / unlock actions
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
require_once __DIR__ . '/../includes/session.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/RBAC.php';
require_once __DIR__ . '/../includes/TenantScope.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tabs.php';
require_once __DIR__ . '/../includes/contract_validity_admin.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/org_scope.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/Validator.php';

// Auth checks
requireAuth();
initLang();
requireRole(['admin', 'support']);

$pdo      = getDB();
$feedback = '';
$orgId    = (int) ($_SESSION['org_id'] ?? 0);
$userId   = (int) $_SESSION['user_id'];
$role     = (string) ($_SESSION['role'] ?? '');
$lang     = currentLang();
$accessibleOrgs = loadAccessibleOrganizations($pdo, $userId, $role);
$accessibleOrgIds = array_map('intval', array_column($accessibleOrgs, 'id'));
$scopedOrgIds = $role === 'support' ? (in_array($orgId, $accessibleOrgIds, true) ? [$orgId] : []) : $accessibleOrgIds;
$orgName  = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');

/**
 * Build the absolute enrollment URL for a plain (un-hashed) token.
 */
function buildEnrollLink(string $plainToken): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/login/enroll.php?t=' . rawurlencode($plainToken);
}

// ── Handle admin POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = $_POST['action'] ?? '';
    $uid    = (int) ($_POST['user_id']    ?? 0);
    $rid    = (int) ($_POST['request_id'] ?? 0);
    $vrid   = (int) ($_POST['validity_request_id'] ?? 0);
    $reviewComment = trim((string) ($_POST['review_comment'] ?? ''));

    switch ($action) {
        case 'activate':
            if (orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier'])) {
                $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$uid]);
                $feedback = t('feedback_activated');
            }
            break;

        case 'deactivate':
            // Prevent admin from deactivating themselves
            if ($uid > 0
                && $uid !== $userId
                && orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier'])) {
                $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$uid]);
                $feedback = t('feedback_deactivated');
            }
            break;

        case 'unlock':
            if (orgScopeUserAccessible($pdo, $uid, $scopedOrgIds, ['supplier'])) {
                $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
                    ->execute([$uid]);
                $feedback = t('feedback_unlocked');
            }
            break;

        case 'resolve_request':
            if ($rid > 0) {
                $pdo->prepare(
                    'UPDATE password_requests SET status = "resolved", resolved_at = NOW() WHERE id = ?'
                )->execute([$rid]);
                $feedback = t('feedback_request_resolved');
            }
            break;

        case 'approve_contract_validity_request':
            if ($vrid > 0) {
                $ok = cvrApproveRequest($pdo, $vrid, (int) $_SESSION['user_id'], $scopedOrgIds);
                if ($ok) {
                    $feedback = t('contract_review_approved');
                    auditLog('admin_contract_validity_request_approved', 'info', null, (int) $_SESSION['user_id'], [
                        'request_id' => $vrid,
                        'org_scope' => $scopedOrgIds,
                    ]);
                    auditLog('contract_primary_changed_by_review', 'info', null, (int) $_SESSION['user_id'], [
                        'request_id' => $vrid,
                        'org_scope' => $scopedOrgIds,
                    ]);
                } else {
                    $feedback = t('contract_review_action_failed');
                }
            }
            break;

        case 'reject_contract_validity_request':
            if ($vrid > 0) {
                $ok = cvrRejectRequest(
                    $pdo,
                    $vrid,
                    (int) $_SESSION['user_id'],
                    $scopedOrgIds,
                    $reviewComment !== '' ? $reviewComment : null
                );
                if ($ok) {
                    $feedback = t('contract_review_rejected');
                    auditLog('admin_contract_validity_request_rejected', 'warning', null, (int) $_SESSION['user_id'], [
                        'request_id' => $vrid,
                        'org_scope' => $scopedOrgIds,
                    ]);
                } else {
                    $feedback = t('contract_review_action_failed');
                }
            }
            break;

        // ── Invitation actions ────────────────────────────────────

        case 'generate_invitation':
            if ($role === 'support') {
                $_SESSION['inv_feedback']      = t('error_unsupported_role');
                $_SESSION['inv_feedback_type'] = 'error';
                header('Location: /login/admin/users.php');
                exit;
            }
            $invRole  = $_POST['inv_role'] ?? 'supplier';
            $invEmail = mb_substr(trim($_POST['invited_email'] ?? ''), 0, Validator::maxLen('email'), 'UTF-8');
            $allowedInvRoles = ($role === 'owner') ? ['admin', 'support', 'supplier'] : ['support', 'supplier'];
            if (!in_array($invRole, $allowedInvRoles, true)) {
                $invRole = 'supplier';
            }
            $extraOrgIds = [];
            $targetOrgId = 0;
            if ($invRole === 'admin' && $role === 'owner') {
                $checkedIds = array_map('intval', (array) ($_POST['admin_org_ids'] ?? []));
                $checkedIds = array_values(array_filter(
                    $checkedIds,
                    fn($id) => in_array($id, $accessibleOrgIds, true)
                ));
                if (empty($checkedIds)) {
                    $_SESSION['inv_feedback']      = t('inv_err_no_org_selected');
                    $_SESSION['inv_feedback_type'] = 'error';
                    header('Location: /login/admin/users.php');
                    exit;
                }
                $targetOrgId = $checkedIds[0];
                $extraOrgIds = array_slice($checkedIds, 1);
            } else {
                $targetOrgId = (int) ($_POST['org_id'] ?? $orgId);
                if (!orgScopeContainsOrgId($accessibleOrgIds, $targetOrgId)) {
                    $_SESSION['inv_feedback']      = t('error_no_org');
                    $_SESSION['inv_feedback_type'] = 'error';
                    header('Location: /login/admin/users.php');
                    exit;
                }
            }
            if ($invEmail !== '' && !filter_var($invEmail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['inv_feedback']      = t('enroll_err_email');
                $_SESSION['inv_feedback_type'] = 'error';
                header('Location: /login/admin/users.php');
                exit;
            }
            $orgLookup = [];
            foreach ($accessibleOrgs as $org) {
                $orgLookup[(int) $org['id']] = (string) $org['name'];
            }
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash  = hash('sha256', $plainToken);
            $expiresAt  = date('Y-m-d H:i:s', time() + 72 * 3600);
            $enrollLink = buildEnrollLink($plainToken);
            $invStmt = $pdo->prepare(
                'INSERT INTO supplier_invitations
                    (token_hash, org_id, extra_org_ids, role, invited_email, status, expires_at, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, "pending", ?, ?)'
            );
            $invStmt->execute([
                $tokenHash,
                $targetOrgId,
                !empty($extraOrgIds) ? json_encode(array_values($extraOrgIds)) : null,
                $invRole,
                $invEmail !== '' ? $invEmail : null,
                $expiresAt,
                $userId,
            ]);
            auditLog('invitation_created', 'info', (int) $pdo->lastInsertId(), $userId, [
                'org_id'        => $targetOrgId,
                'org_name'      => $orgLookup[$targetOrgId] ?? null,
                'role'          => $invRole,
                'invited_email' => $invEmail !== '' ? $invEmail : null,
            ]);
            $emailResult = null;
            if ($invEmail !== '') {
                $emailResult = sendInvitationEmail($invEmail, $enrollLink, $lang);
            }
            $_SESSION['inv_new_link']      = $enrollLink;
            $_SESSION['inv_new_inv_email'] = $invEmail !== '' ? $invEmail : null;
            $_SESSION['inv_email_result']  = $emailResult;
            $_SESSION['inv_feedback']      = t('inv_generated_success');
            $_SESSION['inv_feedback_type'] = 'success';
            header('Location: /login/admin/users.php');
            exit;

        case 'revoke_invitation':
            if ($role !== 'support') {
                $invId = (int) ($_POST['inv_id'] ?? 0);
                if ($invId > 0 && !empty($accessibleOrgIds)) {
                    $revPlaceholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
                    $revParams = array_merge([$invId], $accessibleOrgIds);
                    $revStmt = $pdo->prepare(
                        "UPDATE supplier_invitations
                            SET status = 'revoked', revoked_at = NOW()
                          WHERE id = ?
                            AND org_id IN ({$revPlaceholders})
                            AND status = 'pending'"
                    );
                    $revStmt->execute($revParams);
                    if ($revStmt->rowCount() > 0) {
                        auditLog('invitation_revoked', 'info', $invId, $userId);
                    }
                }
            }
            $_SESSION['inv_feedback']      = t('inv_revoked_success');
            $_SESSION['inv_feedback_type'] = 'success';
            header('Location: /login/admin/users.php');
            exit;

    }

    // PRG — prevent re-submit on refresh
    header('Location: /login/admin/users.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────
// Admin sees supplier users across every business unit they manage.
$usersPerPage = 50;
$usersPage    = max(1, (int) ($_GET['upage'] ?? 1));
$usersTotal   = 0;
$usersPages   = 1;
$users = [];
if (!empty($scopedOrgIds)) {
    $orgPlaceholders = implode(',', array_fill(0, count($scopedOrgIds), '?'));
    // Count for pagination
    $cntStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT u.id)
           FROM users u
           JOIN org_members om ON u.id = om.user_id
          WHERE om.org_id IN ({$orgPlaceholders})
            AND om.role = 'supplier'
            AND om.is_active = 1"
    );
    $cntStmt->execute($scopedOrgIds);
    $usersTotal  = (int) $cntStmt->fetchColumn();
    $usersPages  = max(1, (int) ceil($usersTotal / $usersPerPage));
    $usersPage   = min($usersPage, $usersPages);
    $usersOffset = ($usersPage - 1) * $usersPerPage;
    $uStmt = $pdo->prepare(
        "SELECT u.id, u.username, u.email, u.is_active,
                u.first_login, u.failed_attempts, u.locked_until,
                u.created_at, 'supplier' AS role,
                GROUP_CONCAT(DISTINCT o.name ORDER BY o.name SEPARATOR ', ') AS org_names
           FROM users u
           JOIN org_members om ON u.id = om.user_id
           JOIN organizations o ON o.id = om.org_id
          WHERE om.org_id IN ({$orgPlaceholders})
            AND om.role = 'supplier'
            AND om.is_active = 1
          GROUP BY u.id, u.username, u.email, u.is_active,
                   u.first_login, u.failed_attempts, u.locked_until, u.created_at
          ORDER BY u.username ASC
          LIMIT {$usersPerPage} OFFSET {$usersOffset}"
    );
    $uStmt->execute($scopedOrgIds);
    $users = $uStmt->fetchAll();
}

$requests = $pdo->query(
    'SELECT id, company_name, email, username, notes, status, requested_at
       FROM password_requests
      ORDER BY status ASC, requested_at DESC'
)->fetchAll();

$validityRequests = cvrListValidityRequests($pdo, $scopedOrgIds);

// ── Invitations data ──────────────────────────────────────────
$orgs        = $accessibleOrgs; // accessible orgs for invitation form dropdown
$invitations = [];
if (!empty($accessibleOrgIds)) {
    $invPlaceholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
    $invQStmt = $pdo->prepare(
        "SELECT si.id, si.org_id, o.name AS org_name,
                si.extra_org_ids,
                si.role, si.invited_email, si.status,
                si.expires_at, si.created_at,
                cb.username AS created_by_username,
                ub.username AS used_by_username
           FROM supplier_invitations si
           JOIN organizations o  ON o.id  = si.org_id
           JOIN users cb         ON cb.id = si.created_by_user_id
           LEFT JOIN users ub    ON ub.id = si.used_by_user_id
          WHERE si.org_id IN ({$invPlaceholders})
          ORDER BY si.created_at DESC
          LIMIT 200"
    );
    $invQStmt->execute($accessibleOrgIds);
    $invitations = $invQStmt->fetchAll();
}

// ── Consume invitation session flash vars ─────────────────────
$invFeedback     = $_SESSION['inv_feedback']      ?? '';
$invFeedbackType = $_SESSION['inv_feedback_type'] ?? 'success';
$invNewLink      = $_SESSION['inv_new_link']       ?? '';
$invNewEmail     = $_SESSION['inv_new_inv_email']  ?? null;
$invEmailResult  = $_SESSION['inv_email_result']   ?? null;
unset(
    $_SESSION['inv_feedback'],
    $_SESSION['inv_feedback_type'],
    $_SESSION['inv_new_link'],
    $_SESSION['inv_new_inv_email'],
    $_SESSION['inv_email_result']
);

$username = htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('admin_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    data-inv-link-copied-label="<?= htmlspecialchars(t('inv_link_copied'), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= t('admin_title') ?>
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
                            <th><?= t('col_org') ?></th>
                            <th><?= t('col_status') ?></th>
                            <th><?= t('col_first_login') ?></th>
                            <th><?= t('col_created_at') ?></th>
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
                                <span class="badge badge-supplier">
                                    <?= htmlspecialchars(t('role_' . $u['role']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="text-muted small">
                                <?= htmlspecialchars((string) ($u['org_names'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
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
                            <td class="text-muted small">
                                <?= htmlspecialchars(substr((string) ($u['created_at'] ?? ''), 0, 10) ?: '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= (int) $u['failed_attempts'] ?></td>
                            <td class="text-muted small">
                                <?= $isLocked
                                    ? htmlspecialchars($u['locked_until'], ENT_QUOTES, 'UTF-8')
                                    : '—' ?>
                            </td>
                            <td>
                                <?php if ($isSelf): ?>
                                    <span class="text-muted small">(<?= t('session_active') ?>)</span>
                                <?php else: ?>
                                <div class="user-actions">
                                    <!-- Row 1: activate/deactivate + unlock -->
                                    <div class="user-actions-row">
                                        <form method="POST" action="/login/admin/users.php">
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
                                        <?php if ($isLocked): ?>
                                        <form method="POST" action="/login/admin/users.php">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="action" value="unlock">
                                            <button type="submit" class="btn-tbl btn-secondary"><?= t('btn_unlock') ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if ($usersPages > 1): ?>
            <nav class="pagination" aria-label="<?= t('pagination_label') ?>" style="margin-top:16px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <?php for ($p = 1; $p <= $usersPages; $p++): ?>
                <a href="?upage=<?= $p ?>"
                   class="btn-secondary btn-sm<?= $p === $usersPage ? ' active' : '' ?>"
                   <?= $p === $usersPage ? 'aria-current="page"' : '' ?>>
                    <?= $p ?>
                </a>
                <?php endfor; ?>
                <span class="text-muted small" style="margin-left:6px;">(<?= $usersTotal ?>)</span>
            </nav>
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
                                <form method="POST" action="/login/admin/users.php">
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

        <!-- ── Contract validity requests ──────────────────── -->
        <section class="panel-section" style="margin-top:36px;">
            <h2 class="section-title"><?= t('contract_review_requests_title') ?></h2>

            <?php if (empty($validityRequests)): ?>
                <p class="text-muted"><?= t('contract_review_no_requests') ?></p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= t('col_supplier') ?></th>
                            <th><?= t('contract_review_business_unit') ?></th>
                            <th><?= t('contract_review_requested_contract') ?></th>
                            <th><?= t('contract_review_current_contract') ?></th>
                            <th><?= t('col_contract_signed_date') ?></th>
                            <th><?= t('col_contract_start') ?></th>
                            <th><?= t('col_contract_end') ?></th>
                            <th><?= t('col_contract_uploaded_at') ?></th>
                            <th><?= t('contract_review_requested_by') ?></th>
                            <th><?= t('contract_review_requested_at') ?></th>
                            <th><?= t('req_status') ?></th>
                            <th><?= t('col_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($validityRequests as $vr): ?>
                        <tr>
                            <td><?= (int) $vr['id'] ?></td>
                            <td><?= htmlspecialchars((string) $vr['supplier_username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $vr['org_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $vr['requested_contract_file'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($vr['current_contract_file'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($vr['requested_signed_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($vr['requested_start_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($vr['requested_end_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(substr((string) $vr['requested_uploaded_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($vr['requested_by_username'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $vr['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge <?= $vr['status'] === 'pending' ? 'badge-pending' : 'badge-done' ?>">
                                    <?= htmlspecialchars(t('contract_review_status_' . $vr['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($vr['status'] === 'pending'): ?>
                                <div class="user-actions-row" style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <form method="POST" action="/login/admin/users.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="approve_contract_validity_request">
                                        <input type="hidden" name="validity_request_id" value="<?= (int) $vr['id'] ?>">
                                        <button type="submit" class="btn-tbl btn-success"><?= t('btn_approve') ?></button>
                                    </form>
                                    <form method="POST" action="/login/admin/users.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="reject_contract_validity_request">
                                        <input type="hidden" name="validity_request_id" value="<?= (int) $vr['id'] ?>">
                                        <input type="text" name="review_comment" maxlength="1000" placeholder="<?= htmlspecialchars(t('contract_review_comment_optional'), ENT_QUOTES, 'UTF-8') ?>" style="max-width:180px;">
                                        <button type="submit" class="btn-tbl btn-danger"><?= t('btn_reject') ?></button>
                                    </form>
                                </div>
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

        <!-- ── Invitations ───────────────────────────────── -->
        <section class="panel-section" style="margin-top:36px;" id="invitations">
            <h2 class="section-title"><?= t('inv_section_title') ?></h2>

            <?php if ($invFeedback !== ''): ?>
            <div class="alert alert-<?= $invFeedbackType === 'error' ? 'error' : ($invFeedbackType === 'warning' ? 'warning' : 'success') ?>"
                 style="margin-bottom:20px;" role="status">
                <span><?= htmlspecialchars($invFeedback, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($invNewLink !== ''): ?>
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
                           value="<?= htmlspecialchars($invNewLink, ENT_QUOTES, 'UTF-8') ?>"
                           readonly
                           aria-label="<?= t('inv_link_banner_title') ?>">
                    <button type="button" class="btn-secondary btn-sm inv-copy-btn"
                            onclick="copyInvLink()"
                            id="inv-copy-btn">
                        <?= t('btn_copy_link') ?>
                    </button>
                </div>
                <?php if ($invEmailResult !== null && $invNewEmail !== null): ?>
                    <?php if ($invEmailResult['sent']): ?>
                    <p class="inv-email-status inv-email-ok">
                        ✓ <?= t('inv_email_sent_success') ?>
                        (<?= htmlspecialchars($invNewEmail, ENT_QUOTES, 'UTF-8') ?>)
                    </p>
                    <?php else: ?>
                    <p class="inv-email-status inv-email-fail">
                        ✗ <?= t('inv_email_send_failed') ?>
                        — <?= htmlspecialchars($invNewEmail, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($role !== 'support'): ?>
            <div style="margin-top:24px;">
                <h3 class="section-subtitle"><?= t('inv_form_title') ?></h3>
                <form method="POST" action="/login/admin/users.php#invitations" class="inv-gen-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="generate_invitation">
                    <div class="form-row">
                        <div class="input-wrap">
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
                        <div class="input-wrap">
                            <label for="inv-role"><?= t('inv_role_label') ?></label>
                            <select id="inv-role" name="inv_role" class="input-select">
                                <option value="supplier" selected><?= t('role_supplier') ?></option>
                                <option value="support"><?= t('role_support') ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
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
            </div>
            <?php endif; ?>

            <div style="margin-top:32px;">
                <h3 class="section-subtitle"><?= t('inv_list_title') ?></h3>
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
                                <th><?= t('col_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invitations as $inv):
                            $isExpiredNow = $inv['status'] === 'pending'
                                && strtotime($inv['expires_at']) < time();
                            if ($isExpiredNow) {
                                $pdo->prepare(
                                    'UPDATE supplier_invitations SET status = "expired" WHERE id = ?'
                                )->execute([$inv['id']]);
                                $inv['status'] = 'expired';
                            }
                            $statusLabel = t('inv_status_' . $inv['status']);
                            $statusClass = match($inv['status']) {
                                'pending' => 'badge-pending',
                                'used'    => 'badge-done',
                                default   => 'badge-inactive',
                            };
                            $revokeConfirm = $lang === 'en'
                                ? 'Revoke this invitation?'
                                : '¿Revocar esta invitación?';
                        ?>
                            <tr>
                                <td><?= (int) $inv['id'] ?></td>
                                <td><?= htmlspecialchars($inv['org_name'], ENT_QUOTES, 'UTF-8') ?></td>
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
                                    <form method="POST" action="/login/admin/users.php#invitations" style="display:inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="revoke_invitation">
                                        <input type="hidden" name="inv_id"  value="<?= (int) $inv['id'] ?>">
                                        <button type="submit" class="btn-tbl btn-danger"
                                                onclick="return confirm(<?= htmlspecialchars(json_encode($revokeConfirm), ENT_QUOTES, 'UTF-8') ?>)">
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
            </div>
        </section>

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <!-- Idle-timeout warning at 25 min (300 seconds before 30-min cutoff) -->
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

    (function () {
        const TIMEOUT_MS  = <?= IDLE_TIMEOUT * 1000 ?>;
        const WARNING_MS  = TIMEOUT_MS - 5 * 60 * 1000; // warn 5 min early
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
                    // Ping the server to reset the PHP idle timer
                    fetch('/login/admin/users.php', { method: 'HEAD', credentials: 'same-origin' });
                }
            }
        }, 10000);
    })();
    </script>

</body>
</html>

