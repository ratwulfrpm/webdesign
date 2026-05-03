# REST API v1

Base URL: `/api/v1/`

All responses are `application/json; charset=utf-8`.  
All private endpoints require an active session (role checked per resource).  
Tokens are 64-char hex strings — SHA-256 hash stored in DB, plain token returned **once** at creation.

---

## Authentication & RBAC

| Role | Level | Access |
|---|---|---|
| `owner` | 4 | Full access |
| `admin` | 3 | Full access |
| `supplier` | 2 | Own products only |

Session cookie required for all private endpoints.  
`/public/quote` is the only unauthenticated endpoint.

End-customer access is token-only via assignment/public quote links.
There is no authenticated `user` actor in API v1.

Multi-business-unit scope:
- `admin` only receives data from business units assigned through `org_members`.
- `owner` can operate across all active business units.
- Requests that target an `org_id` outside the caller scope are rejected server-side.

---

## Endpoint Reference

### Users

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/users` | GET | List accessible users. Filters: `?status=active\|inactive`, `?org_id=`, `?role=` | admin/owner |
| `/api/v1/users/:id` | GET | Accessible user detail + visible business units | admin/owner |
| `/api/v1/users/:id` | PATCH | Action-based update: `activate`, `deactivate`, `unlock` | admin/owner |

Notes:
- `admin` only sees supplier users inside assigned business units.
- `owner` can list users across all active business units.
- Responses include `business_unit_name` and `business_units`.

**PATCH /users/:id body:**
```json
{
  "action": "activate | deactivate | unlock"
}
```

### Products

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/products` | GET | List active products. Filter: `?name=`, `?page=` | admin/owner/supplier |
| `/api/v1/products` | POST | Create product | admin/owner/supplier |
| `/api/v1/products/:id` | GET | Product detail + images + keywords | admin/owner/supplier |
| `/api/v1/products/:id` | PATCH | Update product fields | admin/owner/supplier |
| `/api/v1/products/:id` | DELETE | Soft-delete (sets `active=0`) | admin/owner |
| `/api/v1/products/:id/images` | GET | List images (all slots) | admin/owner/supplier |
| `/api/v1/products/:id/images/:slot` | DELETE | Remove image by slot | admin/owner/supplier |
| `/api/v1/products/:id/keywords` | GET | List keywords | admin/owner/supplier |
| `/api/v1/products/:id/keywords` | POST | Add keyword | admin/owner/supplier |
| `/api/v1/products/:id/keywords/:kw` | DELETE | Remove keyword | admin/owner/supplier |

**Image slots:** `front`, `back`, `left`, `right`, `aerial`, `bottom`

**POST /products body:**
```json
{
  "product_name": "string (required)",
  "supplier_product_code": "string (required)",
  "technical_description": "string",
  "price_fob": 0.00,
  "price_cif": 0.00,
  "supplier_id": 1
}
```

**PATCH /products/:id body** (any subset of fields):
```json
{
  "product_name": "string",
  "technical_description": "string",
  "price_fob": 0.00,
  "price_cif": 0.00
}
```

> `price_fob` / `price_cif` are only returned to admin/owner roles.  
> `supplier_product_code` is never exposed in list or detail responses.

---

### Suppliers

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/suppliers` | GET | List active suppliers. Filter: `?q=`, `?page=` | admin/owner |
| `/api/v1/suppliers/:id` | GET | Supplier detail + contacts + contracts + product count | admin/owner |

---

### Contracts

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/contracts` | GET | List contracts. Filter: `?supplier_id=`, `?page=` | admin/owner |
| `/api/v1/contracts` | POST | Upload contract file (`multipart/form-data`) | admin/owner |
| `/api/v1/contracts/:id` | GET | Contract metadata (no file stream) | admin/owner |

Contracts are **immutable** — no PATCH or DELETE. Max file size: 10 MB.  
Allowed MIME types: `application/pdf`, `image/jpeg`, `image/png`.

Validity rule (supplier workflow):
- Only the **most recently uploaded** contract can be set as current directly.
- Historical contracts require an admin/owner review request before changing current validity.

**POST /contracts form fields:**
```
supplier_id           (required)
contract_file         (required, file upload)
signed_date           YYYY-MM-DD
effective_start_date  YYYY-MM-DD
effective_end_date    YYYY-MM-DD
notes                 string
is_primary            1 | 0
```

---

### Contract Validity Review Requests

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/supplier/contracts/:id/request-validity-review` | POST | Supplier requests review to set a historical contract as current | supplier |
| `/api/v1/admin/contract-validity-requests` | GET | List validity review requests (`?status=` optional) | admin/owner |
| `/api/v1/admin/contract-validity-requests/:id/approve` | POST | Approve request and switch current contract transactionally | admin/owner |
| `/api/v1/admin/contract-validity-requests/:id/reject` | POST | Reject request (optional review comment) | admin/owner |

Supplier request behavior:
- If target contract is latest upload: endpoint returns validation error (no review needed).
- If target contract is historical: creates pending request unless one already exists.
- Current contract does not change until approval.

Reject body example:
```json
{
  "review_comment": "Optional reason"
}
```

Approval behavior:
- Transactional update:
  1. `is_primary = 0` for all supplier contracts in business unit.
  2. Requested contract set to `is_primary = 1`.
  3. Request status updated to `approved`, with reviewer and timestamp.

Security checks:
- Server-side ownership and object checks on every request.
- `org_id` scope enforced for admin; owner can review cross-business-unit requests.
- Pending duplicate requests for same supplier/contract are blocked.
- Supplier cannot approve/reject requests.

---

### Invitations

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/invitations` | GET | List invitations. Filter: `?status=pending\|used\|expired\|revoked`, `?org_id=` | admin/owner |
| `/api/v1/invitations` | POST | Create invitation — returns plain token + enroll URL (once only) | admin/owner |
| `/api/v1/invitations/:id` | GET | Invitation detail (no token returned) | admin/owner |
| `/api/v1/invitations/:id/revoke` | POST | Revoke pending invitation | admin/owner |

Scope notes:
- `admin` can only list/create/revoke invitations inside assigned business units.
- Invitation responses include `business_unit_name` and `business_unit`.

**POST /invitations body:**
```json
{
  "org_id": 1,
  "role": "supplier | admin",
  "invited_email": "optional@example.com",
  "valid_days": 7
}
```

**Response includes:**
```json
{
  "token": "…64 hex chars — returned ONCE, not stored in DB…",
  "enroll_url": "https://…/enroll.php?t=…"
}
```

---

### Search

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/search/products` | GET | Paginated full-text product search | admin/owner |

**Query parameters:**

| Param | Description |
|---|---|
| `q` | General keyword (FULLTEXT + keywords table + LIKE fallback) |
| `supplier` | Supplier username or company name (LIKE) |
| `name` | Product name (LIKE) |
| `description` | Technical description (LIKE) |
| `page` | Page number (default 1, 25 per page) |

**Response:**
```json
{
  "success": true,
  "items": [
    {
      "id": 1,
      "product_name": "…",
      "internal_product_code": "…",
      "price_fob": 10.00,
      "price_cif": 12.00,
      "supplier_username": "…",
      "supplier_company": "…",
      "org_name": "…",
      "front_img_path": "uploads/products/…",
      "keywords_csv": "keyword1, keyword2"
    }
  ],
  "total": 100,
  "page": 1,
  "pages": 4,
  "per_page": 25
}
```

---

### Assignments (Quotes)

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/assignments` | GET | List quotes (excl. deleted). Filter: `?status=`, `?page=`, `?org_id=` | admin/owner |
| `/api/v1/assignments` | POST | Create multi-product quote — returns plain token + URL (once only) | admin/owner |
| `/api/v1/assignments/:id` | GET | Quote detail + line items + totals | admin/owner |
| `/api/v1/assignments/:id` | DELETE | Soft-delete quote | admin/owner |
| `/api/v1/assignments/:id/revoke` | POST | Revoke active quote (token stops working) | admin/owner |
| `/api/v1/assignments/:id/clone` | POST | Clone quote with new token (regen link) | admin/owner |

Scope notes:
- `admin` can only list/create/revoke/delete/clone assignments inside assigned business units.
- `org_id` in create requests is validated server-side against the caller scope.
- Assignment responses include `business_unit_name` and `business_unit`.

**POST /assignments body:**
```json
{
  "org_id": 1,
  "assigned_customer_name": "Juan Pérez (required)",
  "company_name": "optional",
  "special_conditions": "optional",
  "price_base_type": "fob | cif",
  "profit_calculation_type": "percentage | fixed_amount",
  "profit_percentage": 30.0,
  "profit_fixed_amount": null,
  "transport_calculation_type": "percentage | fixed_amount | null",
  "transport_percentage": 5.0,
  "transport_fixed_amount": null,
  "tax_calculation_type": "percentage | fixed_amount | null",
  "tax_percentage": 16.0,
  "tax_fixed_amount": null,
  "validity_amount": 7,
  "validity_unit": "days | hours",
  "discount_percentage": 5.0,
  "product_ids": [1, 4, 7]
}
```

**POST /assignments/:id/clone body** (optional override):
```json
{
  "assigned_customer_name": "New Customer Name"
}
```

**Create/Clone response includes:**
```json
{
  "id": 42,
  "token": "…64 hex chars — returned ONCE…",
  "quote_url": "https://…/quote.php?t=…",
  "expires_at": "2026-05-06 10:00:00"
}
```

**Assignment detail totals:**
```json
{
  "totals": {
    "subtotal": 300.00,
    "transport": 15.00,
    "tax": 50.40,
    "discount_percent": 5.0,
    "discount_amount": 18.27,
    "grand_total": 347.13
  }
}
```

> Quotes expiration is configurable per assignment (minimum 1 hour, maximum 7 days).  
> Status lifecycle: `active` → `expired` (auto) | `revoked` (manual) | `deleted` (soft).

---

### Public Quote (No Auth)

| Endpoint | Method | Description | Auth |
|---|---|---|---|
| `/api/v1/public/quote?t=TOKEN` | GET | Customer-facing quote view by token | **None** |

Rate limit: **20 requests / 10 min per IP**.

**Response (new format):**
```json
{
  "success": true,
  "quote": {
    "customer_name": "Juan Pérez",
    "company_name": "Acme S.A.",
    "special_conditions": "Entrega en 30 días",
    "expires_at": "2026-05-06 10:00:00",
    "items": [
      {
        "product_name": "Producto A",
        "technical_description": "…",
        "unit_price": 150.00,
        "front_img_path": "uploads/products/…"
      }
    ],
    "totals": {
      "subtotal": 300.00,
      "transport": 15.00,
      "tax": 50.40,
      "discount_percent": 5.0,
      "discount_amount": 18.27,
      "grand_total": 347.13
    }
  }
}
```

> **Never exposes:** `price_fob`, `price_cif`, `price_base_amount`, `profit_percentage`, `product_id`, `internal_product_code`, `supplier_product_code`.  
> Supports legacy single-product format (backward compatible).

---

## Security Summary

| Threat | Mitigation |
|---|---|
| SQL Injection | 100% PDO prepared statements — zero user input concatenated into SQL |
| IDOR | Ownership checks on contract/request IDs + `org_id` scoping for admin endpoints |
| XSS | Pure JSON API — zero HTML output surface |
| Token brute-force | Rate limit 20 req/10min per IP on `/public/quote` |
| Token leakage | SHA-256 stored in DB; plain token returned **once** on create/clone |
| MIME spoofing | Server-side `finfo` validation on contract file uploads |
| Path traversal | Storage paths always server-controlled, never from user input |
| Privilege escalation | `requireAuth()` validates role on every request |

---

## File Structure

```
api/v1/
├── .htaccess                  — rewrite all requests to index.php
├── _helpers.php               — jsonOk / jsonError / requireAuth / parseBody / likeWrap
├── index.php                  — router & front controller
├── README.md                  — this file
└── resources/
    ├── products.php           — products + images + keywords
  ├── users.php              — scoped user listing + state actions
    ├── suppliers.php          — suppliers list & detail
    ├── contracts.php          — contracts list, upload & detail
    ├── contract_validity_requests.php — supplier review requests + admin/owner approve/reject
    ├── invitations.php        — invitations CRUD + revoke
    ├── search.php             — paginated full-text product search
    ├── assignments.php        — quotes CRUD + revoke + clone
    └── public_quote.php       — public token endpoint (no auth, dual-format)
```

---

## Versioning

Current version: **v1** — all endpoints prefixed `/api/v1/`.  
Future versions will live at `/api/v2/` in parallel.  
Deprecated versions will announce via `Deprecation` response header before removal.
