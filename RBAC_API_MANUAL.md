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
- Cannot access invitations UI/API.

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

- support tabs no longer include invitations.
- support-only BU switcher appears when support has >1 assigned BUs.
- owner/admin pages avoid showing empty org badge for global session.

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
