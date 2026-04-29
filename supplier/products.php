<?php
/**
 * /login/supplier/products.php — Listado de productos del proveedor
 *
 * Muestra todos los productos registrados por el proveedor autenticado,
 * con thumbnail de la imagen aérea y enlace al detalle.
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

requireAuth();
initLang();
requireRole(['supplier']);

if ((int) ($_SESSION['first_login'] ?? 1) === 1) {
    header('Location: /login/supplier/profile.php');
    exit;
}

$pdo         = getDB();
$lang        = currentLang();
$supplierId  = (int) $_SESSION['user_id'];

// Show success banner after save redirect
$justSaved = isset($_GET['saved']);

// Load all products with front-view thumbnail and image count
$stmt = $pdo->prepare(
    'SELECT p.id,
            p.supplier_product_code,
            p.product_name,
            p.price_fob,
            p.price_cif,
            p.active,
            p.created_at,
            thumb.file_path AS thumbnail,
            (SELECT COUNT(*) FROM supplier_product_images si WHERE si.product_id = p.id) AS img_count,
            (SELECT COUNT(*) FROM product_keywords pk WHERE pk.product_id = p.id) AS kw_count
       FROM supplier_products p
       LEFT JOIN supplier_product_images thumb
              ON thumb.product_id = p.id AND thumb.image_slot = "front"
      WHERE p.supplier_id = ?
      ORDER BY p.created_at DESC'
);
$stmt->execute([$supplierId]);
$products = $stmt->fetchAll();

// ── View helpers ──────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$username  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial   = strtoupper(substr((string)($_SESSION['username'] ?? '?'), 0, 1));
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('my_products_page_title') ?></title>
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

    <?= renderTabs('my_products') ?>

    <div class="page-content">

        <?php if ($justSaved): ?>
        <div class="alert alert-success" role="status" style="margin-bottom:16px;">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#34c759" stroke-width="1.5"/>
                <polyline points="4.5,8 7,10.5 11.5,5.5" stroke="#34c759" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?= t('product_saved') ?></span>
        </div>
        <?php endif; ?>

        <div class="panel-section" style="display:flex;align-items:center;justify-content:space-between;
             margin-bottom:16px;flex-wrap:wrap;gap:10px;">
            <div>
                <h1 class="section-title" style="margin-bottom:2px;border:none;padding:0;">
                    <?= t('my_products_title') ?>
                </h1>
                <p style="font-size:0.875rem;color:var(--color-text-muted);margin:0;">
                    <?= t('my_products_subtitle') ?>
                </p>
            </div>
            <a href="/login/supplier/add_product.php" class="btn-secondary">
                + <?= $esc(t('btn_add_new_product')) ?>
            </a>
        </div>

        <?php if (empty($products)): ?>
        <div class="alert alert-info" role="status">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#0071e3" stroke-width="1.5"/>
                <line x1="8" y1="7" x2="8" y2="11" stroke="#0071e3" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="5" r=".75" fill="#0071e3"/>
            </svg>
            <span><?= t('no_products') ?></span>
        </div>
        <?php else: ?>

        <div class="table-wrap">
            <table class="data-table" role="grid">
                <thead>
                    <tr>
                        <th style="width:72px;"><?= t('col_product_images') ?></th>
                        <th><?= t('col_product_code') ?></th>
                        <th><?= t('col_product_name') ?></th>
                        <th><?= t('col_product_price_fob') ?></th>
                        <th><?= t('col_product_price_cif') ?></th>
                        <th><?= t('col_product_date') ?></th>
                        <th><?= t('col_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <!-- Thumbnail -->
                        <td>
                        <?php if (!empty($p['thumbnail'])): ?>
                            <img src="/login/<?= $esc($p['thumbnail']) ?>"
                                 alt="<?= $esc($p['product_name']) ?>"
                                 style="width:56px;height:42px;object-fit:cover;
                                        border-radius:8px;border:1px solid var(--color-border);">
                        <?php else: ?>
                            <div style="width:56px;height:42px;border-radius:8px;
                                        background:#f0f0f3;border:1px solid var(--color-border);
                                        display:flex;align-items:center;justify-content:center;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                     style="opacity:.3">
                                    <rect x="3" y="3" width="18" height="18" rx="3"
                                          stroke="currentColor" stroke-width="1.5"/>
                                    <circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M21 15l-5-5L5 21" stroke="currentColor"
                                          stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        </td>

                        <!-- Código -->
                        <td>
                            <code style="font-size:0.8rem;background:#f5f5f7;padding:2px 6px;
                                         border-radius:6px;"><?= $esc($p['supplier_product_code']) ?></code>
                            <?php if ((int)$p['img_count'] > 0): ?>
                            <br><span class="badge badge-done" style="margin-top:4px;">
                                <?= (int)$p['img_count'] ?> img
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Nombre -->
                        <td style="font-weight:500;">
                            <?= $esc($p['product_name']) ?>
                        </td>

                        <!-- FOB -->
                        <td>
                            <?= $p['price_fob'] !== null
                                ? '$&nbsp;' . number_format((float)$p['price_fob'], 2)
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <!-- CIF -->
                        <td>
                            <?= $p['price_cif'] !== null
                                ? '$&nbsp;' . number_format((float)$p['price_cif'], 2)
                                : '<span class="text-muted">—</span>' ?>
                        </td>

                        <!-- Fecha -->
                        <td class="text-muted" style="font-size:0.82rem;white-space:nowrap;">
                            <?= date('d/m/Y', strtotime($p['created_at'])) ?>
                        </td>

                        <!-- Acciones -->
                        <td class="actions-cell">
                            <a href="/login/supplier/product_view.php?id=<?= (int)$p['id'] ?>"
                               class="btn-tbl btn-tbl-view">
                                <?= t('btn_view_product') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    (function () {
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
    }());
    </script>

</body>
</html>
