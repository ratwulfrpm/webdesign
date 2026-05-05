<?php
/**
 * /login/admin/products.php — Global supplier-products listing
 *
 * Access  : role = 'admin' OR role = 'owner'
 * Features:
 *  - Lists ALL products from ALL suppliers across ALL organizations
 *  - Columns: product name, supplier code, admin code, supplier,
 *             business unit (org), status, creation date,
 *             photo count, has front view, keywords
 *  - Search/filter: name/code, supplier, org, status, front-view presence
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
require_once __DIR__ . '/../includes/storage.php';

requireAuth();
initLang();
requireRole(['admin', 'owner', 'support']);   // all three roles may access

$pdo     = getDB();
$lang    = currentLang();
$role    = $_SESSION['role'] ?? '';
$orgId   = (int) ($_SESSION['org_id'] ?? 0);
$orgName = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');

// ── Filters from GET ─────────────────────────────────────────
$fSearch    = mb_substr(trim($_GET['q']      ?? ''), 0, 200);
$fOrg       = (int) ($_GET['org']    ?? 0);
$fStatus    = $_GET['status']  ?? '';          // '' | 'active' | 'inactive'
$fFront     = $_GET['front']   ?? '';          // '' | 'yes' | 'no'

// Whitelist status values
if (!in_array($fStatus, ['', 'active', 'inactive'], true)) $fStatus = '';
if (!in_array($fFront,  ['', 'yes', 'no'],           true)) $fFront  = '';

// ── Load accessible organizations ────────────────────────────
// Admin/Support: orgs they are a member of (scoped to assigned BUs).
// Owner: all active orgs in the system.
if ($role === 'admin' || $role === 'support') {
    $oStmt = $pdo->prepare(
        'SELECT o.id, o.name FROM org_members om
           JOIN organizations o ON o.id = om.org_id
          WHERE om.user_id = ? AND om.is_active = 1 AND o.is_active = 1
          ORDER BY o.name ASC'
    );
    $oStmt->execute([(int) $_SESSION['user_id']]);
    $orgs = $oStmt->fetchAll();
} else {
    $orgs = $pdo->query(
        'SELECT id, name FROM organizations WHERE is_active = 1 ORDER BY name ASC'
    )->fetchAll();
}

if ($role === 'support') {
    $orgs = array_values(array_filter(
        $orgs,
        fn($row) => (int) ($row['id'] ?? 0) === $orgId
    ));
    if ($fOrg > 0 && $fOrg !== $orgId) {
        $fOrg = $orgId;
    }
}

// IDs the admin/support is allowed to see (used to enforce tenant isolation)
$allowedOrgIds = ($role === 'admin' || $role === 'support')
    ? array_map('intval', array_column($orgs, 'id'))
    : [];   // empty = no restriction for owner

// ── Build query ───────────────────────────────────────────────
$conditions = ['1=1'];
$params     = [];

// Admin: restrict to all assigned orgs.
if ($role === 'admin' && !empty($allowedOrgIds)) {
    $placeholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $conditions[] = "p.org_id IN ($placeholders)";
    $params       = array_merge($params, $allowedOrgIds);
}

// Support: strict active-BU scope only.
if ($role === 'support') {
    if ($orgId <= 0 || !in_array($orgId, $allowedOrgIds, true)) {
        $conditions[] = '1=0';
    } else {
        $conditions[] = 'p.org_id = ?';
        $params[] = $orgId;
    }
}

if ($fSearch !== '') {
    $like = '%' . $fSearch . '%';
    $conditions[] = '(p.product_name LIKE ? OR p.supplier_product_code LIKE ?
                       OR p.admin_product_code LIKE ?
                       OR p.internal_product_code LIKE ?
                       OR u.username LIKE ? OR u.company_name LIKE ?
                       OR EXISTS (SELECT 1 FROM product_keywords pk2
                                   WHERE pk2.product_id = p.id AND pk2.keyword LIKE ?))';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
}

// Org filter: allowed for all roles, but admin can only filter within their allowed orgs
if ($fOrg > 0) {
    if ($role === 'admin' && !in_array($fOrg, $allowedOrgIds, true)) {
        $fOrg = 0;   // silently ignore out-of-scope org filter
    }
    if ($role === 'support' && $fOrg !== $orgId) {
        $fOrg = $orgId;
    }
    if ($fOrg > 0) {
        $conditions[] = 'p.org_id = ?';
        $params[]     = $fOrg;
    }
}

if ($fStatus === 'active') {
    $conditions[] = 'p.active = 1';
} elseif ($fStatus === 'inactive') {
    $conditions[] = 'p.active = 0';
}

if ($fFront === 'yes') {
    $conditions[] = 'EXISTS (SELECT 1 FROM supplier_product_images fi
                              WHERE fi.product_id = p.id AND fi.image_slot = "front")';
} elseif ($fFront === 'no') {
    $conditions[] = 'NOT EXISTS (SELECT 1 FROM supplier_product_images fi
                                  WHERE fi.product_id = p.id AND fi.image_slot = "front")';
}

$whereClause = implode(' AND ', $conditions);

$sql = "
    SELECT
        p.id,
        p.supplier_product_code,
        p.admin_product_code,
        p.internal_product_code,
        p.product_name,
        p.active,
        p.created_at,
        u.id         AS supplier_id,
        u.username   AS supplier_username,
        u.company_name AS supplier_company,
        o.id         AS org_id,
        o.name       AS org_name,
        (SELECT COUNT(*) FROM supplier_product_images si WHERE si.product_id = p.id) AS photo_count,
        (SELECT file_path FROM supplier_product_images fi
          WHERE fi.product_id = p.id AND fi.image_slot = 'front' LIMIT 1) AS front_img_path,
        (SELECT GROUP_CONCAT(pk.keyword ORDER BY pk.keyword SEPARATOR ', ')
           FROM product_keywords pk WHERE pk.product_id = p.id) AS keywords
    FROM supplier_products p
    JOIN users u        ON u.id = p.supplier_id
    JOIN organizations o ON o.id = p.org_id
    WHERE $whereClause
    GROUP BY p.id, p.supplier_product_code, p.admin_product_code, p.internal_product_code, p.product_name,
             p.active, p.created_at,
             u.id, u.username, u.company_name,
             o.id, o.name
    ORDER BY p.created_at DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// ── View helpers ──────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

// Build GET filter string for form action
$filterUrl = function(array $overrides = []): string {
    global $fSearch, $fOrg, $fStatus, $fFront;
    $base = ['q' => $fSearch, 'org' => $fOrg ?: '', 'status' => $fStatus, 'front' => $fFront];
    $merged = array_merge($base, $overrides);
    return '/login/admin/products.php?' . http_build_query(array_filter($merged, fn($v) => $v !== ''));
};
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('all_products_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= $username ?>
                <?php if ($orgName !== ''): ?><span class="org-badge"><?= $orgName ?></span><?php endif; ?>
            </span>
        </div>
        <div class="top-bar-right">
            <nav class="top-bar-lang" aria-label="<?= t('language_label') ?>">
                <a href="?set_lang=es<?= $fOrg ? '&org='.$fOrg : '' ?>"
                   class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=en<?= $fOrg ? '&org='.$fOrg : '' ?>"
                   class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=zh<?= $fOrg ? '&org='.$fOrg : '' ?>"
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

        <section class="panel-section">
            <h1 class="section-title"><?= t('all_products_title') ?></h1>
            <p class="text-muted" style="margin-bottom:20px;">
                <?php if ($role === 'admin'): ?>
                    <?= t('all_products_subtitle_admin') ?>
                    <?= implode(', ', array_map(fn($o) => '<strong>' . htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') . '</strong>', $orgs)) ?>
                <?php elseif ($role === 'support'): ?>
                    <?= t('all_products_subtitle_admin') ?>
                    <strong><?= $esc($_SESSION['org_name'] ?? '') ?></strong>
                <?php else: ?>
                    <?= t('all_products_subtitle') ?>
                <?php endif; ?>
            </p>

            <!-- ── Filter form ────────────────────────────── -->
            <form method="GET" action="/login/admin/products.php"
                  class="filter-form" style="margin-bottom:20px;">

                <div class="filter-row">
                    <div class="filter-group filter-group--wide">
                        <label for="f-q" class="filter-label"><?= t('filter_search_label') ?></label>
                        <input type="text"
                               id="f-q"
                               name="q"
                               class="filter-input"
                               value="<?= $esc($fSearch) ?>"
                               placeholder="<?= $esc(t('filter_search_ph')) ?>"
                               maxlength="200">
                    </div>

                    <div class="filter-group">
                        <label for="f-org" class="filter-label"><?= t('filter_org_label') ?></label>
                        <select id="f-org" name="org" class="filter-select">
                            <option value=""><?= $esc(t('filter_org_all')) ?></option>
                            <?php foreach ($orgs as $org): ?>
                            <option value="<?= (int) $org['id'] ?>"
                                <?= $fOrg === (int) $org['id'] ? ' selected' : '' ?>>
                                <?= $esc($org['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="f-status" class="filter-label"><?= t('filter_status_label') ?></label>
                        <select id="f-status" name="status" class="filter-select">
                            <option value=""><?= $esc(t('filter_status_all')) ?></option>
                            <option value="active"<?= $fStatus === 'active' ? ' selected' : '' ?>>
                                <?= $esc(t('filter_status_active')) ?>
                            </option>
                            <option value="inactive"<?= $fStatus === 'inactive' ? ' selected' : '' ?>>
                                <?= $esc(t('filter_status_inactive')) ?>
                            </option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="f-front" class="filter-label"><?= t('filter_front_label') ?></label>
                        <select id="f-front" name="front" class="filter-select">
                            <option value=""><?= $esc(t('filter_front_all')) ?></option>
                            <option value="yes"<?= $fFront === 'yes' ? ' selected' : '' ?>>
                                <?= $esc(t('filter_front_yes')) ?>
                            </option>
                            <option value="no"<?= $fFront === 'no' ? ' selected' : '' ?>>
                                <?= $esc(t('filter_front_no')) ?>
                            </option>
                        </select>
                    </div>

                    <div class="filter-group filter-group--actions">
                        <button type="submit" class="btn-primary btn-sm">
                            <?= $esc(t('btn_filter')) ?>
                        </button>
                        <a href="/login/admin/products.php" class="btn-secondary btn-sm">
                            <?= $esc(t('btn_clear_filters')) ?>
                        </a>
                    </div>
                </div>
            </form>

            <!-- ── Results count ──────────────────────────── -->
            <p class="text-muted" style="margin-bottom:10px;font-size:0.88rem;">
                <?= count($products) ?> resultado(s)
            </p>

            <!-- ── Products table ────────────────────────── -->
            <?php if (empty($products)): ?>
                <p class="text-muted"><?= t('no_products_global') ?></p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table--products">
                    <thead>
                        <tr>
                            <th style="width:64px;"><?= $esc(t('col_front_view')) ?></th>
                            <th><?= $esc(t('col_product_name')) ?></th>
                            <th><?= $esc(t('col_product_code')) ?></th>
                            <th><?= $esc(t('field_admin_code')) ?></th>
                            <th><?= $esc(t('col_internal_code')) ?></th>
                            <th><?= $esc(t('col_supplier')) ?></th>
                            <th><?= $esc(t('col_org')) ?></th>
                            <th><?= $esc(t('col_active')) ?></th>
                            <th><?= $esc(t('col_created_at')) ?></th>
                            <th><?= $esc(t('col_photos')) ?></th>
                            <th><?= $esc(t('col_keywords')) ?></th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td>
                            <?php if (!empty($p['front_img_path'])): ?>
                                <img src="<?= $esc(Storage::imageUrl($p['front_img_path'])) ?>"
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
                            <td>
                                <strong><?= $esc($p['product_name']) ?></strong>
                            </td>
                            <td>
                                <code class="code-cell"><?= $esc($p['supplier_product_code']) ?></code>
                            </td>
                            <td>
                                <?php if ($p['admin_product_code']): ?>
                                <code class="code-cell"><?= $esc($p['admin_product_code']) ?></code>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['internal_product_code']): ?>
                                <code class="code-cell" style="background:#eaf4ff;color:#0071e3;">
                                    <?= $esc($p['internal_product_code']) ?>
                                </code>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="supplier-cell">
                                    <span class="supplier-username"><?= $esc($p['supplier_username']) ?></span>
                                    <?php if ($p['supplier_company']): ?>
                                    <span class="supplier-company text-muted"><?= $esc($p['supplier_company']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="org-badge org-badge--sm"><?= $esc($p['org_name']) ?></span>
                            </td>
                            <td>
                                <?php if ((int)$p['active'] === 1): ?>
                                <span class="status-badge status-badge--active"><?= t('status_active') ?></span>
                                <?php else: ?>
                                <span class="status-badge status-badge--inactive"><?= t('status_inactive') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="date-cell">
                                <?= $esc(substr($p['created_at'], 0, 10)) ?>
                            </td>
                            <td class="count-cell">
                                <?= (int) $p['photo_count'] ?>
                            </td>
                            <td>
                                <?php if ($p['keywords']): ?>
                                <div class="keywords-cell">
                                    <?php foreach (explode(', ', $p['keywords']) as $kw): ?>
                                    <span class="keyword-chip keyword-chip--readonly keyword-chip--xs">
                                        <?= $esc(trim($kw)) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="/login/admin/product_view.php?id=<?= (int)$p['id'] ?>"
                                   class="btn-tbl btn-tbl-view"><?= $esc(t('btn_view_product')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </section>
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

