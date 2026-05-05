# RBAC + API Manual (May 2026)

This document consolidates the role behavior, API contract changes, and rollout checklist for the RBAC multi-tenant refactor.

## 1) Role Model

### owner
- Global scope.
- No active business unit required in session.
- Can manage invitations for `admin`, `support`, `supplier`.
- Can operate across all organizations.

### admin
- Multi-org assigned scope (from `org_members`).
- No active business unit required in session.
- Can manage invitations for `support` and `supplier` (not `admin`).
- Can operate only inside assigned organizations.

### support
- Strict active business unit scope.
- Requires active BU context in session.
- If multiple assigned BUs, must select/switch active BU.
- Can access Invitations UI in read-only mode (no create/revoke).

### supplier
- Own supplier scope (existing behavior preserved).
- Keeps active BU behavior in `/api/v1/me` for compatibility.

## 2) Session and Login Flow

Updated login/session behavior:
- Owner/admin login creates global session directly.
- Support:
  - 1 BU assigned: auto-select BU and login.
  - >1 BUs assigned: pending session then BU picker.
- Support mid-session switch is restricted to support memberships only.
- Support session revalidates active BU against live `org_members` on each authenticated request.
- If support active BU becomes invalid, session falls back to a valid assigned BU.

Security outcomes:
- Authorization is validated server-side by role and org scope.
- Session no longer requires `org_id` for owner/admin.
- Support is never allowed to operate outside active BU.

## 3) API Contract Changes

## `/api/v1/me`
- owner/admin:
  - returns `assigned_business_units`
  - does not require/return active BU context
- support/supplier:
  - returns `assigned_business_units`
  - returns `active_business_unit`

## `/api/v1/invitations`
- auth restricted to `admin|owner`.
- support access removed.
- role creation rules:
  - owner: `admin|support|supplier`
  - admin: `support|supplier`

## `/api/v1/users`
- support is allowed, but scoped to active BU only.
- owner/admin retain existing broader scopes (global vs assigned orgs).

## `/api/v1/business-units`
- create BU now uses DB transaction for:
  1. insert organization
  2. assign creating owner membership
- prevents partial state if membership assignment fails.

## 4) UI Behavior Changes

- support tabs include invitations in read-only mode.
- support-only BU switcher appears when support has >1 assigned BUs.
- owner/admin pages avoid showing empty org badge for global session.

### Invitations — merged into Users page for admin & owner

Previous behavior:
- admin and owner accessed invitations via a dedicated **"Invitations"** tab pointing to `/login/invitations.php`.

New behavior (current):
- The **"Invitations" tab is removed** from the admin and owner navigation bar.
- Invitation management (generate link, revoke, history) is now **embedded inside the Users page** (`/login/admin/users.php` for admin, `/login/owner/users.php` for owner).
- The Users page now displays three sections: **Users**, **Password Requests**, **Contract Validity Requests**, and a new **Invitations** section at the bottom.
- Admin can invite `support` and `supplier` roles (unchanged restriction).
- Owner can invite `admin`, `support`, and `supplier` roles (unchanged). The multi-org checkbox picker for admin invites is also embedded.
- Support retains a standalone **"Invitations"** tab on `invitations.php` for read-only listing.

### Users list — backend pagination (50 users/page)

- Admin users page (`/login/admin/users.php`) and owner users page (`/login/owner/users.php`) now paginate the users table at **50 users per page**.
- Page is controlled via the `upage` GET parameter.
- A page-number nav bar appears below the users table when total users exceed 50.

### Quotes (module: assignments) — transparent multi-BU product search (admin & owner)

> **UI label:** Cotizaciones / Quotes  
> **Internal module name:** `assignments` (file paths, DB tables, and API routes unchanged)

Previous behavior:
- admin and owner had to select a specific business unit before searching products in the quotes UI.
- Products were filtered to the selected BU only.

New behavior (commits 34c430d / bae35df):
- The BU selector is **no longer shown** to admin and owner in the quotes product search panel.
- Search results include products from **all accessible BUs** transparently.
- A single quote **may contain products from multiple business units**.
- Backend validation verifies each selected product against all BUs the user can access (not limited to one).
- `org_id` in the create quote POST is derived automatically for admin/owner from session context; the explicit org field is only required for support.
- The validity amount input auto-selects its value on focus so the first keystroke replaces the default instead of appending to it.

This behavior is identical for `admin` and `owner`. Owner rights are not degraded.

### Quotes — Replication (POST /api/v1/quotes/:id/replicate)

> **Feature**: "Replicar cotización" / "Replicate quote"  
> **UI button label** (was "Generar nuevo link"): now `asgn_btn_regen` → translated per locale.

**What replicate does:**
- Creates a new quote with a fresh, cryptographically-secure token (never reuses parent token).
- Copies all operational conditions from the parent: products, frozen prices, FOB/CIF base, profit/transport/tax config, discount, validity settings, max_visits, BU.
- Customer name (`customer_name`) is **required and cannot inherit from parent** — forces the user to identify the new customer explicitly.
- Company name and special conditions are optional; conditions default to parent's value unless overridden.
- Expiry is calculated relative to NOW using parent's `validity_amount` + `validity_unit` (not hardcoded 7 days).
- View count reset to 0 for the new quote.
- `parent_quote_id` set to original quote's `id` for traceability.
- Audit log event: `quote_replicated`.

**RBAC rules for replicate:**

| Role | Can replicate | Scope |
|---|---|---|
| `owner` | Yes | All business units |
| `admin` | Yes | Assigned business units only (`org_members`) |
| `support` | No | Blocked (403) |
| `supplier` | No | Blocked (403) |
| Unauthenticated | No | Blocked (401) |

**API routes (both supported):**
- `POST /api/v1/quotes/:id/replicate` (preferred, canonical endpoint)
- `POST /api/v1/assignments/:id/replicate` (alias, same handler)

**Payload:**
```json
{
  "customer_name": "Juan Pérez",
  "company_name": "Acme S.A. (optional)",
  "special_conditions": "optional override; omit to inherit from parent"
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "quote_id": 57,
  "parent_quote_id": 42,
  "public_url": "https://…/quote.php?t=…",
  "expires_at": "2026-05-13 10:00:00",
  "max_views": null,
  "status": "active"
}
```

**Error cases:**
- `400` / `422` — `customer_name` missing or empty.
- `403` — caller is not admin/owner, or parent quote is outside caller's org scope (IDOR protection).
- `404` — parent quote not found or soft-deleted.
- `422` — parent quote has no items.

## 5) Data Integrity and Migration Hardening

Problem addressed:
- Seed/migration upserts could overwrite existing organization names on duplicate slug.

Fix applied:
- changed from:
  - `ON DUPLICATE KEY UPDATE name = VALUES(name)`
- to no-op duplicate update:
  - `ON DUPLICATE KEY UPDATE id = id`

Files patched:
- `setup/migrate_business_units.sql`
- `setup/migrate_orgs.sql`

## 6) Organization Damage Audit

Added:
- `setup/audit_org_integrity.sql` (read-only diagnostics)
- `setup/AUDIT_ORG_INTEGRITY_README.md` (remediation guidance)

Audit covers:
- duplicate slugs/names
- canonical slug identity (`jshop`, `jbusiness`)
- suspicious names (`metales rpm`, etc.)
- orphan rows in org-linked tables
- membership distribution by role

## 7) Deployment Checklist

1. Backup database.
2. Run migration scripts in your standard order.
3. Run `setup/audit_org_integrity.sql` and export results.
4. Validate canonical organizations by `id + slug`.
5. Test login matrix:
   - owner/admin direct login
   - support 1 BU auto-select
   - support multiple BU picker + switch
6. Test API matrix:
   - `/api/v1/me` payload by role
   - invitations denied for support
   - users endpoint scoped correctly for support
7. Validate critical UI paths:
   - admin/products
   - admin/assignments (UI label: Cotizaciones / Quotes)
   - admin/index
   - invitations
8. Re-run audit queries after remediation if needed.

## 8) Known Validation Limits in This Workspace

- PHP CLI is not installed in this environment (`php` command unavailable), so runtime CLI lint/tests were not executed here.
- Static editor diagnostics for modified PHP files are clean.

## 9) Admin / Owner Feature Parity Policy

Whenever a new enhancement is applied to the `admin` role that enables behavior not previously available, the same change must be mirrored to `owner` without reducing any of owner's superior rights.

This ensures `owner ≥ admin` at all times in terms of feature capability.

---

## 10) Central Auth / RBAC / TenantScope Layer (May 2026)

### 10.1 Overview

A central authentication and authorization layer has been introduced.  Existing procedural helpers in `includes/auth.php` and `includes/org_scope.php` remain intact; three new files add OOP facades on top:

| File | Purpose |
|---|---|
| `includes/session.php` | Centralized session bootstrap with dynamic `secure` cookie flag |
| `includes/auth.php` (extended) | Auth procedural helpers + new `Auth` static class |
| `includes/RBAC.php` | Permission matrix + role-creation hierarchy |
| `includes/TenantScope.php` | OOP business-unit scope resolver |

### 10.2 Session Bootstrap — `includes/session.php`

Replaces the duplicated `session_set_cookie_params` + `session_start` block that was present in every entry point.

**Before (duplicated in each file):**
```php
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();
```

**After (single include):**
```php
require_once __DIR__ . '/includes/session.php';
```

**Changes applied:**
- `secure` flag is now **dynamic**: `true` when `$_SERVER['HTTPS']` is present and not `'off'`, `false` otherwise.  No manual change required when switching to HTTPS.
- `httponly = true` enforced globally.
- `samesite = Lax` enforced globally.
- Session started once via `PHP_SESSION_NONE` guard (safe to multi-include).
- All entry points (web + API + admin/api sub-endpoints) now use this file.

### 10.3 Auth Facade — `Auth` class in `includes/auth.php`

New static class providing a clean OOP API over the procedural session helpers.

```php
// Identity
Auth::user()       // returns full session context or null
Auth::id()         // int — user_id or 0
Auth::role()       // string — role or ''
Auth::roleRank()   // int — owner=4, admin=3, support=2, supplier=1, else 0
Auth::check()      // bool — true if fully logged in

// Guards
Auth::requireLogin()            // redirect to login if unauthenticated
Auth::requireRole(['owner'])    // redirect to home if role mismatch
Auth::requireAccess(['admin','owner'])  // combined guard, returns auth context

// Lifecycle
Auth::logout()           // destroys session
Auth::refreshUser()      // DB revalidation, destroys session if deactivated
Auth::regenerateSession()
Auth::clearRoleContext() // clears org/role keys without destroying session
```

**Existing procedural calls (`requireAuth()`, `requireRole()`, `isLoggedIn()`, etc.) continue to work unchanged.**

### 10.4 RBAC — `includes/RBAC.php`

Defines a canonical permission matrix for all roles.

```php
RBAC::can('users.list')           // bool — checks current user's role against matrix
RBAC::requirePermission('users.create')  // aborts with 403 if lacking permission
RBAC::canCreateRole('admin', 'owner')    // false — admin cannot create owner
RBAC::assertRoleHierarchy('admin', 'support')  // ok
RBAC::assertRoleHierarchy('support', 'supplier')  // 403
```

**Permission matrix (abbreviated):**

| Permission | Minimum rank | Minimum role |
|---|---|---|
| `users.list` | 2 | support |
| `users.create` | 3 | admin |
| `users.change_role` | 4 | owner |
| `invitations.create` | 3 | admin |
| `products.list` | 2 | support |
| `products.delete` | 3 | admin |
| `assignments.create` | 2 | support |
| `assignments.delete` | 3 | admin |
| `business_units.*` | 4 | owner |
| `contracts.review` | 3 | admin |

**Role-creation hierarchy:**

| Creator | Can create | Cannot create |
|---|---|---|
| owner | admin, support, supplier | — |
| admin | support, supplier | admin, owner |
| support | — | all |
| supplier | — | all |

*Excepción Admin/Owner: Admin cannot create other admins — owner exclusivity over the admin role is preserved.*

**Context detection:** `requirePermission()` and `assertRoleHierarchy()` detect API context via `HTTP_ACCEPT: application/json` and emit a JSON 403 response instead of an HTML redirect.

### 10.5 TenantScope — `includes/TenantScope.php`

Resolves the correct business-unit scope for the current user and provides query-building helpers.

```php
TenantScope::businessUnitsForCurrentUser()  // array of {id, name}
TenantScope::businessUnitIds()              // int[] — empty = owner (no restriction)
TenantScope::canAccessBusiness(int $id)     // bool
TenantScope::activeBusinessForSupport()     // int (0 for owner/admin)
TenantScope::applyToQuery(string $sql, string $col)  // appends scoped WHERE clause
TenantScope::requireBusinessAccess(int $id) // 403 if out of scope
```

**Scoping by role:**

| Role | `businessUnitIds()` | `applyToQuery()` behavior |
|---|---|---|
| owner | `[]` (empty) | no WHERE clause added |
| admin | all assigned org IDs | `AND org_id IN (1, 2, ...)` |
| support | `[active_org_id]` | `AND org_id = 5` |
| supplier | `[org_id]` | `AND org_id = 3` |

**Owner never uses `active_business_unit`.**  `businessUnitIds()` returning `[]` is the signal to callers that no restriction should be applied.

### 10.6 API — `requireApiAuth` naming fix

**Critical bug fixed:** `requireAuth()` was declared in both `includes/auth.php` (web guard, `void` return) and `api/v1/_helpers.php` (API guard, returns auth context array).  Including both in `api/v1/index.php` caused a PHP fatal redeclaration error on every API request.

**Fix:** renamed the API guard from `requireAuth` to `requireApiAuth` in `_helpers.php` and updated all 13 call sites across `api/v1/resources/*.php`.

**Before:**
```php
// _helpers.php
function requireAuth(array $roles = ['admin', 'owner']): array { ... }

// resource files
$auth = requireAuth(['admin', 'owner']);
```

**After:**
```php
// _helpers.php
function requireApiAuth(array $roles = ['admin', 'owner']): array { ... }

// resource files
$auth = requireApiAuth(['admin', 'owner']);
```

### 10.7 GET /api/v1/me — Scoping behavior

Returns different scopes per role:

| Role | `assigned_business_units` | `active_business_unit` |
|---|---|---|
| owner | all organizations | not returned |
| admin | all assigned orgs | not returned |
| support | all assigned orgs | the active org (org_id in session) |
| supplier | own org | own org |

Owner and admin sessions have `org_id = 0` in session — `active_business_unit` is intentionally absent because they operate globally and are not restricted to any single BU.

### 10.8 Owner / Admin Parity Confirmation

All changes in this layer adhere to the admin→owner parity rule:

- `Auth::requireRole(['admin'])` — owner is NEVER restricted to admin-only gates.
- `RBAC::can()` — any permission with rank ≤ 3 is automatically satisfied by owner (rank 4).
- `TenantScope::businessUnitIds()` — owner returns `[]` (no restriction), admin returns all assigned orgs.
- `TenantScope::canAccessBusiness()` — owner always returns `true`.
- Owner NEVER has `active_business_unit` in session.
- Owner is NEVER shown a BU selector.
- Owner retains all admin permissions plus exclusive `business_units.*` and `users.change_role` permissions.

### 10.9 Manual Test Checklist

**Owner:**
- [ ] Login as owner → lands on admin/products.php without BU selector
- [ ] `GET /api/v1/me` → `role: "owner"`, `active_business_unit` absent, all orgs in `assigned_business_units`
- [ ] Products list shows all products from all BUs
- [ ] Assignments create: no org picker shown; products from all BUs selectable

**Admin:**
- [ ] Login as admin → global session, no BU selector
- [ ] `GET /api/v1/me` → `role: "admin"`, `active_business_unit` absent, assigned BUs only
- [ ] Products list shows only products from assigned BUs
- [ ] Cannot access `GET /api/v1/business-units` → 403

**Support:**
- [ ] Login with 1 BU → auto-select, lands on products page
- [ ] Login with N BUs → org-picker shown, select one, session set
- [ ] `GET /api/v1/me` → `active_business_unit` present and matches session org_id
- [ ] Switch BU via `POST /api/v1/support/active-business` → session updated, new org_id verified

**Supplier:**
- [ ] Login → supplier scope only
- [ ] `GET /api/v1/me` → own org in `active_business_unit`

**Session isolation:**
- [ ] Login as owner, logout, login as support → no inherited context (org_id not carried over)
- [ ] Login as admin, logout, login as owner → owner session has org_id = 0, no BU selector

**Public quote (token-only):**
- [ ] Open quote.php?t=TOKEN while logged in as admin/owner → public view with no internal data
- [ ] No FOB/CIF prices, no supplier codes, no admin navigation visible
- [ ] Session of logged-in user is NOT modified by quote.php visit

---

## 11) Web vs API Routing Separation (May 2026)

The application uses **two completely independent routing systems** that coexist without interference.

### 11.1 Architecture overview

```
Incoming request
       │
       ▼
Apache / .htaccess (project root)
       │
       ├── Path contains /api/  ──────────────────────────────────────▶ api/v1/.htaccess
       │                                                                        │
       │                                                               api/v1/index.php
       │                                                               (API front controller)
       │                                                               requireApiAuth()
       │
       ├── File or directory exists  ────────────────────────────────▶ serve file directly
       │   (legacy .php URLs)                                           (backwards compat)
       │
       └── No match  ───────────────────────────────────────────────▶ router.php
                                                                       (web front controller)
                                                                       Router::dispatch()
                                                                       requireAuth() / requireRole()
```

### 11.2 Web router (`router.php` + `includes/Router.php`)

| Aspect | Detail |
|--------|--------|
| **Entry point** | `router.php` (project root) |
| **Triggered by** | Root `.htaccess` mod_rewrite — only when neither a file nor a directory matches the request path, and the path does not contain `/api/` |
| **Route map** | `config/routes.php` — central web route definitions |
| **Auth layer** | `requireAuth()` / `requireRole()` — procedural web guards from `includes/auth.php` |
| **Token routes** | `quote.php`, `enroll.php` — marked `tokenRoute=true`; auth checks skipped entirely |
| **Backwards compat** | Direct `.php` file access always works — rewrite only fires when the file does not exist |

**Root `.htaccess` rewrite block:**
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI}      !/api/ [NC]
    RewriteRule ^                   router.php [QSA,L]
</IfModule>
```

The three conditions must ALL be true for the rewrite to fire:
1. Request path is not an existing file
2. Request path is not an existing directory
3. Request URI does not contain `/api/` (case-insensitive)

### 11.3 API router (`api/v1/index.php`)

| Aspect | Detail |
|--------|--------|
| **Entry point** | `api/v1/index.php` |
| **Triggered by** | `api/v1/.htaccess` — routes all requests inside `api/v1/` to `index.php` |
| **Auth layer** | `requireApiAuth()` in `api/v1/_helpers.php` — returns auth context array, emits JSON 403 on failure |
| **Response format** | Always `application/json` |
| **Session** | Reads PHP session but never initiates redirects — always returns JSON errors |

### 11.4 Why they are fully independent

- **No shared front controller**: the web router and the API router never call each other.
- **No shared auth function names**: the web guard is `requireAuth()` (void, redirects on failure); the API guard is `requireApiAuth()` (returns array, JSON 403 on failure). They have different signatures and behaviors.
- **No shared `.htaccess`**: the root `.htaccess` exclusion condition (`!/api/`) ensures API paths never reach `router.php`. The `api/v1/.htaccess` handles internal API routing.
- **No state sharing**: `router.php` reads and writes PHP session; `api/v1/index.php` reads session for auth but never modifies it during request handling.

### 11.5 Adding routes

**New web page (clean URL):**
1. Create the PHP file (e.g., `admin/new_page.php`) with its own `requireAuth()` / `requireRole()` guards.
2. Register the clean URL in `config/routes.php`:
   ```php
   Router::get('/admin/new-page', __DIR__ . '/../admin/new_page.php', ['owner', 'admin']);
   ```
3. The legacy URL `/login/admin/new_page.php` continues working unchanged.

**New API endpoint:**
1. Create `api/v1/resources/new_resource.php` using `requireApiAuth()`.
2. Add a route case in `api/v1/index.php`.
3. No changes to web router or root `.htaccess` needed.

### 11.6 Security properties

| Property | Web router | API router |
|----------|-----------|------------|
| Auth failure | HTTP 302 redirect to login page | HTTP 403 JSON response |
| Token-only routes | `tokenRoute=true` skips all session checks | N/A — every API endpoint requires auth |
| CSRF | Legacy pages enforce CSRF on POST forms | API uses `requireApiAuth()` token validation |
| Session required | Yes (except public/token routes) | Yes (reads session; no session creation) |

---

## 12. Session & Cookie Security Reference (May 2026)

### 12.1 Session lifecycle

```
Browser request
  │
  ▼
includes/session.php          ← session_set_cookie_params + session_start (once)
  │
  ▼
requireAuth() / requireApiAuth()
  ├── sendNoCacheHeaders()     ← Cache-Control: no-store / Pragma / Expires: 0
  ├── absolute timeout check   ← 8 h from session_start_time
  ├── idle timeout check       ← 30 min from last_activity
  └── DB revalidation          ← is_active; support org validity
```

### 12.2 Cookie attributes

| Attribute | Value | Reason |
|-----------|-------|--------|
| `HttpOnly` | `true` | JS cannot read the session cookie — XSS cannot steal it |
| `SameSite` | `Lax` | CSRF mitigation without breaking redirect-based flows |
| `Secure` | Auto (HTTPS present) | HTTPS-only in production; plain HTTP in local dev |
| `lifetime` | `0` | No persistent cookie — expires when browser closes |
| `path` | `/` | All app paths share the same cookie |

### 12.3 API token vs session authentication

This application uses **session-cookie authentication exclusively**. There is no API token (Bearer) system.

| Question | Answer |
|----------|--------|
| Does the API support `Authorization: Bearer`? | No. The API reads the PHP session cookie only. |
| Can a public quote token authenticate API calls? | No. Quote tokens are short-lived DB lookup tokens, not auth credentials. |
| Can session auth elevate to higher API permissions? | No. `requireApiAuth(roles)` validates `$_SESSION['role']` against the explicit allow-list. |
| Are session and API authentication independent? | They share the same PHP session mechanism. There is no independent token layer. |
| What happens on session expiry during an API call? | `requireApiAuth()` returns HTTP 401 `{"error":{"code":"UNAUTHORIZED",...}}`. |

Integrators calling the API from a non-browser client must first authenticate via the web login flow to establish a session, then forward the `PHPSESSID` cookie on subsequent API requests. There is no stateless API token endpoint.

### 12.4 Public quote isolation

`GET /login/quote.php?t={token}` is the only truly unauthenticated page. Its isolation contract:

1. Session is started for `lang` persistence only.
2. `session_write_close()` is called before any DB or rendering logic — the session is no longer modifiable after that point.
3. No auth session keys (`logged_in`, `user_id`, `role`, etc.) are read by the page.
4. The token in `?t=` is the sole credential — hashed via SHA-256 and looked up in `quote_assignments`.
5. An authenticated admin/owner opening a quote link: their session is untouched; the quote renders with public-only data.

### 12.5 Session timeout constants

Defined in `includes/auth.php`:

| Constant | Value | Scope |
|----------|-------|-------|
| `IDLE_TIMEOUT` | 1800 s (30 min) | Web + API |
| `ABSOLUTE_TIMEOUT` | 28800 s (8 h) | Web + API |
| `ORG_PICK_TIMEOUT` | 300 s (5 min) | Org-picker pending session only |
| `LOCKOUT_SECS` | 3600 s (1 h) | Login brute-force lockout |

