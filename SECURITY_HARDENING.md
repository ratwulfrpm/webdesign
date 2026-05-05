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
