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
- Invitation management (generate link, revoke, history) is now **embedded inside the Users page** (`/login/admin/index.php` for admin, `/login/owner/index.php` for owner).
- The Users page now displays three sections: **Users**, **Password Requests**, **Contract Validity Requests**, and a new **Invitations** section at the bottom.
- Admin can invite `support` and `supplier` roles (unchanged restriction).
- Owner can invite `admin`, `support`, and `supplier` roles (unchanged). The multi-org checkbox picker for admin invites is also embedded.
- Support retains a standalone **"Invitations"** tab on `invitations.php` for read-only listing.

### Users list — backend pagination (50 users/page)

- Admin users page (`/login/admin/index.php`) and owner users page (`/login/owner/index.php`) now paginate the users table at **50 users per page**.
- Page is controlled via the `upage` GET parameter.
- A page-number nav bar appears below the users table when total users exceed 50.

### Assignments — transparent multi-BU product search (admin & owner)

Previous behavior:
- admin and owner had to select a specific business unit before searching products in the assignments UI.
- Products were filtered to the selected BU only.

New behavior (commits 34c430d / bae35df):
- The BU selector is **no longer shown** to admin and owner in the assignments product search panel.
- Search results include products from **all accessible BUs** transparently.
- A single assignment/quote **may contain products from multiple business units**.
- Backend validation verifies each selected product against all BUs the user can access (not limited to one).
- `org_id` in the create assignment POST is derived automatically for admin/owner from session context; the explicit org field is only required for support.
- The validity amount input auto-selects its value on focus so the first keystroke replaces the default instead of appending to it.

This behavior is identical for `admin` and `owner`. Owner rights are not degraded.

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
   - admin/assignments
   - admin/index
   - invitations
8. Re-run audit queries after remediation if needed.

## 8) Known Validation Limits in This Workspace

- PHP CLI is not installed in this environment (`php` command unavailable), so runtime CLI lint/tests were not executed here.
- Static editor diagnostics for modified PHP files are clean.

## 9) Admin / Owner Feature Parity Policy

Whenever a new enhancement is applied to the `admin` role that enables behavior not previously available, the same change must be mirrored to `owner` without reducing any of owner's superior rights.

This ensures `owner ≥ admin` at all times in terms of feature capability.
