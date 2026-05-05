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
