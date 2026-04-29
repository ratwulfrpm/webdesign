<?php
/**
 * /login/supplier/product_view.php — Detalle de un producto del proveedor
 *
 * URL param : ?id=<product_id>
 * Security  : verifies product belongs to authenticated supplier
 *
 * RBAC  : solo role = 'supplier'
 * Guard : first_login = 0
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

if ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
    exit;
}

$pdo        = getDB();
$lang       = currentLang();
$supplierId = (int) $_SESSION['user_id'];
$productId  = (int) ($_GET['id'] ?? 0);

// ── Load product (ownership check) ───────────────────────────
$product = null;
if ($productId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM supplier_products
          WHERE id = ? AND supplier_id = ?
          LIMIT 1'
    );
    $stmt->execute([$productId, $supplierId]);
    $product = $stmt->fetch();
}

if (!$product) {
    // Product not found or not owned by this supplier
    header('Location: /login/supplier/products.php');
    exit;
}

// ── Load images ───────────────────────────────────────────────
$imgStmt = $pdo->prepare(
    'SELECT image_slot, file_path, original_name, file_size
       FROM supplier_product_images
      WHERE product_id = ?'
);
$imgStmt->execute([$productId]);

$images = []; // keyed by slot name
foreach ($imgStmt->fetchAll() as $img) {
    $images[$img['image_slot']] = $img;
}

// Slot definitions for display
$gallerySlots = [
    'front'  => ['label_key' => 'img_slot_front',  'main' => true],
    'back'   => ['label_key' => 'img_slot_back',   'main' => false],
    'left'   => ['label_key' => 'img_slot_left',   'main' => false],
    'right'  => ['label_key' => 'img_slot_right',  'main' => false],
    'aerial' => ['label_key' => 'img_slot_aerial', 'main' => false],
    'bottom' => ['label_key' => 'img_slot_bottom', 'main' => false],
];

// ── Load keywords ───────────────────────────────────────
$kwStmt = $pdo->prepare(
    'SELECT keyword FROM product_keywords WHERE product_id = ? ORDER BY keyword ASC'
);
$kwStmt->execute([$productId]);
$productKeywords = $kwStmt->fetchAll(PDO::FETCH_COLUMN);

// ── View helpers ──────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$username  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial   = strtoupper(substr((string)($_SESSION['username'] ?? '?'), 0, 1));
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

// Format a price or show dash
$fmtPrice = fn($v): string => $v !== null
    ? '$ ' . number_format((float)$v, 2)
    : '<span class="text-muted">—</span>';

// ── Upload config (used for update) ──────────────────────────
$uploadSlots = [
    'front'  => true,    // required
    'back'   => false,
    'left'   => false,
    'right'  => false,
    'aerial' => false,
    'bottom' => false,
];
$allowedMimes  = ALLOWED_IMAGE_MIMES;
$maxFileBytes  = 5 * 1024 * 1024;
$uploadBaseDir = realpath(__DIR__ . '/../uploads/products')
              ?: (__DIR__ . '/../uploads/products');

// ── Edit form state ───────────────────────────────────────────
$editErrors = [];
$editFlash  = '';
$activeTab  = 'detail';

if (isset($_GET['updated'])) {
    $editFlash = t('product_updated');
}

$editFv = [
    'supplier_product_code' => $product['supplier_product_code'] ?? '',
    'admin_product_code'    => $product['admin_product_code'] ?? '',
    'product_name'          => $product['product_name'] ?? '',
    'technical_description' => $product['technical_description'] ?? '',
    'price_fob'             => $product['price_fob'] !== null ? $product['price_fob'] : '',
    'price_cif'             => $product['price_cif'] !== null ? $product['price_cif'] : '',
];

// ── POST: update_product ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    if (trim($_POST['action'] ?? '') === 'update_product') {
        $activeTab = 'edit';

        $s = fn(string $k, int $max = 255): string =>
            mb_substr(trim($_POST[$k] ?? ''), 0, $max);

        $editFv['supplier_product_code'] = $s('supplier_product_code', 100);
        $editFv['admin_product_code']    = $s('admin_product_code', 100);
        $editFv['product_name']          = $s('product_name', 300);
        $editFv['technical_description'] = mb_substr(trim($_POST['technical_description'] ?? ''), 0, 10000);
        $editFv['price_fob']             = $s('price_fob', 30);
        $editFv['price_cif']             = $s('price_cif', 30);

        if ($editFv['supplier_product_code'] === '') {
            $editErrors['supplier_product_code'] = t('err_supplier_code_required');
        }
        if ($editFv['product_name'] === '') {
            $editErrors['product_name'] = t('err_product_name_required');
        }

        $priceFobClean = null;
        if ($editFv['price_fob'] !== '') {
            $c = str_replace([',',' '], ['.',''], $editFv['price_fob']);
            if (!is_numeric($c) || (float)$c < 0) {
                $editErrors['price_fob'] = t('err_price_fob_numeric');
            } else {
                $priceFobClean = (float)$c;
            }
        }

        $priceCifClean = null;
        if ($editFv['price_cif'] !== '') {
            $c = str_replace([',',' '], ['.',''], $editFv['price_cif']);
            if (!is_numeric($c) || (float)$c < 0) {
                $editErrors['price_cif'] = t('err_price_cif_numeric');
            } else {
                $priceCifClean = (float)$c;
            }
        }

        if ($editFv['technical_description'] !== '') {
            if (preg_match('/https?:\/\/|www\./i', $editFv['technical_description'])) {
                $editErrors['technical_description'] = t('err_desc_no_links');
            }
        }

        // Uniqueness: supplier code per supplier, excluding this product
        if ($editFv['supplier_product_code'] !== '' && !isset($editErrors['supplier_product_code'])) {
            $dup = $pdo->prepare(
                'SELECT id FROM supplier_products
                  WHERE supplier_id = ? AND supplier_product_code = ? AND id != ? LIMIT 1'
            );
            $dup->execute([$supplierId, $editFv['supplier_product_code'], $productId]);
            if ($dup->fetch()) {
                $editErrors['supplier_product_code'] = t('err_supplier_code_duplicate');
            }
        }

        // Validate new image uploads (only those that were actually submitted)
        foreach ($uploadSlots as $slot => $required) {
            $fileKey = 'img_' . $slot;
            $file    = $_FILES[$fileKey] ?? ['error' => UPLOAD_ERR_NO_FILE];
            if ($file['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $editErrors[$fileKey] = t('err_img_upload_failed');
                continue;
            }
            if ($file['size'] > $maxFileBytes) {
                $editErrors[$fileKey] = t('err_img_size');
                continue;
            }
            $result = validateUploadedImage($file, $maxFileBytes);
            if (!$result['ok']) {
                $editErrors[$fileKey] = t($result['error']);
            }
        }

        if (empty($editErrors)) {
            try {
                $pdo->beginTransaction();

                $pdo->prepare(
                    'UPDATE supplier_products
                        SET supplier_product_code = ?,
                            product_name          = ?,
                            technical_description = ?,
                            price_fob             = ?,
                            price_cif             = ?
                      WHERE id = ? AND supplier_id = ?'
                )->execute([
                    $editFv['supplier_product_code'],
                    $editFv['product_name'],
                    $editFv['technical_description'] !== '' ? $editFv['technical_description'] : null,
                    $priceFobClean,
                    $priceCifClean,
                    $productId,
                    $supplierId,
                ]);

                $productDir = $uploadBaseDir . DIRECTORY_SEPARATOR . $productId;
                foreach ($uploadSlots as $slot => $required) {
                    $deleteKey  = 'delete_img_' . $slot;
                    $fileKey    = 'img_' . $slot;
                    $file       = $_FILES[$fileKey] ?? ['error' => UPLOAD_ERR_NO_FILE];
                    $hasNewFile = $file['error'] === UPLOAD_ERR_OK;
                    $doDelete   = ($hasNewFile || isset($_POST[$deleteKey])) && isset($images[$slot]);

                    if ($doDelete) {
                        $oldPath = __DIR__ . '/../' . $images[$slot]['file_path'];
                        if (file_exists($oldPath)) @unlink($oldPath);
                        $pdo->prepare(
                            'DELETE FROM supplier_product_images
                              WHERE product_id = ? AND image_slot = ?'
                        )->execute([$productId, $slot]);
                    }

                    if ($hasNewFile) {
                        if (!is_dir($productDir)) mkdir($productDir, 0755, true);
                        $result   = validateUploadedImage($file, $maxFileBytes);
                        $ext      = $result['ok'] ? $result['ext'] : 'jpg';
                        $mime     = $result['ok'] ? $result['mime'] : 'image/jpeg';
                        $safeName = $slot . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $dest     = $productDir . DIRECTORY_SEPARATOR . $safeName;
                        if (!move_uploaded_file($file['tmp_name'], $dest)) {
                            throw new RuntimeException('move_uploaded_file failed for: ' . $slot);
                        }
                        $relPath  = 'uploads/products/' . $productId . '/' . $safeName;
                        $origName = mb_substr(basename($file['name']), 0, 255);
                        $pdo->prepare(
                            'INSERT INTO supplier_product_images
                                (product_id, supplier_id, image_slot,
                                 file_path, original_name, file_size,
                                 mime_type, uploaded_by_user_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        )->execute([
                            $productId, $supplierId, $slot,
                            $relPath, $origName, (int) $file['size'],
                            $mime, $supplierId,
                        ]);
                    }
                }

                // ── Keyword update: replace all keywords ──────────────
                $rawKwJson = trim($_POST['keywords_json'] ?? '');
                if ($rawKwJson !== '') {
                    $kwDecoded = json_decode($rawKwJson, true);
                    if (is_array($kwDecoded)) {
                        $parsedKws = [];
                        foreach ($kwDecoded as $kw) {
                            $kw = mb_strtolower(trim((string) $kw));
                            if ($kw !== '' && mb_strlen($kw) <= 60
                                && !preg_match('/\s/', $kw)
                                && preg_match('/^[\p{L}\p{N}\-_]+$/u', $kw)
                                && !in_array($kw, $parsedKws, true)) {
                                $parsedKws[] = $kw;
                            }
                        }
                        // Replace keywords: delete existing then insert new
                        $pdo->prepare(
                            'DELETE FROM product_keywords WHERE product_id = ?'
                        )->execute([$productId]);
                        if (!empty($parsedKws)) {
                            $kwIns = $pdo->prepare(
                                'INSERT IGNORE INTO product_keywords (product_id, keyword) VALUES (?, ?)'
                            );
                            foreach ($parsedKws as $kw) {
                                $kwIns->execute([$productId, $kw]);
                            }
                        }
                    }
                }

                $pdo->commit();
                header('Location: /login/supplier/product_view.php?id=' . $productId . '&updated=1');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $editErrors['_general'] = t('err_product_update_failed');
                error_log('Product update error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('product_view_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=12">
</head>
<body class="wide-layout">

    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= $username ?>
                <span class="org-badge"><?= $esc($_SESSION['org_name'] ?? '') ?></span>
            </span>
        </div>
        <div class="top-bar-right">
            <nav class="top-bar-lang" aria-label="<?= t('language_label') ?>">
                <a href="?id=<?= $productId ?>&set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
                <span class="lang-sep">|</span>
                <a href="?id=<?= $productId ?>&set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
                <span class="lang-sep">|</span>
                <a href="?id=<?= $productId ?>&set_lang=zh" class="lang-btn<?= $lang === 'zh' ? ' active' : '' ?>" hreflang="zh">中文</a>
            </nav>
            <form method="POST" action="/login/logout.php" class="top-bar-logout">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
            </form>
        </div>
    </div>

    <?= renderTabs('my_products') ?>

    <div class="page-content">

        <!-- Back button -->
        <div style="margin-bottom:18px;">
            <a href="/login/supplier/products.php" class="btn-secondary btn-sm">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"
                     style="vertical-align:middle;margin-right:4px;">
                    <path d="M10 12L6 8l4-4" stroke="currentColor" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?= $esc(t('btn_back_to_products')) ?>
            </a>
        </div>

        <!-- ════════════════════ PRODUCT DETAIL ════════════════════ -->
        <div class="card profile-form-card" style="max-width:860px;">

            <h1 class="card-title"><?= t('product_view_title') ?></h1>

            <!-- ── Inner tab bar ───────────────────────────── -->
            <div class="pv-tabs">
                <button type="button"
                        class="pv-tab-btn<?= $activeTab === 'detail' ? ' pv-tab-btn--active' : '' ?>"
                        id="btn-tab-detail"
                        onclick="pvSwitchTab('detail')">
                    <?= $esc(t('tab_product_detail')) ?>
                </button>
                <button type="button"
                        class="pv-tab-btn<?= $activeTab === 'edit' ? ' pv-tab-btn--active' : '' ?>"
                        id="btn-tab-edit"
                        onclick="pvSwitchTab('edit')">
                    <?= $esc(t('tab_product_edit')) ?>
                </button>
            </div>

            <?php if ($editFlash): ?>
            <div class="alert alert-success" style="margin-bottom:18px;" role="status">
                <?= $esc($editFlash) ?>
            </div>
            <?php endif; ?>

            <!-- ── Pane: detail ────────────────────────────── -->
            <div id="pv-pane-detail"<?= $activeTab === 'edit' ? ' style="display:none"' : '' ?>>

            <!-- ── Product info grid ───────────────────────── -->
            <div class="product-detail-grid" style="margin-bottom:28px;">

                <div class="detail-row">
                    <span class="detail-label"><?= $esc(t('col_product_code')) ?></span>
                    <span class="detail-value">
                        <code style="font-size:0.9rem;background:#f5f5f7;
                                     padding:2px 8px;border-radius:6px;">
                            <?= $esc($product['supplier_product_code']) ?>
                        </code>
                    </span>
                </div>

                <?php if (!empty($product['admin_product_code'])): ?>
                <div class="detail-row">
                    <span class="detail-label"><?= $esc(t('field_admin_code')) ?></span>
                    <span class="detail-value">
                        <code style="font-size:0.9rem;background:#f0f0f3;
                                     padding:2px 8px;border-radius:6px;">
                            <?= $esc($product['admin_product_code']) ?>
                        </code>
                    </span>
                </div>
                <?php endif; ?>

                <div class="detail-row">
                    <span class="detail-label"><?= $esc(t('field_product_name')) ?></span>
                    <span class="detail-value" style="font-weight:600;font-size:1.05rem;">
                        <?= $esc($product['product_name']) ?>
                    </span>
                </div>

                <div class="detail-row">
                    <span class="detail-label"><?= $esc(t('field_price_fob')) ?></span>
                    <span class="detail-value"><?= $fmtPrice($product['price_fob']) ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label"><?= $esc(t('field_price_cif')) ?></span>
                    <span class="detail-value"><?= $fmtPrice($product['price_cif']) ?></span>
                </div>

                <div class="detail-row">
                    <span class="detail-label"><?= $esc(t('col_product_date')) ?></span>
                    <span class="detail-value text-muted">
                        <?= date('d/m/Y H:i', strtotime($product['created_at'])) ?>
                    </span>
                </div>

                <?php if (!empty($product['technical_description'])): ?>
                <div class="detail-row detail-row--full">
                    <span class="detail-label"><?= $esc(t('field_tech_desc')) ?></span>
                    <div class="detail-value tech-desc-block">
                        <?= nl2br($esc($product['technical_description'])) ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- ── Image gallery ───────────────────────────── -->
            <div class="form-section">
                <h2 class="form-section-title">
                    <span class="section-icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="3" width="18" height="18" rx="3"
                                  stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M21 15l-5-5L5 21"
                                  stroke="currentColor" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <?= t('section_product_gallery') ?>
                </h2>

                <?php if (empty($images)): ?>
                    <p class="text-muted"><?= t('no_images') ?></p>
                <?php else: ?>

                <!-- Front view (main/large) -->
                <?php if (isset($images['front'])): ?>
                <div style="margin-bottom:16px;">
                    <div class="gallery-slot gallery-slot--aerial">
                        <img src="/login/<?= $esc($images['front']['file_path']) ?>"
                             alt="<?= $esc(t('img_slot_front')) ?>"
                             class="gallery-slot-img"
                             onclick="openLightbox(this.src, this.alt)">
                        <div class="gallery-slot-caption">
                            <?= $esc(t('img_slot_front')) ?>
                            <span class="img-slot-required">*</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Optional views grid -->
                <?php
                $optionalSlots = ['back', 'left', 'right', 'aerial', 'bottom'];
                $hasOptional = false;
                foreach ($optionalSlots as $s) {
                    if (isset($images[$s])) { $hasOptional = true; break; }
                }
                if ($hasOptional):
                ?>
                <div class="product-gallery" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
                    <?php foreach ($optionalSlots as $slot): ?>
                        <?php if (isset($images[$slot])): ?>
                        <div class="gallery-slot">
                            <img src="/login/<?= $esc($images[$slot]['file_path']) ?>"
                                 alt="<?= $esc(t('img_slot_' . $slot)) ?>"
                                 class="gallery-slot-img"
                                 onclick="openLightbox(this.src, this.alt)">
                            <div class="gallery-slot-caption">
                                <?= $esc(t('img_slot_' . $slot)) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php endif; // empty($images) ?>

            </div><!-- /form-section gallery -->

            <!-- ── Keywords display ──────────────────── -->
            <?php if (!empty($productKeywords)): ?>
            <div class="form-section" style="margin-top:20px;">
                <h2 class="form-section-title" style="font-size:0.95rem;">
                    <?= $esc(t('section_product_keywords')) ?>
                </h2>
                <div class="keyword-tags-readonly">
                    <?php foreach ($productKeywords as $kw): ?>
                    <span class="keyword-chip keyword-chip--readonly"><?= $esc($kw) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- /pv-pane-detail -->

            <!-- ═══════════════ PANE: EDIT ═══════════════ -->
            <div id="pv-pane-edit"<?= $activeTab !== 'edit' ? ' style="display:none"' : '' ?>>

                <form method="POST" enctype="multipart/form-data"
                      action="/login/supplier/product_view.php?id=<?= $productId ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_product">

                    <?php if (isset($editErrors['_general'])): ?>
                    <div class="alert alert-error" style="margin-bottom:16px;" role="alert">
                        <?= $esc($editErrors['_general']) ?>
                    </div>
                    <?php elseif (!empty($editErrors)): ?>
                    <div class="alert alert-error" style="margin-bottom:16px;" role="alert">
                        <?= $esc(t('profile_error_fields')) ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── Información del producto ──────── -->
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <span class="section-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                    <rect x="2" y="7" width="20" height="14" rx="2"
                                          stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"
                                          stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                            </span>
                            <?= $esc(t('section_product_info')) ?>
                        </h2>

                        <!-- Código proveedor -->
                        <div class="input-wrap">
                            <label for="edit_sup_code">
                                <?= $esc(t('field_supplier_code')) ?> *
                            </label>
                            <input type="text"
                                   id="edit_sup_code"
                                   name="supplier_product_code"
                                   class="<?= isset($editErrors['supplier_product_code']) ? 'is-invalid' : '' ?>"
                                   value="<?= $esc($editFv['supplier_product_code']) ?>"
                                   maxlength="100" required autocomplete="off">
                            <span class="input-help"><?= $esc(t('field_supplier_code_help')) ?></span>
                            <?php if (isset($editErrors['supplier_product_code'])): ?>
                            <span class="field-error"><?= $esc($editErrors['supplier_product_code']) ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Nombre del producto -->
                        <div class="input-wrap" style="margin-top:14px;">
                            <label for="edit_prod_name">
                                <?= $esc(t('field_product_name')) ?> *
                            </label>
                            <input type="text"
                                   id="edit_prod_name"
                                   name="product_name"
                                   class="<?= isset($editErrors['product_name']) ? 'is-invalid' : '' ?>"
                                   value="<?= $esc($editFv['product_name']) ?>"
                                   maxlength="300" required autocomplete="off">
                            <?php if (isset($editErrors['product_name'])): ?>
                            <span class="field-error"><?= $esc($editErrors['product_name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Descripción técnica -->
                        <div class="input-wrap" style="margin-top:14px;">
                            <label for="edit_tech_desc">
                                <?= $esc(t('field_tech_desc')) ?>
                            </label>
                            <textarea id="edit_tech_desc"
                                      name="technical_description"
                                      class="form-textarea<?= isset($editErrors['technical_description']) ? ' is-invalid' : '' ?>"
                                      rows="7"
                                      maxlength="10000"><?= $esc($editFv['technical_description']) ?></textarea>
                            <span class="input-help"><?= $esc(t('field_tech_desc_help')) ?></span>
                            <?php if (isset($editErrors['technical_description'])): ?>
                            <span class="field-error"><?= $esc($editErrors['technical_description']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div><!-- /form-section info -->

                    <!-- ── Precios ───────────────────────── -->
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <span class="section-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M8 4.5v7M6 6.5c0-1.1.9-2 2-2s2 .9 2 2-1.33 2-2 2-2 .9-2 2 .9 2 2 2 2-.9 2-2"
                                          stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                </svg>
                            </span>
                            <?= $esc(t('section_product_pricing')) ?>
                        </h2>
                        <div class="form-row">
                            <div class="input-wrap">
                                <label for="edit_price_fob"><?= $esc(t('field_price_fob')) ?></label>
                                <input type="text"
                                       id="edit_price_fob"
                                       name="price_fob"
                                       class="<?= isset($editErrors['price_fob']) ? 'is-invalid' : '' ?>"
                                       value="<?= $esc($editFv['price_fob']) ?>"
                                       placeholder="<?= $esc(t('field_price_fob_ph')) ?>"
                                       maxlength="30" inputmode="decimal">
                                <?php if (isset($editErrors['price_fob'])): ?>
                                <span class="field-error"><?= $esc($editErrors['price_fob']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="input-wrap">
                                <label for="edit_price_cif"><?= $esc(t('field_price_cif')) ?></label>
                                <input type="text"
                                       id="edit_price_cif"
                                       name="price_cif"
                                       class="<?= isset($editErrors['price_cif']) ? 'is-invalid' : '' ?>"
                                       value="<?= $esc($editFv['price_cif']) ?>"
                                       placeholder="<?= $esc(t('field_price_cif_ph')) ?>"
                                       maxlength="30" inputmode="decimal">
                                <?php if (isset($editErrors['price_cif'])): ?>
                                <span class="field-error"><?= $esc($editErrors['price_cif']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div><!-- /form-section pricing -->

                    <!-- ── Imágenes ──────────────────────── -->
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <span class="section-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                    <rect x="3" y="3" width="18" height="18" rx="3"
                                          stroke="currentColor" stroke-width="1.5"/>
                                    <circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M21 15l-5-5L5 21"
                                          stroke="currentColor" stroke-width="1.5"
                                          stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <?= $esc(t('section_product_images')) ?>
                        </h2>

                        <?php
                        $editImgSlots = [
                            'front'  => ['label_key' => 'img_front_label',  'required' => true],
                            'back'   => ['label_key' => 'img_back_label',   'required' => false],
                            'left'   => ['label_key' => 'img_left_label',   'required' => false],
                            'right'  => ['label_key' => 'img_right_label',  'required' => false],
                            'aerial' => ['label_key' => 'img_aerial_label', 'required' => false],
                            'bottom' => ['label_key' => 'img_bottom_label', 'required' => false],
                        ];
                        ?>

                        <!-- Vista frontal (ancho completo) -->
                        <div class="img-grid-main" style="margin-bottom:20px;">
                        <?php $slot = 'front'; $ecfg = $editImgSlots[$slot]; $fk = 'img_' . $slot; ?>
                        <div class="img-edit-slot" id="edit-slot-<?= $slot ?>">
                            <div class="img-slot-meta" style="margin-bottom:6px;">
                                <span class="img-slot-label">
                                    <?= $esc(t($ecfg['label_key'])) ?>
                                    <span class="img-slot-required">*</span>
                                </span>
                            </div>
                            <?php if (isset($images[$slot])): ?>
                            <div class="edit-img-current" id="edit-current-<?= $slot ?>">
                                <img src="/login/<?= $esc($images[$slot]['file_path']) ?>"
                                     alt="<?= $esc(t('img_slot_' . $slot)) ?>"
                                     class="edit-img-thumb"
                                     onclick="openLightbox(this.src,this.alt)"
                                     title="<?= $esc(t('edit_img_click_zoom')) ?>">
                                <label class="edit-img-delete-label">
                                    <input type="checkbox" name="delete_img_<?= $slot ?>" value="1"
                                           id="del-<?= $slot ?>">
                                    <?= $esc(t('edit_img_delete')) ?>
                                </label>
                                <div class="edit-img-replace-hint"><?= $esc(t('edit_img_or_replace')) ?></div>
                            </div>
                            <?php endif; ?>
                            <input type="file" id="img_<?= $slot ?>" name="img_<?= $slot ?>"
                                   accept="image/*"
                                   class="img-upload-input" data-slot="<?= $slot ?>">
                            <div class="img-upload-area<?= isset($editErrors[$fk]) ? ' is-invalid' : '' ?>"
                                 id="edit-area-<?= $slot ?>"
                                 onclick="editTriggerUpload('<?= $slot ?>')"
                                 role="button" tabindex="0"
                                 onkeydown="if(event.key==='Enter'||event.key===' ')editTriggerUpload('<?= $slot ?>')">
                                <div class="img-upload-preview-wrap" id="edit-preview-<?= $slot ?>" style="display:none">
                                    <img src="" alt="" class="img-upload-preview-img" id="edit-previewImg-<?= $slot ?>">
                                </div>
                                <div class="img-upload-placeholder" id="edit-placeholder-<?= $slot ?>">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"
                                              stroke="currentColor" stroke-width="1.5"
                                              stroke-linecap="round" stroke-linejoin="round"/>
                                        <circle cx="12" cy="13" r="4" stroke="currentColor" stroke-width="1.5"/>
                                    </svg>
                                    <span><?= $esc(t('img_click_to_upload')) ?></span>
                                    <span class="img-upload-type-hint"><?= $esc(t('img_allowed_types')) ?></span>
                                </div>
                            </div>
                            <div class="img-slot-meta" style="margin-top:6px;">
                                <button type="button" class="btn-img-remove"
                                        id="edit-remove-<?= $slot ?>"
                                        onclick="editRemoveImage('<?= $slot ?>')"
                                        style="display:none"><?= $esc(t('img_remove')) ?></button>
                            </div>
                            <?php if (isset($editErrors[$fk])): ?>
                            <span class="field-error"><?= $esc($editErrors[$fk]) ?></span>
                            <?php endif; ?>
                        </div>
                        </div><!-- /img-grid-main -->

                        <!-- Vistas opcionales -->
                        <div class="img-grid-lateral img-grid-lateral--5col">
                        <?php foreach (['back','left','right','aerial','bottom'] as $slot):
                            $ecfg = $editImgSlots[$slot]; $fk = 'img_' . $slot;
                        ?>
                        <div class="img-edit-slot" id="edit-slot-<?= $slot ?>">
                            <div class="img-slot-meta" style="margin-bottom:6px;">
                                <span class="img-slot-label">
                                    <?= $esc(t($ecfg['label_key'])) ?>
                                    <span class="img-slot-optional">(<?= $esc(t('product_view_optional')) ?>)</span>
                                </span>
                            </div>
                            <?php if (isset($images[$slot])): ?>
                            <div class="edit-img-current" id="edit-current-<?= $slot ?>">
                                <img src="/login/<?= $esc($images[$slot]['file_path']) ?>"
                                     alt="<?= $esc(t('img_slot_' . $slot)) ?>"
                                     class="edit-img-thumb"
                                     onclick="openLightbox(this.src,this.alt)"
                                     title="<?= $esc(t('edit_img_click_zoom')) ?>">
                                <label class="edit-img-delete-label">
                                    <input type="checkbox" name="delete_img_<?= $slot ?>" value="1"
                                           id="del-<?= $slot ?>">
                                    <?= $esc(t('edit_img_delete')) ?>
                                </label>
                                <div class="edit-img-replace-hint"><?= $esc(t('edit_img_or_replace')) ?></div>
                            </div>
                            <?php endif; ?>
                            <input type="file" id="img_<?= $slot ?>" name="img_<?= $slot ?>"
                                   accept="image/*"
                                   class="img-upload-input" data-slot="<?= $slot ?>">
                            <div class="img-upload-area<?= isset($editErrors[$fk]) ? ' is-invalid' : '' ?>"
                                 id="edit-area-<?= $slot ?>"
                                 onclick="editTriggerUpload('<?= $slot ?>')"
                                 role="button" tabindex="0"
                                 onkeydown="if(event.key==='Enter'||event.key===' ')editTriggerUpload('<?= $slot ?>')">
                                <div class="img-upload-preview-wrap" id="edit-preview-<?= $slot ?>" style="display:none">
                                    <img src="" alt="" class="img-upload-preview-img" id="edit-previewImg-<?= $slot ?>">
                                </div>
                                <div class="img-upload-placeholder" id="edit-placeholder-<?= $slot ?>">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"
                                              stroke="currentColor" stroke-width="1.5"
                                              stroke-linecap="round" stroke-linejoin="round"/>
                                        <circle cx="12" cy="13" r="4" stroke="currentColor" stroke-width="1.5"/>
                                    </svg>
                                    <span><?= $esc(t('img_click_to_upload')) ?></span>
                                    <span class="img-upload-type-hint"><?= $esc(t('img_allowed_types')) ?></span>
                                </div>
                            </div>
                            <div class="img-slot-meta" style="margin-top:6px;">
                                <button type="button" class="btn-img-remove"
                                        id="edit-remove-<?= $slot ?>"
                                        onclick="editRemoveImage('<?= $slot ?>')"
                                        style="display:none"><?= $esc(t('img_remove')) ?></button>
                            </div>
                            <?php if (isset($editErrors[$fk])): ?>
                            <span class="field-error"><?= $esc($editErrors[$fk]) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        </div><!-- /img-grid-lateral -->

                    </div><!-- /form-section images -->

                    <!-- ── Palabras clave (editar) ───────── -->
                    <div class="form-section">
                        <h2 class="form-section-title">
                            <span class="section-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                    <path d="M7 7h10M7 12h6" stroke="currentColor"
                                          stroke-width="1.5" stroke-linecap="round"/>
                                    <rect x="3" y="3" width="18" height="18" rx="3"
                                          stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                            </span>
                            <?= $esc(t('section_product_keywords')) ?>
                        </h2>
                        <p class="input-help" style="margin-bottom:14px;">
                            <?= $esc(t('keywords_subtitle')) ?>
                        </p>
                        <div class="keyword-input-wrap">
                            <div class="keyword-tags" id="edit-keyword-tags" role="list"></div>
                            <div class="keyword-add-row">
                                <input type="text" id="edit-keyword-input"
                                       class="keyword-text-input"
                                       placeholder="<?= $esc(t('keywords_input_ph')) ?>"
                                       maxlength="60" autocomplete="off">
                                <button type="button" id="edit-keyword-add-btn"
                                        class="btn-secondary btn-sm">
                                    <?= $esc(t('btn_add_keyword')) ?>
                                </button>
                            </div>
                            <div id="edit-keyword-client-error" class="field-error"
                                 style="display:none;margin-top:6px;"></div>
                        </div>
                        <input type="hidden" id="edit-keywords_json" name="keywords_json"
                               value="<?= $esc(json_encode($productKeywords)) ?>">
                    </div>

                    <!-- ── Botones ───────────────────────── -->
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <?= $esc(t('btn_save_product')) ?>
                        </button>
                        <button type="button" class="btn-secondary"
                                onclick="pvSwitchTab('detail')">
                            <?= $esc(t('btn_cancel_product')) ?>
                        </button>
                    </div>

                </form>

            </div><!-- /pv-pane-edit -->
        </div><!-- /card -->
    </div><!-- /page-content -->

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightbox" role="dialog" aria-modal="true"
         style="display:none;" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()" aria-label="Cerrar">&times;</button>
        <img src="" alt="" class="lightbox-img" id="lightboxImg">
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    (function () {
        // ── Idle timeout ──────────────────────────────────────
        const TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        let last = Date.now();
        ['mousemove','keydown','click','scroll'].forEach(function(ev){
            document.addEventListener(ev, function(){ last = Date.now(); }, {passive:true});
        });
        setInterval(function(){
            if (Date.now() - last >= TIMEOUT_MS) {
                window.location.href = '/login/index.php?reason=timeout';
            }
        }, 10000);

        // ── Lightbox ──────────────────────────────────────────
        window.openLightbox = function(src, alt) {
            var lb    = document.getElementById('lightbox');
            var lbImg = document.getElementById('lightboxImg');
            lbImg.src = src;
            lbImg.alt = alt || '';
            lb.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        };

        window.closeLightbox = function() {
            var lb = document.getElementById('lightbox');
            lb.style.display = 'none';
            document.body.style.overflow = '';
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeLightbox();
        });

        // ── Product view inner tabs ───────────────────────────
        window.pvSwitchTab = function(which) {
            ['detail','edit'].forEach(function(id) {
                var pane = document.getElementById('pv-pane-' + id);
                var btn  = document.getElementById('btn-tab-' + id);
                if (pane) pane.style.display = (id === which) ? '' : 'none';
                if (btn)  btn.classList.toggle('pv-tab-btn--active', id === which);
            });
        };

        // ── Edit image upload handling ────────────────────────
        var EDIT_MAX_BYTES = <?= $maxFileBytes ?>;

        window.editTriggerUpload = function(slot) {
            var input = document.getElementById('img_' + slot);
            if (input) input.click();
        };

        window.editRemoveImage = function(slot) {
            var input   = document.getElementById('img_' + slot);
            var preview = document.getElementById('edit-preview-' + slot);
            var ph      = document.getElementById('edit-placeholder-' + slot);
            var btn     = document.getElementById('edit-remove-' + slot);
            var area    = document.getElementById('edit-area-' + slot);

            if (input) {
                var newInput = input.cloneNode(true);
                newInput.value = '';
                input.parentNode.replaceChild(newInput, input);
                newInput.addEventListener('change', function(){ editHandleFileChange(slot); });
            }
            if (preview) preview.style.display = 'none';
            if (ph)      ph.style.display = '';
            if (btn)     btn.style.display = 'none';
            if (area)    area.classList.remove('is-invalid');
        };

        function editHandleFileChange(slot) {
            var input      = document.getElementById('img_' + slot);
            var preview    = document.getElementById('edit-preview-' + slot);
            var previewImg = document.getElementById('edit-previewImg-' + slot);
            var ph         = document.getElementById('edit-placeholder-' + slot);
            var btn        = document.getElementById('edit-remove-' + slot);
            var area       = document.getElementById('edit-area-' + slot);

            if (!input || !input.files || !input.files[0]) return;

            var file    = input.files[0];
            var allowed = ['image/jpeg','image/png','image/webp','image/gif','image/bmp','image/avif'];
            var svgTypes = ['image/svg+xml','image/svg'];

            if (area) area.classList.remove('is-invalid');

            // Block SVG explicitly
            if (svgTypes.indexOf(file.type) !== -1) {
                if (area) area.classList.add('is-invalid');
                editRemoveImage(slot);
                return;
            }
            if (allowed.indexOf(file.type) === -1) {
                if (area) area.classList.add('is-invalid');
                editRemoveImage(slot);
                return;
            }
            if (file.size > EDIT_MAX_BYTES) {
                if (area) area.classList.add('is-invalid');
                editRemoveImage(slot);
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                if (previewImg) previewImg.src = e.target.result;
                if (preview) preview.style.display = '';
                if (ph)      ph.style.display = 'none';
                if (btn)     btn.style.display = '';
            };
            reader.readAsDataURL(file);
        }

        ['front','back','left','right','aerial','bottom'].forEach(function(slot) {
            var input = document.getElementById('img_' + slot);
            if (input) {
                input.addEventListener('change', function(){ editHandleFileChange(slot); });
            }
        });

        // ── Edit keywords widget ──────────────────────────────
        var editKwJson     = document.getElementById('edit-keywords_json');
        var editKwInput    = document.getElementById('edit-keyword-input');
        var editKwAddBtn   = document.getElementById('edit-keyword-add-btn');
        var editKwTags     = document.getElementById('edit-keyword-tags');
        var editKwErrEl    = document.getElementById('edit-keyword-client-error');
        var editCurrentKws = [];

        try {
            var ekStored = JSON.parse(editKwJson ? (editKwJson.value || '[]') : '[]');
            if (Array.isArray(ekStored)) {
                ekStored.forEach(function(k) { if (k) ekAddTag(k, true); });
            }
        } catch(ex) {}

        function ekShowErr(msg) { if (editKwErrEl) { editKwErrEl.textContent = msg; editKwErrEl.style.display = 'block'; } }
        function ekClearErr()   { if (editKwErrEl) { editKwErrEl.style.display = 'none'; editKwErrEl.textContent = ''; } }
        function ekSave()       { if (editKwJson) editKwJson.value = JSON.stringify(editCurrentKws); }

        function ekAddTag(kw, skipCheck) {
            kw = kw.trim().toLowerCase();
            if (!skipCheck && editCurrentKws.indexOf(kw) !== -1) return false;
            if (editCurrentKws.indexOf(kw) === -1) editCurrentKws.push(kw);
            var chip = document.createElement('span');
            chip.className = 'keyword-chip';
            chip.setAttribute('role', 'listitem');
            var text = document.createTextNode(kw + '\u00a0');
            chip.appendChild(text);
            var rb = document.createElement('button');
            rb.type = 'button'; rb.className = 'keyword-chip-remove';
            rb.setAttribute('aria-label', 'Eliminar ' + kw); rb.textContent = '\u00d7';
            rb.addEventListener('click', function() {
                var i = editCurrentKws.indexOf(kw);
                if (i > -1) editCurrentKws.splice(i, 1);
                if (editKwTags && chip.parentNode === editKwTags) editKwTags.removeChild(chip);
                ekSave();
            });
            chip.appendChild(rb);
            if (editKwTags) editKwTags.appendChild(chip);
            ekSave(); return true;
        }

        function ekValidateAdd() {
            ekClearErr();
            var kw = editKwInput ? (editKwInput.value || '').trim().toLowerCase() : '';
            if (!kw) { ekShowErr('<?= addslashes(t('err_keyword_empty')) ?>'); return; }
            if (/\s/.test(kw)) { ekShowErr('<?= addslashes(t('err_keyword_spaces')) ?>'); return; }
            if (kw.length > 60) { ekShowErr('<?= addslashes(t('err_keyword_too_long')) ?>'); return; }
            if (!/^[\w\-]+$/i.test(kw)) { ekShowErr('<?= addslashes(t('err_keyword_invalid_chars')) ?>'); return; }
            if (editCurrentKws.indexOf(kw) !== -1) { ekShowErr('<?= addslashes(t('err_keyword_duplicate')) ?>'); return; }
            ekAddTag(kw, false);
            if (editKwInput) { editKwInput.value = ''; editKwInput.focus(); }
        }
        if (editKwAddBtn) editKwAddBtn.addEventListener('click', ekValidateAdd);
        if (editKwInput) {
            editKwInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); ekValidateAdd(); }
            });
        }
    }());
    </script>

</body>
</html>
