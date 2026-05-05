<?php

/**
 * Shared organization-scope helpers for multi-business-unit RBAC.
 */

function loadAccessibleOrganizations(PDO $pdo, int $userId, string $role, bool $activeOnly = true): array
{
    if ($role === 'owner') {
        $sql = 'SELECT id, name
                  FROM organizations';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        return $pdo->query($sql)->fetchAll();
    }

    if ($role === 'admin' || $role === 'support') {
        $sql = 'SELECT o.id, o.name
                  FROM org_members om
                  JOIN organizations o ON o.id = om.org_id
                 WHERE om.user_id = ?
                   AND om.is_active = 1';
        if ($activeOnly) {
            $sql .= ' AND o.is_active = 1';
        }
        $sql .= ' ORDER BY o.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    return [];
}

function loadAccessibleOrgIds(PDO $pdo, int $userId, string $role, bool $activeOnly = true): array
{
    return array_map('intval', array_column(
        loadAccessibleOrganizations($pdo, $userId, $role, $activeOnly),
        'id'
    ));
}

function orgScopeContainsOrgId(array $allowedOrgIds, int $orgId): bool
{
    return $orgId > 0 && in_array($orgId, $allowedOrgIds, true);
}

function orgScopeUserAccessible(
    PDO $pdo,
    int $targetUserId,
    array $allowedOrgIds,
    array $roles = [],
    bool $requireActiveMembership = true
): bool {
    if ($targetUserId <= 0 || empty($allowedOrgIds)) {
        return false;
    }

    $params = [$targetUserId];
    $where = ['om.user_id = ?'];

    $orgPlaceholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
    $where[] = "om.org_id IN ({$orgPlaceholders})";
    $params = array_merge($params, $allowedOrgIds);

    if ($requireActiveMembership) {
        $where[] = 'om.is_active = 1';
    }

    if (!empty($roles)) {
        $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
        $where[] = "om.role IN ({$rolePlaceholders})";
        $params = array_merge($params, $roles);
    }

    $stmt = $pdo->prepare(
        'SELECT 1
           FROM org_members om
          WHERE ' . implode(' AND ', $where) . '
          LIMIT 1'
    );
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

// ── Enrollment URL builder (shared by admin/users.php and owner/users.php) ─

if (!function_exists('buildEnrollLink')) {
    /**
     * Build the absolute enrollment URL for a plain (un-hashed) invitation token.
     *
     * @param  string $plainToken  Un-hashed 64-char hex token
     * @return string              Full https://… or http://… URL
     */
    function buildEnrollLink(string $plainToken): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/login/enroll.php?t=' . rawurlencode($plainToken);
    }
}