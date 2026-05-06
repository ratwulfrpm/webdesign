<?php
/**
 * /login/admin/product_view.php — Read-only product detail for admin/owner
 *
 * URL param : ?id=<product_id>
 * Access    : role = 'admin' OR role = 'owner'
 * Note      : No edit capability. Admin sees all products from all suppliers.
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
require_once __DIR__ . '/../includes/storage.php';

requireAuth();
initLang();
requireRole(['admin', 'owner']);

$pdo       = getDB();
$lang      = currentLang();
$productId = (int) ($_GET['id'] ?? 0);

// ── Load product (no supplier restriction — admin sees all) ──
$product = null;
if ($productId > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.*,
                u.id           AS supplier_id,
                u.username     AS supplier_username,
                u.full_name    AS supplier_full_name,
                u.company_name AS supplier_company,
                u.email        AS supplier_email,
                u.company_phone_code   AS supplier_phone_code,
                u.company_phone_number AS supplier_phone_number,
                o.id           AS org_id,
                o.name         AS org_name
           FROM supplier_products p
           JOIN users u         ON u.id = p.supplier_id
           LEFT JOIN org_members om  ON om.user_id = u.id AND om.is_active = 1
           LEFT JOIN organizations o ON o.id = om.org_id
          WHERE p.id = ?
          LIMIT 1'
    );
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
}

if (!$product) {
    header('Location: /login/admin/products.php');
    exit;
}

// ── Load images ───────────────────────────────────────────────
$imgStmt = $pdo->prepare(
    'SELECT image_slot, file_path, original_name, file_size
       FROM supplier_product_images
      WHERE product_id = ?'
);
$imgStmt->execute([$productId]);

$images = [];   // keyed by slot name
foreach ($imgStmt->fetchAll() as $img) {
    $images[$img['image_slot']] = $img;
}

// All 6 canonical slot definitions (front is required; others optional)
$gallerySlots = [
    'front'  => 'img_slot_front',
    'back'   => 'img_slot_back',
    'left'   => 'img_slot_left',
    'right'  => 'img_slot_right',
    'aerial' => 'img_slot_aerial',
    'bottom' => 'img_slot_bottom',
];

// ── Load keywords ─────────────────────────────────────────────
$kwStmt = $pdo->prepare(
    'SELECT keyword FROM product_keywords WHERE product_id = ? ORDER BY keyword ASC'
);
$kwStmt->execute([$productId]);
$productKeywords = $kwStmt->fetchAll(PDO::FETCH_COLUMN);

// ── View helpers ──────────────────────────────────────────────
$esc       = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$username  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial   = strtoupper(substr($username, 0, 1));
$orgName   = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

$fmtPrice = fn($v) => ($v !== null && $v !== '')
    ? '$ ' . number_format((float) $v, 2)
    : '<span class="text-muted">—</span>';

$fmtSize = function (int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 0)    . ' KB';
    return $bytes . ' B';
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= $esc(t('product_view_page_title')) ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= $username ?>
                <span class="org-badge"><?= $orgName ?></span>
            </span>
        </div>
        <div class="top-bar-right">
            <nav class="top-bar-lang" aria-label="<?= t('language_label') ?>">
                <a href="?id=<?= $productId ?>&set_lang=es"
                   class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
                <span class="lang-sep">|</span>
                <a href="?id=<?= $productId ?>&set_lang=en"
                   class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
                <span class="lang-sep">|</span>
                <a href="?id=<?= $productId ?>&set_lang=zh"
                   class="lang-btn<?= $lang === 'zh' ? ' active' : '' ?>" hreflang="zh">中文</a>
            </nav>
            <form method="POST" action="/login/logout.php" class="top-bar-logout">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
            </form>
        </div>
    </div>

    <?= renderTabs('all_products') ?>

    <div class="page-content">

        <!-- Back button -->
        <div style="margin-bottom:18px;">
            <a href="/login/admin/products.php" class="btn-secondary btn-sm">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"
                     style="vertical-align:middle;margin-right:4px;">
                    <path d="M10 12L6 8l4-4" stroke="currentColor" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?= $esc(t('btn_back_to_all_products')) ?>
            </a>
        </div>

        <!-- ════════════════════ PRODUCT DETAIL ════════════════════ -->
        <div class="card profile-form-card" style="max-width:860px;">

            <h1 class="card-title"><?= $esc(t('product_view_title')) ?></h1>

            <!-- ── Top info sections (2-column on wide screens) ── -->
            <div class="profile-sections-layout" style="margin-bottom:24px;">

                <!-- Left: product core fields -->
                <div class="profile-sections-col">
                    <div class="form-section" style="margin-bottom:0;">
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

                        <div class="product-detail-grid">

                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('col_product_code')) ?></span>
                                <span class="detail-value">
                                    <code style="font-size:0.9rem;background:#f5f5f7;
                                                 padding:2px 8px;border-radius:6px;">
                                        <?= $esc($product['supplier_product_code']) ?>
                                    </code>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('col_internal_code')) ?></span>
                                <span class="detail-value">
                                    <?php if (!empty($product['internal_product_code'])): ?>
                                    <code style="font-size:0.9rem;background:#eaf4ff;color:#0071e3;
                                                 padding:2px 8px;border-radius:6px;">
                                        <?= $esc($product['internal_product_code']) ?>
                                    </code>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </span>
                            </div>

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
                                <span class="detail-label"><?= $esc(t('field_status')) ?></span>
                                <span class="detail-value">
                                    <?php if ((int)$product['active'] === 1): ?>
                                    <span class="status-badge status-badge--active"><?= t('status_active') ?></span>
                                    <?php else: ?>
                                    <span class="status-badge status-badge--inactive"><?= t('status_inactive') ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('field_created_at_label')) ?></span>
                                <span class="detail-value text-muted">
                                    <?= date('d/m/Y H:i', strtotime($product['created_at'])) ?>
                                </span>
                            </div>

                            <?php if (!empty($product['updated_at'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('field_updated_at')) ?></span>
                                <span class="detail-value text-muted">
                                    <?= date('d/m/Y H:i', strtotime($product['updated_at'])) ?>
                                </span>
                            </div>
                            <?php endif; ?>

                        </div><!-- /product-detail-grid -->
                    </div><!-- /form-section -->
                </div><!-- /col left -->

                <!-- Right: supplier and org info -->
                <div class="profile-sections-col">
                    <div class="form-section" style="margin-bottom:0;">
                        <h2 class="form-section-title">
                            <span class="section-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"
                                          stroke="currentColor" stroke-width="1.5"
                                          stroke-linecap="round"/>
                                </svg>
                            </span>
                            <?= $esc(t('section_supplier_info')) ?>
                        </h2>

                        <div class="product-detail-grid">

                            <?php if (!empty($product['supplier_company'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('company_name_label')) ?></span>
                                <span class="detail-value" style="font-weight:600;">
                                    <?= $esc($product['supplier_company']) ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($product['supplier_full_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('field_full_name')) ?></span>
                                <span class="detail-value"><?= $esc($product['supplier_full_name']) ?></span>
                            </div>
                            <?php endif; ?>

                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('col_supplier')) ?></span>
                                <span class="detail-value">
                                    <code style="font-size:0.85rem;background:#f5f5f7;
                                                 padding:2px 6px;border-radius:6px;">
                                        <?= $esc($product['supplier_username']) ?>
                                    </code>
                                </span>
                            </div>

                            <?php if (!empty($product['supplier_email'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('field_email')) ?></span>
                                <span class="detail-value" style="word-break:break-all;">
                                    <a href="mailto:<?= $esc($product['supplier_email']) ?>"
                                       style="color:var(--color-accent);">
                                        <?= $esc($product['supplier_email']) ?>
                                    </a>
                                </span>
                            </div>
                            <?php endif; ?>

                            <?php
                            $phoneCode   = $product['supplier_phone_code']   ?? '';
                            $phoneNumber = $product['supplier_phone_number'] ?? '';
                            if ($phoneCode !== '' || $phoneNumber !== ''):
                            ?>
                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('company_phone_label')) ?></span>
                                <span class="detail-value">
                                    <?= $esc(trim($phoneCode . ' ' . $phoneNumber)) ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($product['org_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><?= $esc(t('col_org')) ?></span>
                                <span class="detail-value">
                                    <span class="org-badge org-badge--sm">
                                        <?= $esc($product['org_name']) ?>
                                    </span>
                                </span>
                            </div>
                            <?php endif; ?>

                        </div><!-- /product-detail-grid -->
                    </div><!-- /form-section -->
                </div><!-- /col right -->

            </div><!-- /profile-sections-layout -->

            <!-- ── Technical description (full width) ────── -->
            <?php if (!empty($product['technical_description'])): ?>
            <div class="form-section" style="margin-top:0;margin-bottom:24px;">
                <h2 class="form-section-title" style="font-size:0.95rem;">
                    <?= $esc(t('field_tech_desc')) ?>
                </h2>
                <div class="tech-desc-block">
                    <?= nl2br($esc($product['technical_description'])) ?>
                </div>
            </div>
            <?php endif; ?>

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

                <!-- Front view (main / large) -->
                <?php if (isset($images['front'])): ?>
                <div style="margin-bottom:16px;">
                    <div class="gallery-slot gallery-slot--aerial">
                        <img src="<?= $esc(Storage::imageUrl($images['front']['file_path'])) ?>"
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
                $hasOptional   = false;
                foreach ($optionalSlots as $s) {
                    if (isset($images[$s])) { $hasOptional = true; break; }
                }
                if ($hasOptional):
                ?>
                <div class="product-gallery"
                     style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
                    <?php foreach ($optionalSlots as $slot): ?>
                        <?php if (isset($images[$slot])): ?>
                        <div class="gallery-slot">
                            <img src="<?= $esc(Storage::imageUrl($images[$slot]['file_path'])) ?>"
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

            <!-- ── Keywords ──────────────────────────────── -->
            <?php if (!empty($productKeywords)): ?>
            <div class="form-section" style="margin-top:20px;">
                <h2 class="form-section-title" style="font-size:0.95rem;">
                    <?= $esc(t('section_product_keywords')) ?>
                </h2>
                <div class="keyword-tags-readonly">
                    <?php foreach ($productKeywords as $kw): ?>
                    <span class="keyword-chip keyword-chip--readonly">
                        <?= $esc($kw) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /card -->

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <!-- Lightbox overlay -->
    <div class="lightbox-overlay" id="lightboxOverlay"
         onclick="closeLightbox()" role="dialog" aria-modal="true"
         aria-label="<?= $esc(t('img_slot_front')) ?>">
        <button type="button" class="lightbox-close"
                onclick="closeLightbox()" aria-label="Cerrar">&#x2715;</button>
        <img src="" alt="" class="lightbox-img" id="lightboxImg">
    </div>

    <script>
    // ── Idle timeout logout ────────────────────────────────────
    (function () {
        const TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        let last = Date.now();
        ['mousemove', 'keydown', 'click', 'scroll'].forEach(function (ev) {
            document.addEventListener(ev, function () { last = Date.now(); }, { passive: true });
        });
        setInterval(function () {
            if (Date.now() - last >= TIMEOUT_MS) {
                window.location.href = '/login/index.php?reason=timeout';
            }
        }, 10000);
    }());

    // ── Lightbox ───────────────────────────────────────────────
    function openLightbox(src, alt) {
        const overlay = document.getElementById('lightboxOverlay');
        const img     = document.getElementById('lightboxImg');
        img.src = src;
        img.alt = alt || '';
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeLightbox() {
        const overlay = document.getElementById('lightboxOverlay');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        document.getElementById('lightboxImg').src = '';
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeLightbox();
    });
    </script>

</body>
</html>

