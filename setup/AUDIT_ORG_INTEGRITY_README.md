# Organization Integrity Audit

Use `setup/audit_org_integrity.sql` to diagnose organization/business-unit data issues before applying any fix.

## What it checks
- Duplicate `slug` values in `organizations`.
- Duplicate `name` values in `organizations`.
- Presence and identity of canonical slugs (`jshop`, `jbusiness`).
- Potential accidental rename cases (`JBusiness`, `metales rpm`, `JShop`).
- Orphan rows in `org_members`, `invitations`, `products`, and `quote_assignments`.
- Membership distribution by organization and role.

## Recommended remediation flow
1. Run the audit and export results.
2. Confirm canonical records for `jshop` and `jbusiness` by `id` + `slug`.
3. If an organization name was incorrectly overwritten, fix only that row by `id` (never bulk-update by name).
4. If orphan rows exist, repair FK targets or remove invalid rows in a controlled migration.
5. Re-run the audit until all integrity checks are clean.

## Example targeted remediation (template)
Adjust placeholders before running.

```sql
-- Example: restore canonical name for a known org id
UPDATE organizations
SET name = 'JBusiness'
WHERE id = <JBUSINESS_ID>
  AND slug = 'jbusiness';

-- Example: if an accidental org exists and has no memberships/products/quotes,
-- archive it instead of deleting hard:
UPDATE organizations
SET active = 0
WHERE id = <ACCIDENTAL_ORG_ID>;
```

## Important
- Take a DB backup before any remediation.
- Prefer one-off, id-targeted fixes inside a transaction.
- Avoid `ON DUPLICATE KEY UPDATE name = VALUES(name)` in seed/migration scripts.
