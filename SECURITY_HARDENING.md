# Security Hardening — Implementation Record

> **Status**: Step 4 complete — all critical findings closed.  
> **Last updated**: 2025 (see git log for exact date)

---

## Overview

This document records the security hardening work performed on the PHP web application. It covers findings from the internal audit, the fixes applied, and the reasoning behind each decision.

---

## 1. XSS — Output Escaping

### 1.1 `$initial` Variable — Supplier Views (FIXED)

**Finding**: In five supplier view files, the `$initial` variable (the first letter of the username displayed as an avatar) was derived from the raw session value (`$_SESSION['username']`) instead of the already-HTML-escaped `$username` variable. If a username started with `<`, the literal `<` would be injected into the HTML document.

**Affected files**:
- `supplier/products.php`
- `supplier/add_product.php`
- `supplier/profile.php`
- `supplier/product_view.php`
- `supplier/documents.php`

**Fix applied**: Changed `strtoupper(substr((string)($_SESSION['username'] ?? '?'), 0, 1))` → `strtoupper(substr($username, 0, 1))` in all five files, where `$username` is already `htmlspecialchars`-escaped.

**Pattern confirmed safe in admin views**: `admin/users.php`, `admin/products.php`, `admin/product_view.php`, `admin/assignments.php`, `owner/business_units.php`, `owner/users.php`, `supplier/summary.php`, `invitations.php` — all derived `$initial` from the pre-escaped `$username`.

---

### 1.2 PHP-in-JS — `json_encode` Without HTML-Safe Flags (FIXED)

**Finding**: Multiple files embedded PHP values into `<script>` blocks using `json_encode()` without the `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` flags. A database string containing `</script>` would terminate the script context and allow injected HTML/JS to execute.

**Affected locations**:

| File | Location | Value |
|------|----------|-------|
| `includes/tabs.php` | Line with `$roleJs = json_encode(...)` | Role string from whitelist |
| `quote.php` | `var quoteConfig = <?= json_encode($jsConfig, ...) ?>` | Config object with DB strings |
| `admin/assignments.php` | i18n block (~17 calls) | Translation strings |
| `admin/assignments.php` | `prepareFormSubmit()` alert calls | Translation strings |
| `admin/assignments.php` | `updateValidityPreview()` | Translation string |
| `admin/assignments.php` | `showCopied()` | Translation string |
| `admin/assignments.php` | onclick `htmlspecialchars(json_encode(...))` | `special_conditions` DB field |

**Fix applied**:

1. **`includes/tabs.php`**: Added `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` to the `json_encode` call.

2. **`quote.php`**: Added the same four HEX flags to the `json_encode` call on `$jsConfig`.

3. **`admin/assignments.php`**: Defined a `$jse` closure at the view-helpers section:
   ```php
   $jse = fn($v) => json_encode($v,
       JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
   ```
   All `json_encode(t('...'))` calls in the `<script>` block replaced with `$jse(t('...'))`.

4. **`admin/assignments.php` onclick**: Added the HEX flags inside the existing `htmlspecialchars(json_encode(...))` pattern for defense-in-depth.

**Why these flags**: `JSON_HEX_TAG` encodes `<` and `>` as `\u003C`/`\u003E`, preventing `</script>` from terminating the script context. The other flags encode `&`, `'`, `"` to avoid attribute injection vectors.

---

## 2. API Response Format Standardization

### 2.1 `api/v1/_helpers.php` (FIXED)

**Finding**: The `jsonError()` function returned a flat string under `error`:
```json
{"success": false, "error": "Unauthorized"}
```
The spec requires a structured error object:
```json
{"success": false, "error": {"code": "UNAUTHORIZED", "message": "Unauthorized"}}
```
Similarly, `jsonValidationError()` used `"errors"` as a top-level key rather than nesting under `"error"`.

**Fix applied**: Updated three functions in `api/v1/_helpers.php`:

- `jsonError()`: Now returns `{"success": false, "error": {"code": "SNAKE_CODE", "message": "..."}}`
- `jsonValidationError()`: Now returns `{"success": false, "error": {"code": "VALIDATION_ERROR", "message": "...", "fields": {...}}}`
- `jsonServerError()`: Now returns `{"success": false, "error": {"code": "INTERNAL_ERROR", "message": "An internal error occurred."}}`

**HTTP code → error code mapping**:

| HTTP | Error Code |
|------|-----------|
| 400 | `BAD_REQUEST` |
| 401 | `UNAUTHORIZED` |
| 403 | `FORBIDDEN` |
| 404 | `NOT_FOUND` |
| 405 | `METHOD_NOT_ALLOWED` |
| 422 | `VALIDATION_ERROR` |
| 429 | `TOO_MANY_REQUESTS` |
| 500 | `INTERNAL_ERROR` |
| other | `ERROR` |

**Backward compatibility**: All internal JS consumers tested — none parse `data.error` as a string. The `data.success` boolean is the primary discriminator.

---

### 2.2 `admin/api/product_search.php` and `admin/api/product_detail.php` (FIXED)

**Finding**: Both admin AJAX endpoints used manual session checks and raw `echo json_encode([...])` responses, bypassing the standard `_helpers.php` response functions.

**Fix applied**: Both files now:
1. `require_once` the `api/v1/_helpers.php` helpers.
2. Replace manual `$_SESSION` checks with `requireApiAuth(['admin', 'owner'])`.
3. Replace raw `echo json_encode(...)` error responses with `jsonError(...)`.
4. Replace raw success responses with `jsonOk(...)`.

**Response structure change** (product_search):
- Before: `{"success": true, "total": ..., "items": [...]}`  
- After: `{"success": true, "total": ..., "items": [...]}` — **unchanged**, `jsonOk()` merges into the top level via `array_merge(['success' => true], $payload)`, maintaining compatibility with the assignments.php AJAX consumer.

---

## 3. Validation — Entity Validators

**Finding**: Input validation was fragmented across individual route files. Each file re-implemented field length checks independently, risking drift from Validator.php constants.

**Fix applied**: Created `includes/validators/` directory with four entity validator classes:

| File | Class | Entity |
|------|-------|--------|
| `includes/validators/ProductValidator.php` | `ProductValidator` | `supplier_products` |
| `includes/validators/QuoteValidator.php` | `QuoteValidator` | `quote_assignments` |
| `includes/validators/UserValidator.php` | `UserValidator` | `users` |
| `includes/validators/BusinessUnitValidator.php` | `BusinessUnitValidator` | `organizations` |

Each class:
- Imports `Validator.php` and `Input.php`
- Exposes a `validate(array $data): array` method (or `validateCreate`/`validateUpdate` for UserValidator)
- Returns a `field => message` error map compatible with `jsonValidationError($errors)`
- Reads limits exclusively from `Validator::maxLen()` and `Validator::*` constants — no hardcoded numbers

**Usage pattern**:
```php
require_once __DIR__ . '/includes/validators/ProductValidator.php';
$errors = ProductValidator::validate($body);
if ($errors) {
    jsonValidationError($errors);
}
```

---

## 4. File Serving — Supplier IDOR Check

### `storage-file.php` (FIXED)

**Finding**: Authenticated suppliers could request the image path of any product (`uploads/products/{product_id}/...`), regardless of whether the product belonged to them. This is an Insecure Direct Object Reference (IDOR) vulnerability.

**Risk level**: Medium (product catalog images are not personally identifiable, but suppliers should not be able to enumerate or access competitors' product imagery).

**Fix applied**: Added an IDOR check immediately after the session-authenticated access grant. For the `supplier` role only:

1. Parse the product ID from the normalized path using `preg_match('#^uploads/products/(\d+)/#', ...)`.
2. Query `supplier_products WHERE id = ? AND supplier_id = ?` to verify ownership.
3. Only grant access if the match is found.
4. If the path doesn't match the expected format, access is denied.

**Admin/Owner parity maintained**: Admin and owner roles are unaffected — they retain full access to all product images.

**Token-based access unaffected**: Public quote tokens already scope their access via the `quote_assignments` / `product_assignments` join — no change needed.

---

## 5. Files Changed Summary

| File | Change |
|------|--------|
| `supplier/products.php` | `$initial` from escaped `$username` |
| `supplier/add_product.php` | Same |
| `supplier/profile.php` | Same |
| `supplier/product_view.php` | Same |
| `supplier/documents.php` | Same |
| `includes/tabs.php` | `json_encode` HEX flags |
| `quote.php` | `json_encode` HEX flags |
| `admin/assignments.php` | `$jse` helper; all JS `json_encode` calls updated |
| `api/v1/_helpers.php` | Structured error object in `jsonError`, `jsonValidationError`, `jsonServerError` |
| `admin/api/product_detail.php` | Uses `requireApiAuth`, `jsonOk`, `jsonError` |
| `admin/api/product_search.php` | Same |
| `includes/validators/ProductValidator.php` | **New** — entity validator |
| `includes/validators/QuoteValidator.php` | **New** — entity validator |
| `includes/validators/UserValidator.php` | **New** — entity validator |
| `includes/validators/BusinessUnitValidator.php` | **New** — entity validator |
| `storage-file.php` | Supplier IDOR check added |

---

## 6. What Was Confirmed Safe (No Changes)

- **All admin views**: `$initial` derived from pre-escaped `$username` ✓
- **`owner/business_units.php`**, **`owner/users.php`**: Same ✓
- **`supplier/summary.php`**: `$username = htmlspecialchars($profile['username'] ...)` ✓
- **`invitations.php`**: All outputs use `htmlspecialchars` ✓
- **All SQL**: PDO prepared statements used throughout — no string concatenation of user input ✓
- **CSRF**: All state-changing forms use `csrfField()` / `csrfValidate()` ✓
- **Session**: Centralized in `includes/session.php` with `session_regenerate_id()` on login ✓

---

## 7. OWASP Top 10 Coverage

| Risk | Status |
|------|--------|
| A01 Broken Access Control | Hardened — supplier IDOR fixed; RBAC unchanged for admin/owner |
| A02 Cryptographic Failures | N/A for this pass (passwords use bcrypt; tokens use SHA-256) |
| A03 Injection | All SQL via prepared statements; no new risks introduced |
| A04 Insecure Design | Entity validators centralize field rules against Validator.php constants |
| A05 Security Misconfiguration | Security headers present on all endpoints |
| A06 Vulnerable Components | N/A for this pass |
| A07 Auth/Session Failures | Session regeneration on login; token validation enforced |
| A08 Software/Data Integrity | API response format standardized; no new trust issues |
| A09 Logging/Monitoring | `jsonServerError` logs context via `error_log`; no change |
| A10 SSRF | N/A (no outbound HTTP) |

---

## 8. Session, Cookie & Context Isolation Hardening (May 2026)

### 8.1 Session Fixation Protection

| Point | Mechanism | File |
|-------|-----------|------|
| Login — new session ID | `session_regenerate_id(true)` inside every `create*Session()` call | `includes/auth.php` |
| Pending → full session promotion | `session_regenerate_id(true)` inside `selectOrg()` | `includes/auth.php` |
| BU switch mid-session | `session_regenerate_id(true)` | `switch_org.php` |
| Logout | `$_SESSION = []; setcookie(..., time()-42000, ...); session_destroy()` | `includes/auth.php` — `destroySession()` |
| Logout response header | `Clear-Site-Data: "cache", "cookies", "storage"` | `logout.php` |

Session ID is **never reused** across login events. The old session file is invalidated by `session_regenerate_id(true)` (`delete_old_session = true`).

### 8.2 Role Context Isolation

On every session creation all prior `$_SESSION` data is explicitly wiped (`$_SESSION = []`) before writing the new role context. Keys set per role:

| Key | owner | admin | support | supplier |
|-----|-------|-------|---------|----------|
| `logged_in` | ✓ | ✓ | ✓ | ✓ |
| `user_id` | ✓ | ✓ | ✓ | ✓ |
| `role` | `owner` | `admin` | `support` | `supplier` |
| `org_id` | `0` (global) | `0` (global) | Active BU id | Org id |
| `org_slug/name` | empty | empty | Active BU | Org name |
| `support_orgs` | — | — | Full list | — |
| `session_start_time` | ✓ | ✓ | ✓ | ✓ |
| `last_activity` | ✓ | ✓ | ✓ | ✓ |

**Support active-BU revalidation**: On every `requireAuth()` call, if role = `support`, the live `org_members` table is queried to refresh `support_orgs` and validate `org_id`. If the active BU is revoked mid-session, the session automatically falls back to a valid assigned BU (or terminates if none remain).

**Owner/admin no BU selector**: `createGlobalSession()` sets `org_id = 0`. TenantScope returns the full org list for owner and all assigned orgs for admin without reading `org_id` from session.

### 8.3 Cookie Hardening

Configured in `includes/session.php` via `session_set_cookie_params()`:

| Attribute | Value | Notes |
|-----------|-------|-------|
| `lifetime` | `0` | Browser-session cookie — no persistent cookie written |
| `path` | `/` | Entire application |
| `secure` | Auto-detected from `$_SERVER['HTTPS']` | `true` in production (HTTPS); `false` in local dev |
| `httponly` | `true` | JavaScript cannot read the session cookie |
| `samesite` | `Lax` | CSRF mitigation; Strict avoided to not break OAuth/redirect flows |

**Dev vs prod**: `secure=false` in local dev is intentional and safe — session data never leaves localhost. Before cloud deployment, ensure `HTTPS` is enabled at the proxy/load-balancer level so `secure=true` is auto-activated without code changes.

No role, BU ID, or any business data is stored in cookies. The session cookie carries only the PHP session ID.

---

## 9) Temporary Password Reset & Forced Password Change (June 2026)

### 9.1 Threat model

Administrators must be able to unlock access for users who have forgotten their password, without exposing the existing password or creating a persistent back-door.

### 9.2 Security controls

| Control | Implementation |
|---------|---------------|
| Temp password generation | `Validator::generateTemporaryPassword()` — 36 chars, CSPRNG via `random_int()`, no ambiguous chars |
| Storage | Only bcrypt hash (cost 12) stored; plaintext is discarded after hashing |
| Delivery | Sent to user's registered email only; never logged, never in HTTP response body (production) |
| Expiry | 24 hours — `temporary_password_expires_at` enforced at login |
| Post-expiry | `AUTH_TEMP_EXPIRED` returned → login blocked → user must contact admin |
| Forced change | `must_change_password = 1` → redirect to `change_password.php` on every request until changed |
| New password policy | 12–128 chars, upper + lower + digit, via `Validator::validatePassword()` |
| Audit trail | `password_reset_requested`, `temporary_password_generated`, `forced_password_change_completed` |
| Session hardening | `session_regenerate_id(true)` after successful password change |
| Dev-mode safety | `dev_temp_password` key only present in API/UI response when `MAIL_USER` is the placeholder string |

### 9.3 RBAC constraints

- Support and supplier roles **cannot** initiate a password reset.
- Admin can only reset support/supplier users **within their assigned business units**.
- Owner can reset admin/support/supplier users globally.
- No role can reset another user of the same or higher rank.
- Self-reset via this mechanism is blocked (admin/owner cannot reset their own account here).

### 9.4 Redirect loop prevention

`change_password.php` uses `isLoggedIn()` (not `requireAuth()`) to avoid triggering `requireAuth()`'s own redirect to `change_password.php`. `requireAuth()` itself checks `basename($_SERVER['SCRIPT_FILENAME']) !== 'change_password.php'` before redirecting.

### 9.5 Files modified / created

| File | Change |
|------|--------|
| `setup/migrate_password_reset.sql` | DB migration: adds `must_change_password`, `temporary_password_created_at`, `temporary_password_expires_at` columns + index |
| `includes/Validator.php` | Added `PASSWORD_MIN_LEN`, `PASSWORD_MAX_LEN`, `validatePassword()`, `generateTemporaryPassword()` |
| `includes/mailer.php` | Added `sendPasswordResetEmail()` + multilingual templates |
| `includes/auth.php` | `AUTH_TEMP_EXPIRED` constant; expiry check in `attemptLogin()`; must_change_password propagated to session |
| `index.php` | `AUTH_TEMP_EXPIRED` error display; must_change_password redirect |
| `org-picker.php` | must_change_password redirect after BU selection |
| `change_password.php` | **New file** — forced password change page |
| `admin/users.php` | Reset password action + button (support/supplier scope) |
| `owner/users.php` | Reset password action + button (admin/support/supplier scope) |
| `api/v1/resources/users.php` | PATCH action `reset-password` |
| `api/v1/resources/auth.php` | **New file** — `POST /api/v1/auth/change-required-password` |
| `api/v1/index.php` | Route `case 'auth':` added |
| `enroll.php` | Password validation upgraded from `strlen < 8` to `Validator::validatePassword()` |
| `lang/es.php`, `lang/en.php`, `lang/zh.php` | New keys for reset flow, forced change page, and policy errors |

### 8.4 Public Token Context Isolation (`quote.php`)

The public quotation page operates in **token-only mode**:

1. Session is started by `includes/session.php` solely to read `$_SESSION['lang']`.
2. `initLang()` normalises/persists the language preference.
3. `session_write_close()` is called **immediately** after lang is read — the session file is written and the lock released. No further session writes occur.
4. All page logic below that point is pure token-based DB lookup — no `$_SESSION` auth key is ever read.

Effect when an authenticated user opens a quote link:
- Their session on the server is **not modified** in any way.
- The quote renders with the token data only — no private navigation, no FOB/CIF prices, no `internal_product_code`, no `supplier_product_code`, no org IDs.
- Returning to an admin page after viewing a quote works normally.

### 8.5 Cache-Control Headers

All sensitive responses now emit the full three-directive no-cache set:

```
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
Expires: 0
```

**Where they are sent:**

| Scope | Mechanism |
|-------|-----------|
| All fully-authenticated pages | `requireAuth()` → `sendNoCacheHeaders()` (automatic) |
| Org-picker (pending session) | `requirePendingAuth()` → `sendNoCacheHeaders()` (automatic) |
| All API v1 authenticated endpoints | `requireApiAuth()` (automatic) |
| Login page | Direct `header()` calls in `index.php` |
| Enrollment page | Direct `header()` calls in `enroll.php` |
| Public quote page | Direct `header()` calls in `quote.php` |
| Logout page | Direct `header()` calls in `logout.php` + `Clear-Site-Data` |
| Org-switch | Direct `header()` calls in `switch_org.php` |

### 8.6 Session Timeout

Two independent expiry mechanisms operate on every authenticated request:

| Mechanism | Limit | Trigger | Response |
|-----------|-------|---------|----------|
| **Idle timeout** | 30 min (`IDLE_TIMEOUT`) | `time() - last_activity > 1800` | `destroySession()` + redirect to `?reason=timeout` |
| **Absolute ceiling** | 8 hours (`ABSOLUTE_TIMEOUT`) | `time() - session_start_time > 28800` | Same |

Both checks run in `requireAuth()` (web) and inline in `requireApiAuth()` (API). Public token-only pages (`quote.php`) are not affected.

`session_start_time` is written to `$_SESSION` by every session-creation function (`createSession`, `createGlobalSession`, `selectOrg`) and by `switch_org.php`.

### 8.7 API Session / Token Separation

| Concern | Design |
|---------|--------|
| API authentication mechanism | Cookie-based PHP session (same session started by `session.php`) |
| Bearer token support | **Not implemented** — the API does not accept `Authorization: Bearer` headers |
| Public quote API (`/api/v1/public/quote?t=TOKEN`) | Token-only, no session required or read |
| Session vs API token | There is no separate API token system. All API calls use the same session cookie as the web UI. |
| Session cannot elevate API token | N/A — there is no API token to elevate |
| Public links ≠ API tokens | Public quote links are short-lived DB tokens (SHA-256 hash lookup), not API tokens |

This separation must be documented in the API manual for integrators.

### 8.8 Cross-Role Test Matrix

| # | Scenario | Expected Result | Pass? |
|---|----------|-----------------|-------|
| 1 | Login owner → logout → login support, same browser | Support gets fresh session, owner context gone | Manual ✓ |
| 2 | Login admin → open public quote link | Quote renders token-only; admin session untouched | Manual ✓ |
| 3 | Login support BU-A → switch to BU-B | Session regenerated, org_id updated, BU-A context cleared | Manual ✓ |
| 4 | Login supplier → access `/login/admin/products.php` | `requireRole()` redirects to supplier home | Manual ✓ |
| 5 | Login admin → logout (incomplete sim) → login owner | Full destroySession on logout; owner gets clean session | Manual ✓ |
| 6 | Open public quote in new tab while admin logged in | Quote tab: token-only view; admin tab: unaffected | Manual ✓ |

### 8.9 Admin → Owner Parity Check

| Behavior | Admin | Owner | Parity |
|----------|-------|-------|--------|
| Global session (no BU requirement) | ✓ | ✓ | ✓ Equal |
| No BU selector shown | ✓ | ✓ | ✓ Equal |
| Idle + absolute timeout | ✓ | ✓ | ✓ Equal |
| No-cache headers | ✓ | ✓ | ✓ Equal |
| Public quote isolation | Unaffected | Unaffected | ✓ Equal |
| Owner-only: BU management | — | ✓ | Expected difference |
| Owner-only: create admin users | — | ✓ | Expected difference |

Owner retains all admin capabilities and adds BU-management exclusives. No admin capability was removed or degraded.

### 8.10 Files Modified in This Pass

| File | Change |
|------|--------|
| `includes/auth.php` | Added `ABSOLUTE_TIMEOUT` constant; `sendNoCacheHeaders()` helper; `requireAuth()` now calls `sendNoCacheHeaders()` + absolute timeout check; `requirePendingAuth()` now calls `sendNoCacheHeaders()`; all `create*Session()` functions write `session_start_time` |
| `api/v1/_helpers.php` | `requireApiAuth()` now enforces idle timeout, absolute timeout, and no-cache headers |
| `quote.php` | Added `Pragma` + `Expires` headers; added explicit session isolation block with `session_write_close()` |
| `index.php` | Added `Pragma: no-cache` + `Expires: 0` headers |
| `enroll.php` | Added `Pragma: no-cache` + `Expires: 0` headers |
| `switch_org.php` | Added `Pragma: no-cache` + `Expires: 0` headers; writes `session_start_time` on BU switch |
| `SECURITY_HARDENING.md` | This section |
| `RBAC_API_MANUAL.md` | Session/API token separation section added |

---

## 10. Authentication & Session Gap Closure (June 2026)

This section records the final pass of security hardening, closing remaining gaps identified in the internal audit for authentication, session continuity, password management, and API access control.

### 10.1 API must_change_password Enforcement

**Gap**: API calls could succeed even when `must_change_password = 1` was set in session, bypassing the forced password-change requirement.

**Fix**: `requireApiAuth()` in `api/v1/_helpers.php` now checks the flag before granting access.

```php
if (!empty($_SESSION['must_change_password'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    if (!str_ends_with(rtrim($uri, '/'), '/auth/change-required-password')) {
        jsonError('You must change your password before performing this action.', 403, 'PASSWORD_CHANGE_REQUIRED');
    }
}
```

- All routes return **HTTP 403** / `PASSWORD_CHANGE_REQUIRED` until the user complies.
- `POST /api/v1/auth/change-required-password` is explicitly allowed through to satisfy the requirement programmatically.
- **OWASP A01 (Broken Access Control)**: forces clients through the password-change gate regardless of role.

### 10.2 switch_org.php — must_change_password Continuity

**Gap**: when a support user with `must_change_password = 1` switched business units, the session rebuild in `switch_org.php` cleared the flag, allowing the user to bypass the forced-change page.

**Fix**: the flag is saved before the rebuild and restored immediately after:

```php
$mustChangePassword = (int) ($_SESSION['must_change_password'] ?? 0);
// … selectOrg() / session rebuild …
$_SESSION['must_change_password'] = $mustChangePassword;
if ($mustChangePassword) {
    header('Location: /login/change_password.php');
    exit;
}
```

The same pattern applies to the fallback manual-rebuild path in `switch_org.php`.

### 10.3 Dedicated POST /api/v1/users/:id/reset-password

A dedicated REST endpoint was added as a semantically clear alternative to the PATCH action form:

```
POST /api/v1/users/42/reset-password
```

No request body is required. Behaviour is identical to `PATCH /api/v1/users/42 {action: "reset-password"}`: generates a 36-char CSPRNG temp password, stores the bcrypt hash, emails the user, sets `must_change_password = 1`, and writes audit log entries.

**Files changed**:
- `api/v1/index.php` — `case 'users':` intercepts POST with `$sub === 'reset-password'` before the generic handler.
- `api/v1/resources/users.php` — new `handleUserResetPassword(int $id)` function.

### 10.4 Audit Events Added

| Event | Severity | Trigger |
|-------|----------|---------|
| `invitation_expired` | info | Lazy expiry detected during `enroll.php` enrollment — `loadInvitation()` |
| `failed_temp_password_expired` | warning | Login attempt with an expired temp password — `index.php` |
| `forbidden_user_management_attempt` | warning | Admin/owner trying a forbidden password reset — `admin/users.php`, `owner/users.php`, `api/v1/resources/users.php` |
| `support_business_unit_selected` | info | Support user switches active BU — `switch_org.php` |

Events are written via `auditLog()` in `includes/audit.php`. Audit writes are best-effort and never abort the main request.

### 10.5 Files Modified in This Pass

| File | Change |
|------|--------|
| `api/v1/_helpers.php` | `requireApiAuth()` — added `must_change_password` guard with `PASSWORD_CHANGE_REQUIRED` error |
| `api/v1/index.php` | `case 'users':` — intercepts POST `/users/:id/reset-password` |
| `api/v1/resources/users.php` | Added `handleUserResetPassword()` function; updated doc comment |
| `switch_org.php` | Preserves `must_change_password` across session rebuild; redirects to change_password.php if set; adds `support_business_unit_selected` audit |
| `enroll.php` | Adds `invitation_expired` audit log on lazy expiry |
| `index.php` | Adds `failed_temp_password_expired` audit log on `AUTH_TEMP_EXPIRED` |
| `admin/users.php` | Adds `forbidden_user_management_attempt` audit on disallowed reset |
| `owner/users.php` | Same |
| `RBAC_API_MANUAL.md` | Section 13 — gap closure documentation |
| `SECURITY_HARDENING.md` | This section |

### 10.6 OWASP Top 10 Coverage Update

| Risk | Status After This Pass |
|------|----------------------|
| A01 Broken Access Control | `must_change_password` enforced at API layer; forbidden-reset attempts audited |
| A07 Auth/Session Failures | `must_change_password` flag preserved through BU switch; forced change cannot be bypassed |
| A09 Logging/Monitoring | Four new audit events cover previously silent security-relevant actions |

---

## 11. Logging & Secret Handling (June 2026)

### 11.1 Threat Model

Log files are a common exfiltration vector for credentials. Temporary passwords, verification codes, and enrollment tokens written to `logs/mail.log` in plaintext would be readable by anyone with filesystem access to the server (OWASP A09 — Logging and Monitoring Failures). This section documents controls that prevent secrets appearing in logs in production environments.

### 11.2 APP_ENV — Formal Environment Awareness

A formal `APP_ENV` variable now controls all environment-sensitive behaviour. Previously, dev/prod detection was implicit via SMTP placeholder detection; it is now explicit and independently configurable.

| Value      | Log detail       | Secrets in logs | Dev UI features |
|------------|------------------|-----------------|-----------------|
| `dev`      | Full (`[DEV ONLY]` marker) | Yes (intentional) | Yes |
| `staging`  | Reduced          | No              | No              |
| `prod`     | Minimal metadata only | Never      | No              |

**Default**: `prod` — safe fallback when variable is not set.

**Configuration options:**

```apache
# Apache / MAMP .htaccess
SetEnv APP_ENV dev
```

```ini
# php.ini (MAMP PHP settings)
env[APP_ENV] = dev
```

### 11.3 New Helper Classes

| File | Class | Purpose |
|------|-------|---------|
| `includes/AppConfig.php` | `AppConfig` | `env()`, `isDev()`, `isStaging()`, `isProd()` — single source of truth for environment |
| `includes/SafeLogger.php` | `SafeLogger` | `info()`, `error()`, `debug()`, `secureEvent()`, `maskEmail()`, `maskToken()` — environment-aware structured logging |

`SafeLogger::debug()` is silenced in `prod`. `SafeLogger::secureEvent()` accepts a `$safe` array (always logged) and a `$devOnly` array (logged only in `dev`).

### 11.4 Mail Log Sanitisation (`includes/mailer.php`)

All four mail-fallback log functions now check `AppConfig::isProd()` before writing body content:

| Function | PROD output | DEV output |
|----------|-------------|------------|
| `_writeResetLog()` | `event=password_reset_notification_attempted to=u***@example.com` | Full body including temporary password — prefixed `[DEV ONLY]` |
| `writeMailLog()` | `event=verification_email_fallback to=u***@example.com` | Full body including verification code — prefixed `[DEV ONLY]` |
| `writeInviteLog()` | `event=invitation_sent to=u***@example.com` | Full body including enrollment link/token — prefixed `[DEV ONLY]` |
| `writeQuoteShareLog()` | `event=quote_share_fallback to=u***@example.com` | Full body including quote link — prefixed `[DEV ONLY]` |

`writeErrorLog()` truncates SMTP error messages to 120 characters in `prod` to avoid leaking server configuration details.

### 11.5 Temporary Password UI Gate

The `dev_temp_password` key in the `sendPasswordResetEmail()` return value is **only present when `AppConfig::isDev()` is true**. The UI block that renders the temporary password is gated on the same check:

```php
<?php if ($devTempPassword !== null && AppConfig::isDev()): ?>
```

In `prod` (or `staging`), the temporary password is never returned in any PHP response or rendered in the browser.

### 11.6 Files Changed

| File | Change |
|------|--------|
| `includes/AppConfig.php` | **New** — `APP_ENV` helper class |
| `includes/SafeLogger.php` | **New** — Secure, environment-aware logging helper |
| `includes/mailer.php` | All four log functions sanitised; `_maskEmail()` helper added; `dev_temp_password` key gated on `AppConfig::isDev()` |
| `owner/users.php` | `devTempPassword` UI block gated on `AppConfig::isDev()`; `AppConfig` required |
| `admin/users.php` | Same as `owner/users.php` |
| `.env.example` | `APP_ENV` entry added with documentation |

### 11.7 OWASP Top 10 Coverage Update

| Risk | Status After This Pass |
|------|----------------------|
| A09 Logging/Monitoring Failures | Passwords and tokens no longer written to logs in prod; `[DEV ONLY]` markers on all sensitive log output in dev |
| A02 Cryptographic Failures | Temporary passwords no longer exposed in log files or UI in prod |
| A05 Security Misconfiguration | `APP_ENV` defaults to `prod`; dev features require explicit opt-in |

