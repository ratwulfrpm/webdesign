<?php
/**
 * includes/views/users/user_row.php — Single user table row.
 *
 * Rendered from users_table.php inside a foreach ($users as $u) loop.
 *
 * Variables inherited from loop scope:
 *   array  $u            — User row data from DB
 *   string $actorRole    — 'admin'|'owner'|'support'
 *   string $actionUrl    — Form action URL
 *   bool   $canChangeRole — true for owner (shows role selector)
 */

$isLocked = !empty($u['locked_until']) && strtotime($u['locked_until']) > time();
$isSelf   = (int) $u['id'] === (int) $_SESSION['user_id'];

// Determine primary role and badge class for this row.
if ($canChangeRole) {
    // Owner view: users have multi-role CSV; display highest-ranked role.
    $rolesCsv    = (string) ($u['roles_csv'] ?? 'supplier');
    $roles       = array_values(array_filter(explode(',', $rolesCsv)));
    $primaryRole = $roles[0] ?? 'supplier';
    $roleBadge   = match ($primaryRole) {
        'owner'   => 'badge-owner',
        'admin'   => 'badge-admin',
        'support' => 'badge-admin',
        default   => 'badge-supplier',
    };
} else {
    // Admin / support view: only supplier rows are returned by the query.
    $primaryRole = (string) ($u['role'] ?? 'supplier');
    $roleBadge   = 'badge-supplier';
}
?>
<tr>
    <td><?= (int) $u['id'] ?></td>
    <td><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
    <td>
        <span class="badge <?= htmlspecialchars($roleBadge, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(t('role_' . $primaryRole), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </td>
    <td class="text-muted small">
        <?= htmlspecialchars((string) ($u['org_names'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
    </td>
    <td>
        <span class="badge <?= (int) $u['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
            <?= (int) $u['is_active']
                ? htmlspecialchars(t('status_active'),   ENT_QUOTES, 'UTF-8')
                : htmlspecialchars(t('status_inactive'), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </td>
    <td>
        <span class="badge <?= (int) $u['first_login'] ? 'badge-pending' : 'badge-done' ?>">
            <?= (int) $u['first_login']
                ? htmlspecialchars(t('first_login_yes'), ENT_QUOTES, 'UTF-8')
                : htmlspecialchars(t('first_login_no'),  ENT_QUOTES, 'UTF-8') ?>
        </span>
    </td>
    <?php if (!$canChangeRole): ?>
    <td class="text-muted small">
        <?= htmlspecialchars(
            substr((string) ($u['created_at'] ?? ''), 0, 10) ?: '—',
            ENT_QUOTES, 'UTF-8'
        ) ?>
    </td>
    <?php endif; ?>
    <td><?= (int) $u['failed_attempts'] ?></td>
    <td class="text-muted small">
        <?= $isLocked
            ? htmlspecialchars($u['locked_until'], ENT_QUOTES, 'UTF-8')
            : '—' ?>
    </td>
    <td>
        <?php if ($isSelf): ?>
            <span class="text-muted small">(<?= htmlspecialchars(t('session_active'), ENT_QUOTES, 'UTF-8') ?>)</span>
        <?php else: ?>
            <?php require __DIR__ . '/user_actions.php'; ?>
        <?php endif; ?>
    </td>
</tr>
