<?php
/**
 * router.php — Progressive front controller entry point.
 *
 * This file is the single entry point for clean-URL web requests.
 * Apache's mod_rewrite (configured in .htaccess) sends here any request
 * for a path that does NOT correspond to an existing file or directory.
 *
 * ── Transition strategy ───────────────────────────────────────
 * This is a PROGRESSIVE front controller.  It does NOT replace the
 * existing direct-access pattern:
 *
 *   Legacy (still works):   http://localhost/login/admin/products.php
 *   Clean URL (via router): http://localhost/login/admin/products
 *
 * Both paths serve the same content.  Legacy files are included as
 * handlers — no views are rewritten, no logic is duplicated.
 *
 * ── Load order ────────────────────────────────────────────────
 *   1. session.php     — secure cookie params + session_start()
 *   2. auth.php        — requireAuth() / requireRole() / Auth class
 *   3. RBAC.php        — RBAC::can() permission matrix
 *   4. TenantScope.php — tenant/BU scope resolver
 *   5. Router.php      — route dispatcher class
 *   6. config/routes.php — route definitions (registers all routes)
 *   7. Router::dispatch() — match URI → run handler → include legacy file
 *
 * ── API routes ────────────────────────────────────────────────
 * Routes under /api/v1/* are NOT dispatched here.
 * The .htaccess excludes /api/ paths from this front controller.
 * API requests flow through api/v1/index.php with its own .htaccess.
 *
 * ── Security ──────────────────────────────────────────────────
 * - Security headers are set below; legacy files may add more.
 * - Authentication is enforced per route via requireAuth()/requireRole().
 * - Token-only routes (quote, enroll) bypass session auth intentionally.
 * - No HTML output before dispatch (headers can still be set freely).
 *
 * Owner/Admin parity:
 *   All role checks are defined in config/routes.php.
 *   Owner always inherits admin-level access and extends it globally.
 *   See RBAC_API_MANUAL.md for the full role model.
 */

// ── Security headers ─────────────────────────────────────────
// Baseline headers; individual pages may add Cache-Control etc.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Bootstrap core layer ──────────────────────────────────────
// Load in dependency order.  All require_once calls are safe to
// re-declare inside included handler files (PHP deduplicates).
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/RBAC.php';
require_once __DIR__ . '/includes/TenantScope.php';

// ── Load router ───────────────────────────────────────────────
require_once __DIR__ . '/includes/Router.php';

// ── Register routes ───────────────────────────────────────────
// This file calls Router::setBasePath() and all Router::any/get/post().
require_once __DIR__ . '/config/routes.php';

// ── Dispatch ──────────────────────────────────────────────────
$_routerMatched = Router::dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI']    ?? '/'
);

if (!$_routerMatched) {
    Router::notFound();
}
