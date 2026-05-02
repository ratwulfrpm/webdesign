<?php
/**
 * /login/supplier/documents.php — Documentos del proveedor
 *
 * Muestra y gestiona el historial de contratos firmados.
 * POST actions:
 *   add_contract          — carga un nuevo contrato (PDF/JPG/PNG ≤10 MB)
 *   mark_primary_contract — designa un contrato como el vigente
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
require_once __DIR__ . '/../includes/image_validate.php';

requireAuth();
initLang();
requireRole(['supplier']);

// Requires completed profile
if ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
    exit;
}

$pdo  = getDB();
$lang = currentLang();
$uid  = (int) $_SESSION['user_id'];

// ── Load all contracts — most recent first ────────────────────
function loadContracts(PDO $pdo, int $uid): array
{
    $s = $pdo->prepare(
        'SELECT sc.id, sc.storage_path, sc.original_filename, sc.mime_type,
                sc.file_size, sc.signed_date, sc.effective_start_date,
                sc.effective_end_date, sc.notes, sc.is_primary, sc.created_at,
                u.username AS uploader_username
           FROM supplier_contracts sc
           JOIN users u ON u.id = sc.uploaded_by_user_id
          WHERE sc.supplier_id = ?
          ORDER BY sc.created_at DESC'
    );
    $s->execute([$uid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
$contracts = loadContracts($pdo, $uid);

// ── Helpers ───────────────────────────────────────────────────
$errors    = [];
$flash     = '';
$flashType = 'success';
$esc       = fn($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$csrfField = csrfField();

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = trim($_POST['action'] ?? '');

    // ─── Action: add_contract ────────────────────────────────
    if ($action === 'add_contract') {

        $contractResult = validateContractFile(
            $_FILES['contract_file'] ?? ['error' => UPLOAD_ERR_NO_FILE],
            CONTRACT_MAX_BYTES
        );

        if (!$contractResult['ok']) {
            $errors['contract_file'] = t($contractResult['error']);
        }

        if (empty($errors)) {
            $cleanDate = static function (string $v): ?string {
                return ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
            };
            $signedDate = $cleanDate(trim($_POST['signed_date']          ?? ''));
            $startDate  = $cleanDate(trim($_POST['effective_start_date'] ?? ''));
            $endDate    = $cleanDate(trim($_POST['effective_end_date']   ?? ''));
            $notes      = mb_substr(trim($_POST['contract_notes'] ?? ''), 0, 1000);

            $contractsBase = realpath(__DIR__ . '/../uploads/contracts')
                          ?: (__DIR__ . '/../uploads/contracts');
            $supplierDir   = $contractsBase . DIRECTORY_SEPARATOR . $uid;
            $uniqueName    = bin2hex(random_bytes(16)) . '.' . $contractResult['ext'];
            $finalPath     = $supplierDir . DIRECTORY_SEPARATOR . $uniqueName;
            $storagePath   = 'uploads/contracts/' . $uid . '/' . $uniqueName;

            $fileMoved = false;
            try {
                if (!is_dir($supplierDir) && !mkdir($supplierDir, 0755, true)) {
                    throw new RuntimeException('mkdir_failed');
                }
                if (!move_uploaded_file($_FILES['contract_file']['tmp_name'], $finalPath)) {
                    throw new RuntimeException('move_failed');
                }
                $fileMoved = true;
                $fileHash  = hash_file('sha256', $finalPath) ?: null;

                $orgId = (int) ($_SESSION['org_id'] ?? 0);

                $pdo->prepare(
                    'INSERT INTO supplier_contracts
                        (supplier_id, org_id, storage_path, original_filename, mime_type, file_size,
                         file_hash, signed_date, effective_start_date, effective_end_date,
                         notes, is_primary, uploaded_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
                )->execute([
                    $uid,
                    $orgId,
                    $storagePath,
                    mb_substr((string) ($_FILES['contract_file']['name'] ?? ''), 0, 255),
                    $contractResult['mime'],
                    (int) ($_FILES['contract_file']['size'] ?? 0),
                    $fileHash,
                    $signedDate,
                    $startDate,
                    $endDate,
                    $notes ?: null,
                    $uid,
                ]);

                // TODO: audit_log — contract upload
                $flash      = t('contract_saved');
                $flashType  = 'success';

            } catch (Throwable $e) {
                if ($fileMoved && is_file($finalPath)) {
                    @unlink($finalPath);
                }
                $errors['contract_file'] = t('err_contract_save');
            }

            $contracts = loadContracts($pdo, $uid);
        }

    // ─── Action: mark_primary_contract ──────────────────────
    } elseif ($action === 'mark_primary_contract') {

        $contractId = (int) ($_POST['contract_id'] ?? 0);

        if ($contractId > 0) {
            $chkStmt = $pdo->prepare(
                'SELECT id FROM supplier_contracts WHERE id = ? AND supplier_id = ? LIMIT 1'
            );
            $chkStmt->execute([$contractId, $uid]);
            if ($chkStmt->fetch()) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'UPDATE supplier_contracts SET is_primary = 0 WHERE supplier_id = ?'
                    )->execute([$uid]);
                    $pdo->prepare(
                        'UPDATE supplier_contracts SET is_primary = 1 WHERE id = ?'
                    )->execute([$contractId]);
                    $pdo->commit();
                    // TODO: audit_log — mark-primary event
                    $flash     = t('contract_mark_primary_ok');
                    $flashType = 'success';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
            }
        }

        $contracts = loadContracts($pdo, $uid);
    }
}

// ── Separate primary from history ─────────────────────────────
$primaryContract  = null;
$historyContracts = [];
foreach ($contracts as $ctc) {
    if ((int) $ctc['is_primary'] === 1 && $primaryContract === null) {
        $primaryContract = $ctc;
    } else {
        $historyContracts[] = $ctc;
    }
}

$uploadPanelOpen = isset($errors['contract_file']);

$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr((string) ($_SESSION['username'] ?? '?'), 0, 1));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('tab_documents') ?></title>
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

    <?= renderTabs('documents') ?>

    <div class="page-content">

        <?php if ($flash): ?>
        <div class="alert alert-<?= $flashType === 'success' ? 'success' : 'error' ?>"
             style="margin-bottom:20px;" role="status">
            <?php if ($flashType === 'success'): ?>
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#34c759" stroke-width="1.5"/>
                <polyline points="4.5,8 7,10.5 11.5,5.5" stroke="#34c759" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php endif; ?>
            <span><?= $esc($flash) ?></span>
        </div>
        <?php endif; ?>

        <!-- ══ CONTRATO VIGENTE ════════════════════════════════ -->
        <div class="card panel-card" style="margin-bottom:24px;">
            <h2 class="card-title"><?= t('contract_current_label') ?></h2>
            <p class="card-subtitle"><?= t('contracts_subtitle') ?></p>

            <?php if ($primaryContract): ?>
            <div style="border:1px solid var(--color-border);border-radius:10px;
                        padding:18px 22px;background:var(--color-bg-card);">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                    <span class="status-badge status-badge--active">
                        <?= t('contract_primary_badge') ?>
                    </span>
                    <span style="font-weight:600;font-size:.92rem;">
                        <?= $esc($primaryContract['original_filename']) ?>
                    </span>
                    <?php if ($primaryContract['signed_date']): ?>
                    <span class="text-muted" style="font-size:.8rem;">
                        <?= $esc(t('col_contract_signed_date')) ?>: <?= $esc($primaryContract['signed_date']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:.82rem;
                            color:var(--color-text-muted);margin-bottom:12px;">
                    <?php if ($primaryContract['effective_start_date']): ?>
                    <span><strong><?= $esc(t('col_contract_start')) ?>:</strong>
                        <?= $esc($primaryContract['effective_start_date']) ?></span>
                    <?php endif; ?>
                    <?php if ($primaryContract['effective_end_date']): ?>
                    <span><strong><?= $esc(t('col_contract_end')) ?>:</strong>
                        <?= $esc($primaryContract['effective_end_date']) ?></span>
                    <?php endif; ?>
                    <span><strong><?= $esc(t('col_contract_uploaded_by')) ?>:</strong>
                        <?= $esc($primaryContract['uploader_username']) ?></span>
                    <span><strong><?= $esc(t('col_contract_uploaded_at')) ?>:</strong>
                        <?= $esc(substr($primaryContract['created_at'], 0, 10)) ?></span>
                </div>
                <?php if ($primaryContract['notes']): ?>
                <p style="font-size:.83rem;color:var(--color-text-muted);margin:0 0 12px;">
                    <?= $esc($primaryContract['notes']) ?>
                </p>
                <?php endif; ?>
                <a href="/login/supplier/contract_file.php?id=<?= (int) $primaryContract['id'] ?>"
                   target="_blank"
                   class="btn-secondary btn-sm">
                    <?= t('btn_view_contract') ?>
                </a>
            </div>
            <?php else: ?>
            <p class="text-muted" style="font-size:.875rem;">
                <?= t('no_contracts') ?>
            </p>
            <?php endif; ?>
        </div>

        <!-- ══ HISTORIAL DE DOCUMENTOS ════════════════════════ -->
        <div class="card panel-card" style="margin-bottom:24px;">
            <h2 class="card-title"><?= t('contract_history_label') ?></h2>

            <?php if ($flash && $flashType === 'success'): ?>
            <!-- Flash was shown at top, no duplicate needed -->
            <?php endif; ?>

            <?php if (isset($errors['contract_file'])): ?>
            <div class="alert alert-error" style="margin-bottom:14px;" role="alert">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                    <line x1="8" y1="4.75" x2="8" y2="8.75" stroke="#ff3b30"
                          stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
                </svg>
                <span><?= $esc($errors['contract_file']) ?></span>
            </div>
            <?php endif; ?>

            <?php if (empty($contracts)): ?>
            <p class="text-muted" style="font-size:.875rem;margin-bottom:20px;">
                <?= t('no_contracts') ?>
            </p>
            <?php else: ?>
            <div class="table-wrap" style="margin-bottom:24px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= $esc(t('col_contract_file')) ?></th>
                            <th><?= $esc(t('col_contract_signed_date')) ?></th>
                            <th><?= $esc(t('col_contract_start')) ?></th>
                            <th><?= $esc(t('col_contract_end')) ?></th>
                            <th><?= $esc(t('col_contract_uploaded_by')) ?></th>
                            <th><?= $esc(t('col_contract_uploaded_at')) ?></th>
                            <th><?= $esc(t('col_contract_notes')) ?></th>
                            <th><?= $esc(t('col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contracts as $c): ?>
                        <tr<?= (int) $c['is_primary'] === 1 ? ' style="background:var(--color-bg-card);"' : '' ?>>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                title="<?= $esc($c['original_filename']) ?>">
                                <?php if ((int) $c['is_primary'] === 1): ?>
                                <span class="status-badge status-badge--active"
                                      style="font-size:.68rem;margin-right:5px;vertical-align:middle;">
                                    <?= t('contract_primary_badge') ?>
                                </span>
                                <?php endif; ?>
                                <?= $esc($c['original_filename']) ?>
                            </td>
                            <td><?= $esc($c['signed_date'] ?? '') ?: '<em class="text-muted">—</em>' ?></td>
                            <td><?= $esc($c['effective_start_date'] ?? '') ?: '<em class="text-muted">—</em>' ?></td>
                            <td><?= $esc($c['effective_end_date'] ?? '') ?: '<em class="text-muted">—</em>' ?></td>
                            <td><?= $esc($c['uploader_username']) ?></td>
                            <td><?= $esc(substr($c['created_at'], 0, 10)) ?></td>
                            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                title="<?= $esc($c['notes'] ?? '') ?>">
                                <?= $esc($c['notes'] ?? '') ?: '<em class="text-muted">—</em>' ?>
                            </td>
                            <td class="actions-cell">
                                <a href="/login/supplier/contract_file.php?id=<?= (int) $c['id'] ?>"
                                   target="_blank"
                                   class="btn-tbl btn-secondary">
                                    <?= $esc(t('btn_view_contract')) ?>
                                </a>
                                <?php if ((int) $c['is_primary'] === 0): ?>
                                <form method="POST" action="/login/supplier/documents.php"
                                      style="display:inline;">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="action" value="mark_primary_contract">
                                    <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="btn-tbl btn-primary">
                                        <?= $esc(t('btn_mark_primary')) ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- ── Subir nuevo documento ──────────────────── -->
            <div>
                <button type="button"
                        class="btn-secondary"
                        style="margin-bottom:16px;"
                        onclick="toggleUploadForm()">
                    <span id="upload-btn-label"><?= $esc(t('btn_add_contract')) ?></span>
                </button>

                <div id="upload-form-panel"
                     style="<?= $uploadPanelOpen ? '' : 'display:none;' ?>">
                    <p class="panel-title"><?= $esc(t('contract_form_title')) ?></p>

                    <form method="POST" action="/login/supplier/documents.php"
                          enctype="multipart/form-data" novalidate>
                        <?= $csrfField ?>
                        <input type="hidden" name="action" value="add_contract">

                        <div class="form-group" style="gap:14px;">

                            <div class="input-wrap">
                                <label for="contract_file">
                                    <?= $esc(t('contract_file_label')) ?>
                                </label>
                                <input type="file"
                                       id="contract_file"
                                       name="contract_file"
                                       accept=".pdf,.jpg,.jpeg,.png"
                                       class="<?= isset($errors['contract_file']) ? 'is-invalid' : '' ?>"
                                       required>
                                <span class="input-help">
                                    <?= $esc(t('contract_file_help')) ?>
                                </span>
                            </div>

                            <div class="form-row">
                                <div class="input-wrap">
                                    <label for="signed_date">
                                        <?= $esc(t('contract_signed_date_label')) ?>
                                    </label>
                                    <input type="date"
                                           id="signed_date"
                                           name="signed_date"
                                           value="<?= $esc($_POST['signed_date'] ?? '') ?>"
                                           max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="input-wrap">
                                    <label for="effective_start_date">
                                        <?= $esc(t('contract_effective_start_label')) ?>
                                    </label>
                                    <input type="date"
                                           id="effective_start_date"
                                           name="effective_start_date"
                                           value="<?= $esc($_POST['effective_start_date'] ?? '') ?>">
                                </div>
                                <div class="input-wrap">
                                    <label for="effective_end_date">
                                        <?= $esc(t('contract_effective_end_label')) ?>
                                    </label>
                                    <input type="date"
                                           id="effective_end_date"
                                           name="effective_end_date"
                                           value="<?= $esc($_POST['effective_end_date'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="input-wrap">
                                <label for="contract_notes">
                                    <?= $esc(t('contract_notes_label')) ?>
                                </label>
                                <textarea id="contract_notes"
                                          name="contract_notes"
                                          rows="3"
                                          maxlength="1000"
                                          placeholder="<?= $esc(t('contract_notes_ph')) ?>"
                                          style="width:100%;resize:vertical;"
                                          ><?= $esc($_POST['contract_notes'] ?? '') ?></textarea>
                            </div>

                            <div>
                                <button type="submit"
                                        class="btn-primary"
                                        style="width:auto;min-width:180px;height:44px;font-size:.9rem;">
                                    <?= $esc(t('btn_save_contract')) ?>
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>

        </div><!-- /historial card -->

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    (function () {
        const TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        let last = Date.now();
        ['mousemove','keydown','click','scroll','touchstart'].forEach(ev =>
            document.addEventListener(ev, () => { last = Date.now(); }, { passive: true })
        );
        setInterval(() => {
            if (Date.now() - last >= TIMEOUT_MS) {
                window.location.href = '/login/index.php?reason=timeout';
            }
        }, 10000);
    })();

    (function () {
        // Auto-open panel if there was a validation error on POST
        var open = <?= $uploadPanelOpen ? 'true' : 'false' ?>;
        var label = document.getElementById('upload-btn-label');
        if (open && label) {
            label.textContent = <?= json_encode(t('btn_cancel')) ?>;
        }
    })();

    function toggleUploadForm() {
        var panel = document.getElementById('upload-form-panel');
        var label = document.getElementById('upload-btn-label');
        if (!panel || !label) return;
        var willOpen = panel.style.display === 'none' || panel.style.display === '';
        panel.style.display = willOpen ? 'block' : 'none';
        label.textContent   = willOpen
            ? <?= json_encode(t('btn_cancel')) ?>
            : <?= json_encode(t('btn_add_contract')) ?>;
        if (willOpen) {
            var fi = panel.querySelector('input[type="file"]');
            if (fi) setTimeout(function () { fi.focus(); }, 50);
        }
    }
    </script>

</body>
</html>

