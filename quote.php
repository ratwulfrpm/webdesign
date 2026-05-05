<?php
/**
 * /login/quote.php — Public product quotation view (no authentication required)
 *
 * URL : /login/quote.php?t={plain_64hex_token}
 *
 * Supports two formats:
 *  1. NEW    — token matches quote_assignments (multi-product master-detail)
 *  2. LEGACY — token matches product_assignments (single-product, backward compat)
 *
 * Security design:
 *  - No user session or authentication required.
 *  - Receives a plain token via GET ?t=
 *  - Hashes the token server-side (SHA-256) and looks up the DB.
 *  - NEVER accepts product_id or assignment_id from the URL (anti-IDOR).
 *  - On any invalid/expired/revoked/missing token: shows a generic error message.
 *    No information is revealed about whether the token or product exists.
 *  - FOB/CIF raw prices and all internal admin data are never shown.
 *  - supplier_product_code never exposed.
 *  - All output is escaped (XSS prevention).
 *  - No SQL concatenation — all queries use prepared statements.
 *
 * Token flow:
 *  plain_token (URL)  →  hash('sha256', plain_token)  →  lookup in DB
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/session.php';   // centralized session bootstrap
require_once __DIR__ . '/includes/storage.php';   // Storage::imageUrl() for secure image serving

// ── PUBLIC TOKEN CONTEXT ISOLATION ───────────────────────────
// This page operates in pure token-only mode.
// The PHP session is opened ONLY to read/persist the visitor's language
// preference.  After initLang() writes any pending lang change, we call
// session_write_close() to release the session file immediately.
//
// Security contract:
//  • Any authenticated admin/owner/support/supplier session that exists in
//    the same browser is LEFT COMPLETELY INTACT on the server. We never
//    modify or destroy the underlying session data.
//  • Auth context keys (logged_in, user_id, role, org_id, etc.) are NEVER
//    read by this page — the token in ?t= is the sole access credential.
//  • After write_close(), $_SESSION becomes a local-only snapshot for this
//    request; no subsequent changes can persist, preventing accidental bleed.
//  • No private navigation, FOB/CIF prices, internal codes, or supplier IDs
//    are rendered regardless of whether an authenticated session exists.
//
$lang = 'en';
initLang();                // handle ?set_lang= redirect; normalise $_SESSION['lang']
$lang = currentLang();
session_write_close();     // release session lock — auth context preserved on server
// ─────────────────────────────────────────────────────────────

// ── Generic error page ────────────────────────────────────────
function showExpiredPage(string $lang): void
{
    $msgs = [
        'en' => ['title'   => 'Link unavailable',
                 'heading' => 'This link is not available or has expired.',
                 'detail'  => 'The quotation you requested is no longer accessible. Please contact the person who sent you this link.'],
        'es' => ['title'   => 'Enlace no disponible',
                 'heading' => 'Este enlace no está disponible o ha expirado.',
                 'detail'  => 'La cotización que solicitó ya no es accesible. Contacte a la persona que le envió este enlace.'],
        'zh' => ['title'   => '链接不可用',
                 'heading' => '此链接不可用或已过期。',
                 'detail'  => '您请求的报价已无法访问。请联系向您发送此链接的人。'],
    ];
    $m   = $msgs[$lang] ?? $msgs['en'];
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="' . $esc($lang) . '"><head>'
        . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $esc($m['title']) . '</title>'
        . '<link rel="stylesheet" href="/login/css/style.css?v=15">'
        . '</head><body class="role-user">'
        . '<div class="page-content" style="max-width:520px;margin:80px auto;text-align:center;">'
        . '<div class="card" style="padding:40px 32px;">'
        . '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" style="margin-bottom:16px;" aria-hidden="true">'
        . '<circle cx="12" cy="12" r="10" stroke="#d1d1d6" stroke-width="1.5"/>'
        . '<path d="M12 8v5M12 16h.01" stroke="#8e8e93" stroke-width="1.5" stroke-linecap="round"/>'
        . '</svg>'
        . '<h1 style="font-size:1.2rem;margin-bottom:12px;">' . $esc($m['heading']) . '</h1>'
        . '<p style="color:#666;font-size:0.9rem;">' . $esc($m['detail']) . '</p>'
        . '</div></div></body></html>';
    exit;
}

// ── Token extraction + rate limit ─────────────────────────────
$rawToken = trim($_GET['t'] ?? '');
$_auditIp = _auditClientIp();

if (auditIsRateLimited('quote_invalid_token', $_auditIp, 20, 600)) {
    auditLog('quote_rate_limited', 'warning', null, null, ['ip' => $_auditIp]);
    http_response_code(429);
    showExpiredPage($lang);
}

if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
    auditLog('quote_invalid_token', 'warning', null, null, ['reason' => 'bad_format']);
    showExpiredPage($lang);
}

$tokenHash = hash('sha256', $rawToken);
$pdo       = getDB();

// ═════════════════════════════════════════════════════════════
//  STEP 1 — Try NEW format (quote_assignments + items)
// ═════════════════════════════════════════════════════════════
$isNewFormat = false;
$quoteData   = null;
$quoteItems  = [];

$stmtNew = $pdo->prepare(
    'SELECT id, assigned_customer_name, company_name, special_conditions,
            discount_percentage, status, valid_from, expires_at, view_count,
            transport_calculation_type, transport_percentage, transport_fixed_amount,
            tax_calculation_type, tax_percentage, tax_fixed_amount
       FROM quote_assignments
      WHERE token_hash = ?
      LIMIT 1'
);
$stmtNew->execute([$tokenHash]);
$quoteData = $stmtNew->fetch();

if ($quoteData) {
    $isNewFormat = true;

    if ($quoteData['status'] !== 'active') {
        auditLog('quote_not_active', 'warning', (int)$quoteData['id'], null,
            ['status' => $quoteData['status'], 'format' => 'new']);
        showExpiredPage($lang);
    }

    if (strtotime($quoteData['expires_at']) < time()) {
        try {
            $pdo->prepare(
                "UPDATE quote_assignments SET status='expired', updated_at=NOW()
                  WHERE id = ? AND status = 'active'"
            )->execute([$quoteData['id']]);
        } catch (\PDOException $e) {
            error_log('quote.php new expire update failed: ' . $e->getMessage());
        }
        auditLog('quote_expired', 'info', (int)$quoteData['id']);
        showExpiredPage($lang);
    }

    // Load items (never expose supplier_product_code)
    $itemStmt = $pdo->prepare(
        'SELECT qi.final_unit_price,
                p.product_name,
                p.technical_description,
                p.active AS product_active,
                p.id     AS product_id
           FROM quote_assignment_items qi
           JOIN supplier_products p ON p.id = qi.product_id
          WHERE qi.quote_assignment_id = ?
          ORDER BY qi.id ASC'
    );
    $itemStmt->execute([$quoteData['id']]);
    $quoteItems = $itemStmt->fetchAll();

    if (empty($quoteItems)) {
        auditLog('quote_invalid_token', 'warning', (int)$quoteData['id'], null,
            ['reason' => 'no_items']);
        showExpiredPage($lang);
    }

    foreach ($quoteItems as $item) {
        if (!(int)$item['product_active']) {
            auditLog('quote_product_inactive', 'warning', (int)$quoteData['id']);
            showExpiredPage($lang);
        }
    }

    // Load images per product
    $productIds   = array_column($quoteItems, 'product_id');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $allImagesStmt = $pdo->prepare(
        "SELECT product_id, image_slot, file_path
           FROM supplier_product_images
          WHERE product_id IN ({$placeholders})
          ORDER BY FIELD(image_slot,'front','back','left','right','aerial','bottom')"
    );
    $allImagesStmt->execute($productIds);
    $allImages = [];
    foreach ($allImagesStmt->fetchAll() as $img) {
        $allImages[(int)$img['product_id']][$img['image_slot']] = $img['file_path'];
    }

    // Record access
    try {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE quote_assignments
                SET view_count = view_count + 1,
                    last_viewed_at = ?,
                    viewed_at = COALESCE(viewed_at, ?),
                    updated_at = ?
              WHERE id = ?'
        )->execute([$now, $now, $now, $quoteData['id']]);
        auditLog('quote_accessed', 'info', (int)$quoteData['id'], null,
            ['view_count' => (int)$quoteData['view_count'] + 1, 'format' => 'new']);
    } catch (\PDOException $e) {
        error_log('quote.php new view_count update failed: ' . $e->getMessage());
    }
}

// ═════════════════════════════════════════════════════════════
//  STEP 2 — Try LEGACY format (product_assignments)
// ═════════════════════════════════════════════════════════════
$legacyAssignment = null;
$legacyImages     = [];

if (!$isNewFormat) {
    $stmtLeg = $pdo->prepare(
        'SELECT a.id,
                a.product_id,
                a.assigned_customer_name,
                a.status,
                a.final_price,
                a.valid_from,
                a.expires_at,
                a.view_count,
                p.product_name,
                p.technical_description,
                p.active AS product_active
           FROM product_assignments a
           JOIN supplier_products p ON p.id = a.product_id
          WHERE a.token_hash = ?
          LIMIT 1'
    );
    $stmtLeg->execute([$tokenHash]);
    $legacyAssignment = $stmtLeg->fetch();

    if (!$legacyAssignment) {
        auditLog('quote_invalid_token', 'warning', null, null, ['reason' => 'not_found']);
        showExpiredPage($lang);
    }

    if ($legacyAssignment['status'] !== 'active') {
        auditLog('quote_not_active', 'warning', (int)$legacyAssignment['id'], null,
            ['status' => $legacyAssignment['status']]);
        showExpiredPage($lang);
    }

    if (strtotime($legacyAssignment['expires_at']) < time()) {
        try {
            $pdo->prepare(
                "UPDATE product_assignments SET status='expired', updated_at=NOW()
                  WHERE id = ? AND status = 'active'"
            )->execute([$legacyAssignment['id']]);
        } catch (\PDOException $e) {
            error_log('quote.php legacy expire update failed: ' . $e->getMessage());
        }
        auditLog('quote_expired', 'info', (int)$legacyAssignment['id']);
        showExpiredPage($lang);
    }

    if (!(int)$legacyAssignment['product_active']) {
        auditLog('quote_product_inactive', 'warning', (int)$legacyAssignment['id']);
        showExpiredPage($lang);
    }

    try {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE product_assignments
                SET view_count = view_count + 1,
                    last_viewed_at = ?,
                    viewed_at = COALESCE(viewed_at, ?),
                    updated_at = ?
              WHERE id = ?'
        )->execute([$now, $now, $now, $legacyAssignment['id']]);
        auditLog('quote_accessed', 'info', (int)$legacyAssignment['id'], null,
            ['view_count' => (int)$legacyAssignment['view_count'] + 1]);
    } catch (\PDOException $e) {
        error_log('quote.php legacy view_count update failed: ' . $e->getMessage());
    }

    $imgStmt = $pdo->prepare(
        'SELECT image_slot, file_path FROM supplier_product_images WHERE product_id = ?'
    );
    $imgStmt->execute([$legacyAssignment['product_id']]);
    foreach ($imgStmt->fetchAll() as $img) {
        $legacyImages[$img['image_slot']] = $img['file_path'];
    }

}

// ── Slot labels ────────────────────────────────────────────────
$slotLabels = [
    'front'  => t('img_slot_front'),
    'back'   => t('img_slot_back'),
    'left'   => t('img_slot_left'),
    'right'  => t('img_slot_right'),
    'aerial' => t('img_slot_aerial'),
    'bottom' => t('img_slot_bottom'),
];

// ── View helpers ──────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmtDate  = fn($v) => date('d/m/Y', strtotime((string)$v));
$fmtPrice = fn($v) => '$ ' . number_format((float) $v, 2);

$metaCompany = '';
$metaConditions = '';
if ($isNewFormat) {
    $metaCompany = (string)($quoteData['company_name'] ?? '');
    $metaConditions = (string)($quoteData['special_conditions'] ?? '');
}

// ── Compute totals (new format) ───────────────────────────────
$subtotal      = 0.0;
$discountPct   = 0.0;
$discountAmt   = 0.0;
$transportAmt  = 0.0;
$taxAmt        = 0.0;
$total         = 0.0;
if ($isNewFormat) {
    foreach ($quoteItems as $item) { $subtotal += (float)$item['final_unit_price']; }
    $discountPct  = $quoteData['discount_percentage'] !== null ? (float)$quoteData['discount_percentage'] : 0.0;
    $discountAmt  = round($subtotal * $discountPct / 100, 2);
    if ($quoteData['transport_calculation_type'] === 'percentage') {
        $transportAmt = round($subtotal * (float)($quoteData['transport_percentage'] ?? 0) / 100, 2);
    } elseif ($quoteData['transport_calculation_type'] === 'fixed_amount') {
        $transportAmt = round((float)($quoteData['transport_fixed_amount'] ?? 0), 2);
    }
    if ($quoteData['tax_calculation_type'] === 'percentage') {
        $taxAmt = round(($subtotal + $transportAmt) * (float)($quoteData['tax_percentage'] ?? 0) / 100, 2);
    } elseif ($quoteData['tax_calculation_type'] === 'fixed_amount') {
        $taxAmt = round((float)($quoteData['tax_fixed_amount'] ?? 0), 2);
    }
    $total = round($subtotal + $transportAmt + $taxAmt - $discountAmt, 2);
}

$expiresAt    = $isNewFormat ? $quoteData['expires_at'] : $legacyAssignment['expires_at'];
$customerName = $isNewFormat ? $quoteData['assigned_customer_name'] : $legacyAssignment['assigned_customer_name'];

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= $esc(t('quote_page_title')) ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
    <style>
        body { background: #f5f5f7; }
        .quote-header {
            background:#fff; border-bottom:1px solid #e5e5ea;
            padding:18px 32px; display:flex; align-items:center;
            justify-content:space-between;
        }
        .quote-brand { font-size:1.05rem; font-weight:600; color:#1d1d1f; }
        .quote-validity { font-size:0.83rem; color:#555; }
        .quote-wrap { max-width:840px; margin:28px auto; padding:0 16px 48px; }
        .quote-card {
            background:#fff; border-radius:14px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07); overflow:hidden;
            margin-bottom:20px;
        }
        .quote-card-header { padding:24px 28px 18px; border-bottom:1px solid #f0f0f3; }
        .quote-product-name { font-size:1.35rem; font-weight:700; color:#1d1d1f; margin:0 0 5px; }
        .quote-product-code { font-size:0.82rem; color:#888; font-family:monospace; }
        .quote-price-box {
            display:flex; align-items:baseline; gap:8px;
            margin:18px 28px; padding:18px 22px;
            background:#f0f7ff; border:1.5px solid #b8d6f5; border-radius:12px;
        }
        .quote-price-label { font-size:0.88rem; color:#555; font-weight:500; }
        .quote-price-value { font-size:1.9rem; font-weight:800; color:#0071e3; }
        .quote-section { padding:18px 28px; border-top:1px solid #f0f0f3; }
        .quote-section-title {
            font-size:0.82rem; font-weight:600; color:#6e6e73;
            text-transform:uppercase; letter-spacing:0.04em; margin:0 0 10px;
        }
        .quote-desc { font-size:0.93rem; color:#333; line-height:1.65; white-space:pre-line; }
        .quote-gallery {
            display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px;
        }
        .quote-gallery-slot {
            border-radius:8px; overflow:hidden; border:1px solid #e5e5ea; cursor:zoom-in;
        }
        .quote-gallery-slot img { width:100%; height:140px; object-fit:cover; display:block; }
        .quote-gallery-caption { padding:5px 7px; font-size:0.75rem; color:#666; text-align:center; background:#fafafa; }
        .quote-front-img {
            width:100%; max-height:320px; object-fit:contain;
            border-radius:8px; border:1px solid #e5e5ea; cursor:zoom-in; display:block; margin-bottom:14px;
        }
        .quote-meta-box {
            margin:0 28px 22px; padding:18px 22px;
            background:#f8fbff; border:1.5px solid #d7e5f5; border-radius:12px;
            display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));
            gap:12px; color:#21384f;
        }
        .quote-meta-box .meta-item {
            background:#fff;
            border:1px solid #e3ecf8;
            border-radius:10px;
            padding:11px 12px;
            font-size:1.02rem;
            font-weight:600;
            color:#16395f;
        }
        .quote-meta-box .meta-item strong {
            display:block;
            color:#4c6682;
            font-size:0.82rem;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:0.05em;
            margin-bottom:5px;
        }
        .quote-footer-note { text-align:center; padding:18px; font-size:0.78rem; color:#aaa; border-top:1px solid #f0f0f3; }
        /* Totals */
        .quote-totals-box {
            background:#fff; border-radius:14px;
            box-shadow:0 2px 12px rgba(0,0,0,0.07); padding:20px 28px;
        }
        .quote-total-row {
            display:flex; justify-content:space-between;
            padding:7px 0; font-size:0.95rem; border-bottom:1px solid #f0f0f3;
        }
        .quote-total-row:last-child { border-bottom:none; }
        .quote-total-row.grand-total {
            font-size:1.15rem; font-weight:800; color:#0071e3;
            border-top:2px solid #b8d6f5; margin-top:6px; padding-top:10px;
        }
        .discount-val { color:#e74c3c; }
        .item-number-badge {
            display:inline-flex; align-items:center; justify-content:center;
            width:24px; height:24px; border-radius:50%; background:#0071e3;
            color:#fff; font-size:0.75rem; font-weight:700; flex-shrink:0; margin-right:8px;
        }
        /* Lightbox */
        .quote-lightbox {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.88); z-index:9999;
            align-items:center; justify-content:center;
        }
        .quote-lightbox.active { display:flex; }
        .quote-lightbox img { max-width:90vw; max-height:88vh; border-radius:6px; }
        .quote-lightbox-close {
            position:absolute; top:18px; right:22px;
            background:none; border:none; color:#fff; font-size:1.8rem; cursor:pointer;
        }
        @media (max-width:600px) {
            .quote-header { padding:13px 16px; }
            .quote-price-box { margin:14px; }
            .quote-section  { padding:14px; }
            .quote-meta-box { margin:0 14px 16px; }
            .quote-meta-box .meta-item { font-size:0.95rem; }
            .quote-product-name { font-size:1.15rem; }
        }
        /* ── Product selection ── */
        .quote-card { transition:opacity 0.2s, box-shadow 0.2s; }
        .quote-card.deselected { opacity:0.45; }
        .product-select-bar {
            display:flex; align-items:center; gap:12px;
            padding:12px 28px 14px; border-top:1px solid #f0f0f3;
            background:#fafafa;
        }
        .product-checkbox-wrap {
            display:flex; align-items:center; gap:8px; cursor:pointer;
            user-select:none; flex:1;
        }
        .product-checkbox-wrap input[type=checkbox] {
            width:20px; height:20px; accent-color:#0071e3; cursor:pointer; flex-shrink:0;
        }
        .product-checkbox-label { font-size:0.88rem; font-weight:500; color:#444; }
        .product-price-tag {
            font-size:0.88rem; font-weight:700; color:#0071e3;
            background:#eaf4ff; padding:4px 12px; border-radius:20px;
        }
        /* ── Live totals ── */
        #liveTotalsBox { transition:background 0.2s; }
        .live-zero { color:#aaa !important; }
        .no-selection-note {
            text-align:center; padding:12px; font-size:0.88rem;
            color:#999; display:none;
        }
    </style>
</head>
<body class="role-user">

    <header class="quote-header">
        <div class="quote-brand"><?= $esc(t('quote_page_title')) ?></div>
        <div class="quote-validity">
            <?= $esc(t('quote_valid_until')) ?>:
            <strong><?= $esc($fmtDate($expiresAt)) ?></strong>
        </div>
    </header>

    <main class="quote-wrap">

        <!-- ── Client / Company / Conditions card ─────────── -->
        <div class="quote-card" style="margin-bottom:20px;">
            <div class="quote-meta-box" style="margin:0 28px 22px;">
                <div class="meta-item">
                    <strong><?= $esc(t('quote_client_label')) ?></strong>
                    <?= $esc($customerName) ?>
                </div>
                <?php if ($metaCompany !== ''): ?>
                <div class="meta-item">
                    <strong><?= $esc(t('quote_company_label')) ?></strong>
                    <?= $esc($metaCompany) ?>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <strong><?= $esc(t('quote_expires_label')) ?></strong>
                    <?= $esc($fmtDate($expiresAt)) ?>
                </div>
            </div>
            <?php if ($metaConditions !== ''): ?>
            <div style="padding:0 28px 18px;">
                <div style="font-size:0.78rem;font-weight:600;color:#6e6e73;text-transform:uppercase;margin-bottom:6px;">
                    <?= $esc(t('quote_conditions_label')) ?>
                </div>
                <div style="font-size:0.9rem;color:#333;line-height:1.6;white-space:pre-line;">
                    <?= nl2br($esc($metaConditions)) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isNewFormat): ?>
        <!-- ══════════════════════════════════════════════════
             NEW FORMAT — Multi-product
        ══════════════════════════════════════════════════ -->
        <?php foreach ($quoteItems as $idx => $item): ?>
        <?php
            $productId = (int)$item['product_id'];
            $images    = $allImages[$productId] ?? [];
            $itemPrice = (float)$item['final_unit_price'];
        ?>
        <div class="quote-card" data-product-id="<?= $productId ?>" data-price="<?= htmlspecialchars((string)$itemPrice) ?>">
            <div class="quote-card-header">
                <h2 class="quote-product-name">
                    <span class="item-number-badge"><?= $idx + 1 ?></span>
                    <?= $esc($item['product_name']) ?>
                </h2>
            </div>
            <div class="quote-price-box">
                <span class="quote-price-label"><?= $esc(t('quote_item_price_label')) ?></span>
                <span class="quote-price-value"><?= $esc($fmtPrice($item['final_unit_price'])) ?></span>
            </div>
            <?php if (!empty($item['technical_description'])): ?>
            <div class="quote-section">
                <div class="quote-section-title"><?= $esc(t('quote_description_label')) ?></div>
                <div class="quote-desc"><?= nl2br($esc($item['technical_description'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($images)): ?>
            <div class="quote-section">
                <div class="quote-section-title"><?= $esc(t('quote_gallery_label')) ?></div>
                <?php if (isset($images['front'])): ?>
                <img src="<?= $esc(Storage::imageUrl($images['front'], $rawToken)) ?>"
                     alt="<?= $esc($slotLabels['front']) ?>"
                     class="quote-front-img"
                     onclick="openLightbox(this.src,this.alt)">
                <?php endif; ?>
                <?php
                $optSlots = ['back','left','right','aerial','bottom']; $hasOpt = false;
                foreach ($optSlots as $s) { if (isset($images[$s])) { $hasOpt = true; break; } }
                ?>
                <?php if ($hasOpt): ?>
                <div class="quote-gallery">
                    <?php foreach ($optSlots as $slot): ?>
                        <?php if (isset($images[$slot])): ?>
                        <div class="quote-gallery-slot"
                             onclick="openLightbox('<?= $esc(Storage::imageUrl($images[$slot], $rawToken)) ?>','<?= $esc($slotLabels[$slot] ?? $slot) ?>')">
                            <img src="<?= $esc(Storage::imageUrl($images[$slot], $rawToken)) ?>"
                                 alt="<?= $esc($slotLabels[$slot] ?? $slot) ?>" loading="lazy">
                            <div class="quote-gallery-caption"><?= $esc($slotLabels[$slot] ?? $slot) ?></div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <!-- ── Product selection checkbox ── -->
            <div class="product-select-bar">
                <label class="product-checkbox-wrap">
                    <input type="checkbox" class="product-cb"
                           data-product-id="<?= $productId ?>"
                           data-price="<?= htmlspecialchars((string)$itemPrice) ?>"
                           checked
                           onchange="recalcTotals()">
                    <span class="product-checkbox-label"><?= $esc(t('quote_include_in_quote')) ?></span>
                </label>
                <span class="product-price-tag"><?= $esc($fmtPrice($item['final_unit_price'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- ── Live totals box ── -->
        <?php
        // Build the JS config for dynamic recalculation
        $jsConfig = [
            'discountPct'   => (float)($quoteData['discount_percentage'] ?? 0),
            'transportType' => $quoteData['transport_calculation_type'] ?? null,
            'transportPct'  => (float)($quoteData['transport_percentage'] ?? 0),
            'transportAmt'  => (float)($quoteData['transport_fixed_amount'] ?? 0),
            'taxType'       => $quoteData['tax_calculation_type'] ?? null,
            'taxPct'        => (float)($quoteData['tax_percentage'] ?? 0),
            'taxAmt'        => (float)($quoteData['tax_fixed_amount'] ?? 0),
        ];
        ?>
        <script>
        var quoteConfig = <?= json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

        function fmtMoney(v) {
            return '$ ' + v.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        }

        function recalcTotals() {
            var checked = document.querySelectorAll('.product-cb:checked');
            var subtotal = 0;
            checked.forEach(function(cb) { subtotal += parseFloat(cb.dataset.price) || 0; });

            // Transport
            var transport = 0;
            if (quoteConfig.transportType === 'percentage') {
                transport = Math.round(subtotal * quoteConfig.transportPct / 100 * 100) / 100;
            } else if (quoteConfig.transportType === 'fixed_amount') {
                transport = quoteConfig.transportAmt;
            }

            // Tax (base = subtotal + transport)
            var tax = 0;
            if (quoteConfig.taxType === 'percentage') {
                tax = Math.round((subtotal + transport) * quoteConfig.taxPct / 100 * 100) / 100;
            } else if (quoteConfig.taxType === 'fixed_amount') {
                tax = quoteConfig.taxAmt;
            }

            // Discount on subtotal
            var discountAmt = Math.round(subtotal * quoteConfig.discountPct / 100 * 100) / 100;
            var total = Math.round((subtotal + transport + tax - discountAmt) * 100) / 100;

            var hasExtras = transport > 0 || tax > 0 || discountAmt > 0;

            function row(id, show, val) {
                var el = document.getElementById(id);
                if (!el) return;
                el.style.display = show ? 'flex' : 'none';
                var sp = el.querySelector('.live-val');
                if (sp) sp.textContent = val;
            }

            row('liveRowSubtotal',  hasExtras, fmtMoney(subtotal));
            row('liveRowTransport', transport > 0, fmtMoney(transport));
            row('liveRowTax',       tax > 0,        fmtMoney(tax));
            row('liveRowDiscount',  discountAmt > 0, '−' + fmtMoney(discountAmt));

            var totalEl = document.getElementById('liveTotal');
            if (totalEl) totalEl.textContent = fmtMoney(total);

            var noneNote = document.getElementById('noSelectionNote');
            if (noneNote) noneNote.style.display = checked.length === 0 ? 'block' : 'none';

            // Dim deselected cards
            document.querySelectorAll('.product-cb').forEach(function(cb) {
                var card = cb.closest('.quote-card');
                if (card) {
                    card.classList.toggle('deselected', !cb.checked);
                }
            });
        }

        // Init on load
        document.addEventListener('DOMContentLoaded', recalcTotals);
        </script>

        <div class="quote-totals-box" id="liveTotalsBox">
            <div class="no-selection-note" id="noSelectionNote"
                 style="display:none;">
                <?= $esc(t('quote_no_items_selected')) ?>
            </div>
            <div class="quote-total-row" id="liveRowSubtotal" style="display:none;">
                <span><?= $esc(t('quote_subtotal_label')) ?></span>
                <span class="live-val"></span>
            </div>
            <div class="quote-total-row" id="liveRowTransport" style="display:none;">
                <span><?= $esc(t('quote_transport_label')) ?></span>
                <span class="live-val"></span>
            </div>
            <div class="quote-total-row" id="liveRowTax" style="display:none;">
                <span><?= $esc(t('quote_tax_label')) ?></span>
                <span class="live-val"></span>
            </div>
            <div class="quote-total-row" id="liveRowDiscount" style="display:none;">
                <span><?= $esc(t('quote_discount_label')) ?> (<?= number_format($discountPct, 1) ?>%)</span>
                <span class="live-val discount-val"></span>
            </div>
            <div class="quote-total-row grand-total">
                <span><?= $esc(t('quote_total_label')) ?></span>
                <span id="liveTotal"><?= $esc($fmtPrice($total)) ?></span>
            </div>
        </div>

        <div style="text-align:center;padding:16px;font-size:0.78rem;color:#aaa;">
            <?= $esc(sprintf(t('quote_footer_note'), $fmtDate($expiresAt))) ?>
        </div>

        <?php else: ?>
        <!-- ══════════════════════════════════════════════════
             LEGACY FORMAT — Single product
        ══════════════════════════════════════════════════ -->
        <div class="quote-card">
            <div class="quote-card-header">
                <h1 class="quote-product-name"><?= $esc($legacyAssignment['product_name']) ?></h1>
            </div>
            <div class="quote-price-box">
                <span class="quote-price-label"><?= $esc(t('quote_price_label')) ?></span>
                <span class="quote-price-value"><?= $esc($fmtPrice($legacyAssignment['final_price'])) ?></span>
            </div>
            <?php if (!empty($legacyAssignment['technical_description'])): ?>
            <div class="quote-section">
                <div class="quote-section-title"><?= $esc(t('quote_description_label')) ?></div>
                <div class="quote-desc"><?= nl2br($esc($legacyAssignment['technical_description'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($legacyImages)): ?>
            <div class="quote-section">
                <div class="quote-section-title"><?= $esc(t('quote_gallery_label')) ?></div>
                <?php if (isset($legacyImages['front'])): ?>
                <img src="<?= $esc(Storage::imageUrl($legacyImages['front'], $rawToken)) ?>"
                     alt="<?= $esc($slotLabels['front']) ?>"
                     class="quote-front-img"
                     onclick="openLightbox(this.src,this.alt)">
                <?php endif; ?>
                <?php
                $optSlots = ['back','left','right','aerial','bottom']; $hasOpt = false;
                foreach ($optSlots as $s) { if (isset($legacyImages[$s])) { $hasOpt = true; break; } }
                ?>
                <?php if ($hasOpt): ?>
                <div class="quote-gallery">
                    <?php foreach ($optSlots as $slot): ?>
                        <?php if (isset($legacyImages[$slot])): ?>
                        <div class="quote-gallery-slot"
                             onclick="openLightbox('<?= $esc(Storage::imageUrl($legacyImages[$slot], $rawToken)) ?>','<?= $esc($slotLabels[$slot] ?? $slot) ?>')">
                            <img src="<?= $esc(Storage::imageUrl($legacyImages[$slot], $rawToken)) ?>"
                                 alt="<?= $esc($slotLabels[$slot] ?? $slot) ?>" loading="lazy">
                            <div class="quote-gallery-caption"><?= $esc($slotLabels[$slot] ?? $slot) ?></div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="quote-footer-note">
                <?= $esc(sprintf(t('quote_footer_note'), $fmtDate($legacyAssignment['expires_at']))) ?>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <div class="quote-lightbox" id="quoteLightbox" role="dialog" aria-modal="true">
        <button class="quote-lightbox-close" onclick="closeLightbox()" aria-label="Close">&times;</button>
        <img id="lightboxImg" src="" alt="">
    </div>

    <script>
    function openLightbox(src, alt) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('lightboxImg').alt = alt || '';
        document.getElementById('quoteLightbox').classList.add('active');
    }
    function closeLightbox() {
        document.getElementById('quoteLightbox').classList.remove('active');
        document.getElementById('lightboxImg').src = '';
    }
    document.getElementById('quoteLightbox').addEventListener('click', function(e) {
        if (e.target === this) closeLightbox();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLightbox();
    });
    </script>

</body>
</html>

