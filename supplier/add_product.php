<?php
/**
 * /login/supplier/add_product.php — Carga de productos con imágenes y palabras clave
 *
 * POST actions:
 *  save_product — valida y persiste producto + fotos + keywords
 *
 * RBAC  : solo role = 'supplier'
 * Guard : first_login = 0
 *
 * Fotos (hasta 6, una por vista canónica):
 *  - img_front   → vista frontal   (REQUERIDA)
 *  - img_back    → vista trasera   (opcional)
 *  - img_left    → vista lateral izquierda (opcional)
 *  - img_right   → vista lateral derecha   (opcional)
 *  - img_aerial  → vista aérea     (opcional)
 *  - img_bottom  → vista desde abajo (opcional)
 *  Máx. 5 MB por imagen | formatos: JPG / PNG / WEBP / GIF / BMP / AVIF
 *
 * Keywords:
 *  - palabras clave individuales (sin espacios, lowercase)
 *  - enviadas como JSON en campo oculto keywords_json
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
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tabs.php';
require_once __DIR__ . '/../includes/image_validate.php';
require_once __DIR__ . '/../includes/product_code.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/Validator.php';
require_once __DIR__ . '/../includes/Input.php';

requireAuth();
initLang();
requireRole(['supplier']);

if ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
    exit;
}

$pdo     = getDB();
$lang    = currentLang();
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// ── Upload config ─────────────────────────────────────────────
// Canonical view slots: front is required, others optional.
// ENUM in DB: 'front','back','left','right','aerial','bottom'
define('ALLOWED_VIEW_TYPES', ['front', 'back', 'left', 'right', 'aerial', 'bottom']);

$uploadSlots = [
    'front'  => true,    // required
    'back'   => false,
    'left'   => false,
    'right'  => false,
    'aerial' => false,
    'bottom' => false,
];

$slotLabelKeys = [
    'front'  => 'img_front_label',
    'back'   => 'img_back_label',
    'left'   => 'img_left_label',
    'right'  => 'img_right_label',
    'aerial' => 'img_aerial_label',
    'bottom' => 'img_bottom_label',
];

$allowedMimes  = ALLOWED_IMAGE_MIMES;
$maxFileBytes  = 5 * 1024 * 1024; // 5 MB
$uploadBaseDir = appStorageDir('products');

// ── State ─────────────────────────────────────────────────────
$errors    = [];
$flash     = '';

$fv = [
    'supplier_product_code' => '',
    'admin_product_code'    => '',
    'product_name'          => '',
    'technical_description' => '',
    'price_fob'             => '',
    'price_cif'             => '',
];
$keywordsInput = [];   // array of validated keywords from current POST
$keywordsError = '';   // single error string for keywords section

// ── POST dispatcher ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    if (trim($_POST['action'] ?? '') === 'save_product') {

        $s = fn(string $k, int $max = 255): string =>
            mb_substr(trim($_POST[$k] ?? ''), 0, $max);

        $supplierId = (int) $_SESSION['user_id'];

        $fv['supplier_product_code'] = Input::postString('supplier_product_code', Validator::maxLen('product_code'));
        $fv['admin_product_code']    = Input::postString('admin_product_code',    Validator::maxLen('product_code'));
        $fv['product_name']          = Input::postString('product_name',          Validator::maxLen('product_name'));
        $fv['technical_description'] = Input::postText('technical_description',   Validator::maxLen('technical_description'));
        $fv['price_fob']             = Input::postString('price_fob', 30);
        $fv['price_cif']             = Input::postString('price_cif', 30);

        // ── Text field validations ────────────────────────────

        if ($fv['supplier_product_code'] === '') {
            $errors['supplier_product_code'] = t('err_supplier_code_required');
        }
        if ($fv['product_name'] === '') {
            $errors['product_name'] = t('err_product_name_required');
        }

        $priceFobClean = null;
        if ($fv['price_fob'] !== '') {
            $c = str_replace([',',' '], ['.',''], $fv['price_fob']);
            $priceFobClean = Input::toDecimal($c, 0, Validator::PRICE_MAX);
            if ($priceFobClean === null) {
                $errors['price_fob'] = t('err_price_fob_numeric');
            }
        }

        $priceCifClean = null;
        if ($fv['price_cif'] !== '') {
            $c = str_replace([',',' '], ['.',''], $fv['price_cif']);
            $priceCifClean = Input::toDecimal($c, 0, Validator::PRICE_MAX);
            if ($priceCifClean === null) {
                $errors['price_cif'] = t('err_price_cif_numeric');
            }
        }

        if ($fv['technical_description'] !== '') {
            if (preg_match('/https?:\/\/|www\./i', $fv['technical_description'])) {
                $errors['technical_description'] = t('err_desc_no_links');
            }
        }

        // Uniqueness: supplier code per supplier
        if ($fv['supplier_product_code'] !== '' && !isset($errors['supplier_product_code'])) {
            $dup = $pdo->prepare(
                'SELECT id FROM supplier_products
                  WHERE supplier_id = ? AND supplier_product_code = ? LIMIT 1'
            );
            $dup->execute([$supplierId, $fv['supplier_product_code']]);
            if ($dup->fetch()) {
                $errors['supplier_product_code'] = t('err_supplier_code_duplicate');
            }
        }

        $adminCodeClean = null;
        if ($isAdmin && $fv['admin_product_code'] !== '') {
            $adup = $pdo->prepare(
                'SELECT id FROM supplier_products WHERE admin_product_code = ? LIMIT 1'
            );
            $adup->execute([$fv['admin_product_code']]);
            if ($adup->fetch()) {
                $errors['admin_product_code'] = t('err_admin_code_duplicate');
            } else {
                $adminCodeClean = $fv['admin_product_code'];
            }
        }

        // ── Keyword validation ────────────────────────────────
        $rawKeywordsJson = trim($_POST['keywords_json'] ?? '');
        $parsedKeywords  = [];
        if ($rawKeywordsJson !== '' && $rawKeywordsJson !== '[]') {
            $decoded = json_decode($rawKeywordsJson, true);
            if (!is_array($decoded)) {
                $errors['keywords'] = t('err_keyword_empty');
            } else {
                $seen = [];
                foreach ($decoded as $kw) {
                    $kw = mb_strtolower(trim((string) $kw));
                    if ($kw === '') continue;
                    if (mb_strlen($kw) > Validator::maxLen('keyword')) {
                        $errors['keywords'] = t('err_keyword_too_long');
                        break;
                    }
                    if (preg_match('/\s/', $kw)) {
                        $errors['keywords'] = t('err_keyword_spaces');
                        break;
                    }
                    if (!preg_match('/^[\p{L}\p{N}\-_]+$/u', $kw)) {
                        $errors['keywords'] = t('err_keyword_invalid_chars');
                        break;
                    }
                    if (in_array($kw, $seen, true)) {
                        $errors['keywords'] = t('err_keyword_duplicate');
                        break;
                    }
                    $seen[]           = $kw;
                    $parsedKeywords[] = $kw;
                }
                if (!isset($errors['keywords'])) {
                    $keywordsInput = $parsedKeywords;
                }
            }
        }

        // ── Image validations ─────────────────────────────────
        $hasAnyUpload = false;
        foreach ($uploadSlots as $slot => $required) {
            $key  = 'img_' . $slot;
            $file = $_FILES[$key] ?? ['error' => UPLOAD_ERR_NO_FILE];

            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $errors[$key] = t('err_img_front_required');
                }
                continue;
            }

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[$key] = t('err_img_upload_failed');
                continue;
            }

            if ($file['size'] > $maxFileBytes) {
                $errors[$key] = t('err_img_size');
                continue;
            }

            // SVG + type check using shared validator (SVG is explicitly blocked)
            $typeCheck = validateUploadedImage($file, $maxFileBytes);
            if (!$typeCheck['ok'] && $typeCheck['error'] !== 'err_img_size') {
                $errors[$key] = t($typeCheck['error']);
            } else {
                $hasAnyUpload = true;
            }
        }

        // ── Save if valid ─────────────────────────────────────
        if (empty($errors)) {
            // TODO: connect audit_log module when implemented
            $productDir = '';
            $productId  = 0;

            try {
                $pdo->beginTransaction();

                // Generate a unique internal product code before inserting.
                $internalCode = generateInternalProductCode($pdo);

                $orgId = (int) ($_SESSION['org_id'] ?? 0);

                $pdo->prepare(
                    'INSERT INTO supplier_products
                        (supplier_id, org_id, supplier_product_code, admin_product_code,
                         internal_product_code,
                         product_name, technical_description,
                         price_fob, price_cif, active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
                )->execute([
                    $supplierId,
                    $orgId,
                    $fv['supplier_product_code'],
                    $adminCodeClean,
                    $internalCode,
                    $fv['product_name'],
                    $fv['technical_description'] !== '' ? $fv['technical_description'] : null,
                    $priceFobClean,
                    $priceCifClean,
                    $supplierId,
                ]);
                $productId = (int) $pdo->lastInsertId();

                // Create product-specific upload directory
                $productDir = $uploadBaseDir . DIRECTORY_SEPARATOR . $productId;
                if (!is_dir($productDir)) {
                    mkdir($productDir, 0755, true);
                }

                // Move and record each uploaded image
                foreach ($uploadSlots as $slot => $required) {
                    $key  = 'img_' . $slot;
                    $file = $_FILES[$key] ?? ['error' => UPLOAD_ERR_NO_FILE];

                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $result   = validateUploadedImage($file, $maxFileBytes);
                    $ext      = $result['ok'] ? $result['ext'] : 'jpg';
                    $mime     = $result['ok'] ? $result['mime'] : 'image/jpeg';
                    $safeName = $slot . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $dest     = $productDir . DIRECTORY_SEPARATOR . $safeName;

                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        throw new RuntimeException('move_uploaded_file failed for slot: ' . $slot);
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

                // Persist keywords
                if (!empty($parsedKeywords)) {
                    $kwStmt = $pdo->prepare(
                        'INSERT IGNORE INTO product_keywords (product_id, keyword)
                         VALUES (?, ?)'
                    );
                    foreach ($parsedKeywords as $kw) {
                        $kwStmt->execute([$productId, $kw]);
                    }
                }

                $pdo->commit();
                header('Location: /login/supplier/products.php?saved=1');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                // Cleanup any files that were moved before the error
                if ($productId > 0 && is_dir($productDir)) {
                    foreach (glob($productDir . DIRECTORY_SEPARATOR . '*') as $f) {
                        @unlink($f);
                    }
                    @rmdir($productDir);
                }
                $errors['_general'] = t('err_product_save_failed');
                error_log('Product save error: ' . $e->getMessage());
            }
        }
    }
}

// ── View helpers ──────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$val      = fn(string $k): string => htmlspecialchars((string)($fv[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$clsInput = fn(string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$errMsg   = fn(string $key): string => isset($errors[$key])
    ? '<span class="field-error">' . htmlspecialchars($errors[$key], ENT_QUOTES, 'UTF-8') . '</span>'
    : '';

$username  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial   = strtoupper(substr((string)($_SESSION['username'] ?? '?'), 0, 1));
$csrfField = csrfField();
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

// Build slot configs for view
$slots = [
    'front'  => ['label_key' => 'img_front_label',  'required' => true],
    'back'   => ['label_key' => 'img_back_label',   'required' => false],
    'left'   => ['label_key' => 'img_left_label',   'required' => false],
    'right'  => ['label_key' => 'img_right_label',  'required' => false],
    'aerial' => ['label_key' => 'img_aerial_label', 'required' => false],
    'bottom' => ['label_key' => 'img_bottom_label', 'required' => false],
];

// Helper: render one upload slot
function renderUploadSlot(string $slot, array $cfg, callable $esc, callable $errMsg): string {
    $key       = 'img_' . $slot;
    $label     = $esc(t($cfg['label_key']));
    $required  = $cfg['required'];
    $errHtml   = $errMsg($key);
    $isInvalid = $errHtml !== '' ? ' is-invalid' : '';
    $reqBadge  = $required
        ? '<span class="img-slot-required">*</span>'
        : '<span class="img-slot-optional">(' . $esc(t('product_view_optional')) . ')</span>';
    $accept    = 'image/*';
    $clickTxt  = $esc(t('img_click_to_upload'));
    $typeTxt   = $esc(t('img_allowed_types'));
    $removeTxt = $esc(t('img_remove'));

    // Camera icon SVG
    $camIcon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
             . '<path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"'
             . ' stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
             . '<circle cx="12" cy="13" r="4" stroke="currentColor" stroke-width="1.5"/>'
             . '</svg>';

    return <<<HTML
<div class="img-upload-slot" id="slot-{$slot}">
    <input type="file"
           id="img_{$slot}"
           name="img_{$slot}"
           accept="{$accept}"
           class="img-upload-input"
           data-slot="{$slot}">
    <div class="img-upload-area{$isInvalid}"
         id="area-{$slot}"
         onclick="triggerUpload('{$slot}')"
         role="button"
         tabindex="0"
         aria-label="{$label}"
         onkeydown="if(event.key==='Enter'||event.key===' ')triggerUpload('{$slot}')">
        <div class="img-upload-preview-wrap" id="preview-{$slot}" style="display:none">
            <img src="" alt="" class="img-upload-preview-img" id="previewImg-{$slot}">
        </div>
        <div class="img-upload-placeholder" id="placeholder-{$slot}">
            {$camIcon}
            <span>{$clickTxt}</span>
            <span class="img-upload-type-hint">{$typeTxt}</span>
        </div>
    </div>
    <div class="img-slot-meta">
        <span class="img-slot-label">{$label}{$reqBadge}</span>
        <button type="button"
                class="btn-img-remove"
                id="remove-{$slot}"
                onclick="removeImage('{$slot}')"
                aria-label="{$removeTxt}">{$removeTxt}</button>
    </div>
    {$errHtml}
</div>
HTML;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('add_product_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

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

    <?= renderTabs('add_product') ?>

    <div class="page-content">

        <!-- ════════════════════ PRODUCT FORM ════════════════════ -->
        <div class="card profile-form-card">

            <h1 class="card-title"><?= t('add_product_title') ?></h1>
            <p class="card-subtitle" style="margin-bottom:28px;"><?= t('add_product_subtitle') ?></p>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error" role="alert" style="margin-bottom:24px;">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                    <line x1="8" y1="4.75" x2="8" y2="8.75" stroke="#ff3b30"
                          stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
                </svg>
                <span><?= t('profile_error_fields') ?></span>
            </div>
            <?php endif; ?>
            <?php if (isset($errors['_general'])): ?>
            <div class="alert alert-error" role="alert" style="margin-bottom:16px;">
                <span><?= $esc($errors['_general']) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST"
                  action="/login/supplier/add_product.php"
                  enctype="multipart/form-data"
                  novalidate>
                <?= $csrfField ?>
                <input type="hidden" name="action" value="save_product">

                <!-- ══ Sección 1: Información ═════════════════════ -->
                <div class="form-section">
                    <h2 class="form-section-title">
                        <span class="section-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <rect x="2" y="2" width="12" height="12" rx="2"
                                      stroke="currentColor" stroke-width="1.5"/>
                                <path d="M5 8h6M5 5.5h6M5 10.5h4"
                                      stroke="currentColor" stroke-width="1.5"
                                      stroke-linecap="round"/>
                            </svg>
                        </span>
                        <?= t('section_product_info') ?>
                    </h2>

                    <!-- Código proveedor -->
                    <div class="input-wrap" style="margin-bottom:16px;">
                        <label for="supplier_product_code"><?= $esc(t('field_supplier_code')) ?> *</label>
                        <input type="text" id="supplier_product_code" name="supplier_product_code"
                               value="<?= $val('supplier_product_code') ?>"
                               placeholder="<?= $esc(t('field_supplier_code_ph')) ?>"
                               class="<?= ltrim($clsInput('supplier_product_code')) ?>"
                               maxlength="100" required autofocus>
                        <span class="input-help"><?= $esc(t('field_supplier_code_help')) ?></span>
                        <?= $errMsg('supplier_product_code') ?>
                    </div>

                    <?php if ($isAdmin): ?>
                    <!-- Código admin — solo visible para rol admin -->
                    <div class="input-wrap" style="margin-bottom:16px;">
                        <label for="admin_product_code"><?= $esc(t('field_admin_code')) ?></label>
                        <input type="text" id="admin_product_code" name="admin_product_code"
                               value="<?= $val('admin_product_code') ?>"
                               placeholder="<?= $esc(t('field_admin_code_ph')) ?>"
                               class="<?= ltrim($clsInput('admin_product_code')) ?>"
                               maxlength="100">
                        <span class="input-help"><?= $esc(t('field_admin_code_help')) ?></span>
                        <?= $errMsg('admin_product_code') ?>
                    </div>
                    <?php endif; ?>

                    <!-- Nombre -->
                    <div class="input-wrap" style="margin-bottom:16px;">
                        <label for="product_name"><?= $esc(t('field_product_name')) ?> *</label>
                        <input type="text" id="product_name" name="product_name"
                               value="<?= $val('product_name') ?>"
                               placeholder="<?= $esc(t('field_product_name_ph')) ?>"
                               class="<?= ltrim($clsInput('product_name')) ?>"
                               maxlength="300" required>
                        <?= $errMsg('product_name') ?>
                    </div>

                    <!-- Descripción / Ficha Técnica -->
                    <div class="input-wrap">
                        <label for="technical_description"><?= $esc(t('field_tech_desc')) ?></label>
                        <textarea id="technical_description" name="technical_description"
                                  class="form-textarea<?= $clsInput('technical_description') ?>"
                                  placeholder="<?= $esc(t('field_tech_desc_ph')) ?>"
                                  rows="7" maxlength="10000"><?= $val('technical_description') ?></textarea>
                        <span class="input-help"><?= $esc(t('field_tech_desc_help')) ?></span>
                        <?= $errMsg('technical_description') ?>
                    </div>
                </div>

                <!-- ══ Sección 2: Precios ════════════════════════════ -->
                <div class="form-section">
                    <h2 class="form-section-title">
                        <span class="section-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 4.5v7M6 6.5c0-1.1.9-2 2-2s2 .9 2 2-1.33 2-2 2-2 .9-2 2 .9 2 2 2 2-.9 2-2"
                                      stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <?= t('section_product_pricing') ?>
                    </h2>
                    <div class="form-row">
                        <div class="input-wrap">
                            <label for="price_fob"><?= $esc(t('field_price_fob')) ?></label>
                            <input type="text" id="price_fob" name="price_fob"
                                   value="<?= $val('price_fob') ?>"
                                   placeholder="<?= $esc(t('field_price_fob_ph')) ?>"
                                   class="<?= ltrim($clsInput('price_fob')) ?>"
                                   maxlength="30" inputmode="decimal">
                            <?= $errMsg('price_fob') ?>
                        </div>
                        <div class="input-wrap">
                            <label for="price_cif"><?= $esc(t('field_price_cif')) ?></label>
                            <input type="text" id="price_cif" name="price_cif"
                                   value="<?= $val('price_cif') ?>"
                                   placeholder="<?= $esc(t('field_price_cif_ph')) ?>"
                                   class="<?= ltrim($clsInput('price_cif')) ?>"
                                   maxlength="30" inputmode="decimal">
                            <?= $errMsg('price_cif') ?>
                        </div>
                    </div>
                </div>

                <!-- ══ Sección 3: Imágenes ════════════════════════════ -->
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
                        <?= t('section_product_images') ?>
                    </h2>

                    <!-- Vista frontal — requerida (ancho completo) -->
                    <div class="img-grid-main">
                        <?= renderUploadSlot('front', $slots['front'], $esc, $errMsg) ?>
                    </div>

                    <!-- 5 vistas opcionales -->
                    <div class="img-grid-lateral img-grid-lateral--5col">
                        <?= renderUploadSlot('back',   $slots['back'],   $esc, $errMsg) ?>
                        <?= renderUploadSlot('left',   $slots['left'],   $esc, $errMsg) ?>
                        <?= renderUploadSlot('right',  $slots['right'],  $esc, $errMsg) ?>
                        <?= renderUploadSlot('aerial', $slots['aerial'], $esc, $errMsg) ?>
                        <?= renderUploadSlot('bottom', $slots['bottom'], $esc, $errMsg) ?>
                    </div>

                </div>

                <!-- ══ Sección 4: Palabras clave ════════════════════ -->
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
                        <?= t('section_product_keywords') ?>
                    </h2>
                    <p class="input-help" style="margin-bottom:14px;">
                        <?= $esc(t('keywords_subtitle')) ?>
                    </p>

                    <?php if (!empty($errors['keywords'])): ?>
                    <div class="alert alert-error" role="alert" style="margin-bottom:12px;">
                        <span><?= $esc($errors['keywords']) ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="keyword-input-wrap">
                        <div class="keyword-tags" id="keyword-tags" role="list"
                             aria-label="<?= $esc(t('section_product_keywords')) ?>">
                        </div>
                        <div class="keyword-add-row">
                            <input type="text"
                                   id="keyword-input"
                                   class="keyword-text-input"
                                   placeholder="<?= $esc(t('keywords_input_ph')) ?>"
                                   maxlength="60"
                                   autocomplete="off"
                                   aria-label="<?= $esc(t('keywords_input_ph')) ?>">
                            <button type="button"
                                    id="keyword-add-btn"
                                    class="btn-secondary btn-sm">
                                <?= $esc(t('btn_add_keyword')) ?>
                            </button>
                        </div>
                        <div id="keyword-client-error" class="field-error"
                             style="display:none;margin-top:6px;"></div>
                    </div>

                    <input type="hidden"
                           id="keywords_json"
                           name="keywords_json"
                           value="<?= $esc(json_encode($keywordsInput)) ?>">
                </div>

                <!-- ══ Botones ════════════════════════════════════════ -->
                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <?= $esc(t('btn_save_product')) ?>
                    </button>
                    <a href="/login/supplier/summary.php" class="btn-secondary">
                        <?= $esc(t('btn_cancel_product')) ?>
                    </a>
                </div>

            </form>
        </div>
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

        // ── Image upload handling ─────────────────────────────
        var MAX_BYTES = <?= $maxFileBytes ?>;

        window.triggerUpload = function(slot) {
            document.getElementById('img_' + slot).click();
        };

        window.removeImage = function(slot) {
            var input   = document.getElementById('img_' + slot);
            var preview = document.getElementById('preview-' + slot);
            var ph      = document.getElementById('placeholder-' + slot);
            var btn     = document.getElementById('remove-' + slot);
            var area    = document.getElementById('area-' + slot);

            // Reset the file input by replacing it
            var newInput = input.cloneNode(true);
            newInput.value = '';
            input.parentNode.replaceChild(newInput, input);
            newInput.addEventListener('change', function(){ handleFileChange(slot); });

            preview.style.display   = 'none';
            ph.style.display        = '';
            btn.style.display       = 'none';
            area.classList.remove('is-invalid');
        };

        function handleFileChange(slot) {
            var input   = document.getElementById('img_' + slot);
            var preview = document.getElementById('preview-' + slot);
            var previewImg = document.getElementById('previewImg-' + slot);
            var ph      = document.getElementById('placeholder-' + slot);
            var btn     = document.getElementById('remove-' + slot);
            var area    = document.getElementById('area-' + slot);

            if (!input || !input.files || !input.files[0]) return;

            var file = input.files[0];
            var allowed = ['image/jpeg','image/png','image/webp','image/gif','image/bmp','image/avif'];
            var svgTypes = ['image/svg+xml','image/svg'];

            area.classList.remove('is-invalid');

            // Block SVG explicitly on the client side too
            if (svgTypes.indexOf(file.type) !== -1) {
                area.classList.add('is-invalid');
                window.removeImage(slot);
                return;
            }
            if (allowed.indexOf(file.type) === -1) {
                area.classList.add('is-invalid');
                window.removeImage(slot);
                return;
            }
            if (file.size > MAX_BYTES) {
                area.classList.add('is-invalid');
                window.removeImage(slot);
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.style.display = '';
                ph.style.display      = 'none';
                btn.style.display     = '';
            };
            reader.readAsDataURL(file);
        }

        // Wire change events for all slots
        ['front','back','left','right','aerial','bottom'].forEach(function(slot){
            var input = document.getElementById('img_' + slot);
            if (input) {
                input.addEventListener('change', function(){ handleFileChange(slot); });
            }
        });

        // ── Frontend form validation ──────────────────────────
        var form = document.querySelector('form[action*="add_product"]');
        if (form) {
            form.addEventListener('submit', function(e) {
                var valid = true;

                ['supplier_product_code','product_name'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el && !el.value.trim()) {
                        el.classList.add('is-invalid');
                        valid = false;
                    }
                });

                // Check front view image (required)
                var frontInput = document.getElementById('img_front');
                var frontArea  = document.getElementById('area-front');
                if (frontInput && !frontInput.files.length) {
                    if (frontArea) frontArea.classList.add('is-invalid');
                    valid = false;
                }

                if (!valid) e.preventDefault();
            });
        }

        // ── Keywords widget ───────────────────────────────────
        var keywordsJson  = document.getElementById('keywords_json');
        var keywordInput  = document.getElementById('keyword-input');
        var keywordAddBtn = document.getElementById('keyword-add-btn');
        var keywordTags   = document.getElementById('keyword-tags');
        var keywordErrEl  = document.getElementById('keyword-client-error');

        var currentKeywords = [];

        // Re-hydrate from hidden field (server-side validation failure)
        try {
            var stored = JSON.parse(keywordsJson ? (keywordsJson.value || '[]') : '[]');
            if (Array.isArray(stored)) {
                stored.forEach(function(k) { if (k) addKeywordTag(k, true); });
            }
        } catch(ex) {}

        function showKwError(msg) {
            if (keywordErrEl) { keywordErrEl.textContent = msg; keywordErrEl.style.display = 'block'; }
        }
        function clearKwError() {
            if (keywordErrEl) { keywordErrEl.style.display = 'none'; keywordErrEl.textContent = ''; }
        }
        function saveKeywords() {
            if (keywordsJson) keywordsJson.value = JSON.stringify(currentKeywords);
        }

        function addKeywordTag(kw, skipDupeCheck) {
            kw = kw.trim().toLowerCase();
            if (!skipDupeCheck && currentKeywords.indexOf(kw) !== -1) return false;
            if (currentKeywords.indexOf(kw) === -1) currentKeywords.push(kw);

            var chip = document.createElement('span');
            chip.className = 'keyword-chip';
            chip.setAttribute('role', 'listitem');
            chip.dataset.kw = kw;

            var text = document.createTextNode(kw + '\u00a0');
            chip.appendChild(text);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'keyword-chip-remove';
            removeBtn.setAttribute('aria-label', 'Eliminar ' + kw);
            removeBtn.textContent = '\u00d7';
            removeBtn.addEventListener('click', function() {
                var idx = currentKeywords.indexOf(kw);
                if (idx > -1) currentKeywords.splice(idx, 1);
                if (keywordTags && chip.parentNode === keywordTags) keywordTags.removeChild(chip);
                saveKeywords();
            });
            chip.appendChild(removeBtn);
            if (keywordTags) keywordTags.appendChild(chip);
            saveKeywords();
            return true;
        }

        function validateAndAddKeyword() {
            clearKwError();
            var kw = keywordInput ? (keywordInput.value || '').trim().toLowerCase() : '';

            if (!kw) { showKwError('<?= addslashes(t('err_keyword_empty')) ?>'); return; }
            if (/\s/.test(kw)) { showKwError('<?= addslashes(t('err_keyword_spaces')) ?>'); return; }
            if (kw.length > 60) { showKwError('<?= addslashes(t('err_keyword_too_long')) ?>'); return; }
            if (!/^[\w\-]+$/i.test(kw)) { showKwError('<?= addslashes(t('err_keyword_invalid_chars')) ?>'); return; }
            if (currentKeywords.indexOf(kw) !== -1) { showKwError('<?= addslashes(t('err_keyword_duplicate')) ?>'); return; }

            addKeywordTag(kw, false);
            if (keywordInput) { keywordInput.value = ''; keywordInput.focus(); }
        }

        if (keywordAddBtn) keywordAddBtn.addEventListener('click', validateAndAddKeyword);
        if (keywordInput) {
            keywordInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); validateAndAddKeyword(); }
            });
        }
    }());
    </script>

</body>
</html>

