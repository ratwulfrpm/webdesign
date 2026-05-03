-- Audit script: organization/business-unit integrity checks
-- Safe read-only diagnostics (no UPDATE/DELETE).

-- 1) Full organizations listing
SELECT id, slug, name, active, created_at
FROM organizations
ORDER BY id;

-- 2) Duplicate slug detection (should be 0 rows)
SELECT slug, COUNT(*) AS cnt
FROM organizations
GROUP BY slug
HAVING COUNT(*) > 1;

-- 3) Duplicate name detection (review manually if any row appears)
SELECT name, COUNT(*) AS cnt
FROM organizations
GROUP BY name
HAVING COUNT(*) > 1;

-- 4) Canonical slugs expected by app seeds/migrations
SELECT id, slug, name, active
FROM organizations
WHERE slug IN ('jshop', 'jbusiness')
ORDER BY slug, id;

-- 5) Detect likely accidental overwrite cases around known names
SELECT id, slug, name, active
FROM organizations
WHERE LOWER(name) IN ('jbusiness', 'metales rpm', 'jshop')
ORDER BY id;

-- 6) Orphan org_members rows (should be 0 rows)
SELECT om.id, om.user_id, om.org_id, om.role
FROM org_members om
LEFT JOIN organizations o ON o.id = om.org_id
WHERE o.id IS NULL;

-- 7) Users with memberships in inactive organizations (review)
SELECT om.user_id, u.username, om.org_id, o.slug, o.name, o.active, om.role
FROM org_members om
JOIN users u ON u.id = om.user_id
JOIN organizations o ON o.id = om.org_id
WHERE o.active = 0
ORDER BY om.user_id, om.org_id;

-- 8) Membership cardinality per organization by role
SELECT o.id, o.slug, o.name, om.role, COUNT(*) AS members
FROM organizations o
LEFT JOIN org_members om ON om.org_id = o.id
GROUP BY o.id, o.slug, o.name, om.role
ORDER BY o.id, om.role;

-- 9) Invitations tied to missing organizations (should be 0 rows)
SELECT i.id, i.org_id, i.role, i.status, i.created_at
FROM invitations i
LEFT JOIN organizations o ON o.id = i.org_id
WHERE o.id IS NULL;

-- 10) Products tied to missing organizations (should be 0 rows)
SELECT p.id, p.org_id, p.product_name, p.active
FROM products p
LEFT JOIN organizations o ON o.id = p.org_id
WHERE o.id IS NULL;

-- 11) Quote assignments tied to missing organizations (should be 0 rows)
SELECT qa.id, qa.org_id, qa.status, qa.created_at
FROM quote_assignments qa
LEFT JOIN organizations o ON o.id = qa.org_id
WHERE o.id IS NULL;
