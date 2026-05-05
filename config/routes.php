<?php
/**
 * config/routes.php — Central web route definitions.
 *
 * Registers all application web routes through Router.
 * Each handler is a closure that includes the corresponding legacy PHP file.
 * This approach preserves full backward compatibility: the existing files
 * are not modified, they just receive an additional clean-URL entry point.
 *
 * ── Route types ───────────────────────────────────────────────
 *  public=true, tokenRoute=false  → public pages (login, enroll, forgot-password)
 *  public=false, roles=[...]      → authenticated pages with role guard
 *  tokenRoute=true                → public token-only link; no session applied
 *  public=true (own guard inside) → org-picker (requirePendingAuth inside file)
 *
 * ── API separation ────────────────────────────────────────────
 *  Routes under /api/v1/* are NOT registered here.
 *  The REST API has its own front controller (api/v1/index.php) and its
 *  own .htaccess.  Web routes and API routes are fully independent.
 *  See: RBAC_API_MANUAL.md — section "Web vs API routing separation".
 *
 * ── Admin → Owner parity ──────────────────────────────────────
 *  Every route that grants access to 'admin' ALSO grants access to 'owner'
 *  (owner rank 4 ≥ admin rank 3 — owner always inherits admin capabilities).
 *
 *  Excepción Admin/Owner — /admin/users:
 *    owner is deliberately excluded from /admin/users because the owner
 *    has a dedicated panel at /owner/users with superset capabilities
 *    (full user list across all roles + change_role permission).
 *    Adding 'owner' to /admin/users would give the owner a degraded view.
 *    Owner capabilities are PRESERVED, not reduced.
 *
 * ── Base path ─────────────────────────────────────────────────
 *  Current MAMP deployment: http://localhost:{port}/login/
 *  If your virtual host serves the project at domain root, change to ''.
 */

Router::setBasePath('/login');

// ═══════════════════════════════════════════════════════════════
//  PUBLIC ROUTES  (no authentication required)
// ═══════════════════════════════════════════════════════════════

/**
 * Login page.
 * Also handles POST (form submission with CSRF protection).
 * Clean URLs: /login  or  /login/
 * Legacy URL: /login/index.php
 */
Router::any('/', static function (array $p): void {
    require __DIR__ . '/../index.php';
}, [], true);

/**
 * Supplier self-registration via invitation token.
 * GET  → renders registration form.
 * POST → validates and creates user account.
 * Token: ?t={plain_hex_token}
 * Legacy URL: /login/enroll.php?t=…
 */
Router::any('/enroll', static function (array $p): void {
    require __DIR__ . '/../enroll.php';
}, [], true);

/**
 * Password request form (public — no login needed).
 * Used by suppliers who have forgotten their password.
 * Legacy URL: /login/forgot-password.php
 */
Router::any('/forgot-password', static function (array $p): void {
    require __DIR__ . '/../forgot_password.php';
}, [], true);

// ═══════════════════════════════════════════════════════════════
//  TOKEN-ONLY PUBLIC ROUTES  (public, token-isolated)
// ═══════════════════════════════════════════════════════════════

/**
 * Public product quotation view.
 *
 * Security contract:
 *  - No session authentication is applied (tokenRoute = true).
 *  - Access is controlled entirely by the SHA-256 token in ?t=.
 *  - An authenticated admin/owner who opens a quote link is served the
 *    same customer-facing view — NO internal prices, supplier codes,
 *    or FOB/CIF data are exposed.
 *  - quote.php enforces this isolation internally.
 *
 * Token: ?t={plain_64hex_token}
 * Legacy URL: /login/quote.php?t=…
 */
Router::any('/quote', static function (array $p): void {
    require __DIR__ . '/../quote.php';
}, [], false, true);   // public=false, tokenRoute=true

// ═══════════════════════════════════════════════════════════════
//  SEMI-PUBLIC ROUTES  (own auth guard inside the file)
// ═══════════════════════════════════════════════════════════════

/**
 * Organization selector — shown to support users with multiple BUs.
 * Uses a pending session (Phase 1 of login).
 * The file calls requirePendingAuth() internally — Router does not apply
 * requireAuth() to avoid interfering with the pending-session guard.
 * Legacy URL: /login/org-picker.php
 */
Router::any('/org-picker', static function (array $p): void {
    require __DIR__ . '/../org-picker.php';
}, [], true);   // public=true so Router skips auth; file's own guard runs

// ═══════════════════════════════════════════════════════════════
//  AUTHENTICATED ROUTES — any valid role
// ═══════════════════════════════════════════════════════════════

/**
 * Logout.
 * GET  ?cancel=1  → cancel pending session (no CSRF required).
 * POST            → full logout with CSRF validation.
 * Legacy URL: /login/logout.php
 */
Router::any('/logout', static function (array $p): void {
    require __DIR__ . '/../logout.php';
}, ['owner', 'admin', 'support', 'supplier']);

/**
 * Dashboard — role-based dispatcher.
 * Redirects immediately to the correct role panel.
 * Legacy URL: /login/dashboard.php
 */
Router::any('/dashboard', static function (array $p): void {
    require __DIR__ . '/../dashboard.php';
}, ['owner', 'admin', 'support', 'supplier']);

/**
 * Switch active business unit (support only, POST).
 * Legacy URL: /login/switch_org.php
 */
Router::any('/switch-org', static function (array $p): void {
    require __DIR__ . '/../switch_org.php';
}, ['support']);

// ═══════════════════════════════════════════════════════════════
//  ADMIN ROUTES  (owner inherits all admin routes per parity rule)
// ═══════════════════════════════════════════════════════════════

/**
 * Global product listing — all suppliers across all organizations.
 * owner: global scope (all BUs).
 * admin: scoped to assigned BUs.
 * support: scoped to active BU.
 * Legacy URL: /login/admin/products.php
 */
Router::any('/admin/products', static function (array $p): void {
    require __DIR__ . '/../admin/products.php';
}, ['owner', 'admin', 'support']);

/**
 * Product detail view (read-only).
 * Path parameter {id} is injected into $_GET for legacy compatibility.
 * Alternatively accessible via /login/admin/product_view.php?id={id}
 * Legacy URL: /login/admin/product_view.php?id=…
 */
Router::any('/admin/products/{id}', static function (array $p): void {
    $_GET['id'] = $p['id'];
    require __DIR__ . '/../admin/product_view.php';
}, ['owner', 'admin', 'support']);

/**
 * User management — activate/deactivate/unlock/password-reset.
 *
 * Excepción Admin/Owner:
 *   'owner' is deliberately excluded here.
 *   Reason: owner has a dedicated, more capable panel at /owner/users
 *   which includes change_role functionality not present in admin/users.php.
 *   Including owner here would give the owner a DEGRADED view compared to
 *   their own panel.  Owner capabilities are preserved — NOT reduced.
 *   owner → /owner/users  (full: all roles, change_role, global scope)
 *   admin → /admin/users  (limited: supplier role only, no role change)
 *
 * Legacy URL: /login/admin/users.php
 */
Router::any('/admin/users', static function (array $p): void {
    require __DIR__ . '/../admin/users.php';
}, ['admin', 'support']);

/**
 * Multi-product quotation/assignment management.
 * owner: global scope.
 * admin: scoped to assigned BUs.
 * support: scoped to active BU.
 * Legacy URL: /login/admin/assignments.php
 */
Router::any('/admin/assignments', static function (array $p): void {
    require __DIR__ . '/../admin/assignments.php';
}, ['owner', 'admin', 'support']);

/**
 * Invitation management — generate/revoke supplier invitation links.
 * Also accessible at /invitations (legacy path alias).
 * owner: can invite admin/support/supplier.
 * admin: can invite support/supplier.
 * support: read-only (no create/revoke).
 * Legacy URL: /login/invitations.php
 */
Router::any('/admin/invitations', static function (array $p): void {
    require __DIR__ . '/../invitations.php';
}, ['owner', 'admin', 'support']);

/**
 * Invitations — legacy path alias (kept for backward compatibility).
 * Same handler and role list as /admin/invitations.
 */
Router::any('/invitations', static function (array $p): void {
    require __DIR__ . '/../invitations.php';
}, ['owner', 'admin', 'support']);

// ═══════════════════════════════════════════════════════════════
//  OWNER-EXCLUSIVE ROUTES  (no admin access — owner superset)
// ═══════════════════════════════════════════════════════════════

/**
 * Owner user management — full global user list.
 * Capabilities beyond admin: all roles visible, change_role allowed.
 * Global scope: all organizations, no BU filter.
 * Legacy URL: /login/owner/users.php
 */
Router::any('/owner/users', static function (array $p): void {
    require __DIR__ . '/../owner/users.php';
}, ['owner']);

/**
 * Owner business units management.
 * Create/toggle/assign admins to business units.
 * Exclusive to owner — no other role can manage BU structure.
 * Legacy URL: /login/owner/business_units.php
 */
Router::any('/owner/business-units', static function (array $p): void {
    require __DIR__ . '/../owner/business_units.php';
}, ['owner']);

// ═══════════════════════════════════════════════════════════════
//  SUPPLIER ROUTES  (supplier role only)
// ═══════════════════════════════════════════════════════════════

/**
 * Supplier main dashboard.
 * Redirects to profile if first_login = 1 (first-login guard inside file).
 * Legacy URL: /login/supplier/summary.php
 */
Router::any('/supplier/summary', static function (array $p): void {
    require __DIR__ . '/../supplier/summary.php';
}, ['supplier']);

/**
 * Supplier profile — personal, legal, address, and contact information.
 * Mandatory on first login (first_login = 1).
 * Legacy URL: /login/supplier/profile.php
 */
Router::any('/supplier/profile', static function (array $p): void {
    require __DIR__ . '/../supplier/profile.php';
}, ['supplier']);

/**
 * Add new product with images and keywords.
 * Requires completed profile (first_login = 0 guard inside file).
 * Legacy URL: /login/supplier/add_product.php
 */
Router::any('/supplier/add-product', static function (array $p): void {
    require __DIR__ . '/../supplier/add_product.php';
}, ['supplier']);

/**
 * Supplier product detail view and editing.
 * Verifies product belongs to authenticated supplier (anti-IDOR).
 * Path parameter {id} is injected into $_GET for legacy compatibility.
 * Legacy URL: /login/supplier/product_view.php?id=…
 */
Router::any('/supplier/products/{id}', static function (array $p): void {
    $_GET['id'] = $p['id'];
    require __DIR__ . '/../supplier/product_view.php';
}, ['supplier']);

/**
 * Supplier documents and contract history.
 * Upload contracts (PDF/image), mark primary, request validity review.
 * Legacy URL: /login/supplier/documents.php
 */
Router::any('/supplier/documents', static function (array $p): void {
    require __DIR__ . '/../supplier/documents.php';
}, ['supplier']);

/**
 * Supplier contract file download/serve.
 * Streams the stored contract file with per-role access control.
 * supplier  — may only access their own contracts.
 * admin     — may access contracts within their assigned BUs.
 * owner     — may access all contracts (global scope).
 *
 * Admin → Owner parity: both admin and owner have access here.
 *
 * Legacy URL: /login/supplier/contract_file.php
 */
Router::any('/supplier/contract-file', static function (array $p): void {
    require __DIR__ . '/../supplier/contract_file.php';
}, ['supplier', 'admin', 'owner']);

/**
 * Secure product-image serving endpoint.
 * Serves stored product images from outside the webroot.
 * Authentication: session-based (any role) OR valid public quote token.
 *
 * Admin → Owner parity: both admin and owner have access here.
 * See: storage-file.php for full RBAC and security details.
 *
 * GET /storage-file?path={relative_path}[&t={quote_token}]
 * Legacy URL: /login/storage-file.php
 */
Router::any('/storage-file', static function (array $p): void {
    require __DIR__ . '/../storage-file.php';
}, [], true);   // public=true: file has its own access control (session OR token)
