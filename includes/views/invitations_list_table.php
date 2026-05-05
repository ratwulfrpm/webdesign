<?php
/**
 * includes/views/invitations_list_table.php
 *
 * Shared partial: the invitations list table body.
 * Used by admin/users.php and owner/users.php.
 *
 * Expected variables (set before including this file):
 *   array  $invitations  — rows from supplier_invitations
 *   array  $orgs         — accessible orgs (for name lookup of extra_org_ids)
 *   PDO    $pdo          — DB connection (to mark expired entries inline)
 *   string $actionUrl    — base form action URL, e.g. '/login/owner/users.php#invitations'
 *   bool   $isOwner      — true for owner: shows extra org IDs; false for admin
 *   string $role         — current actor's role (used for admin revoke guard)
 *   string $lang         — current language code
 */

$orgNamesById = array_column($orgs, 'name', 'id');
$revokeConfirm = $lang === 'en' ? 'Revoke this invitation?' : '¿Revocar esta invitación?';
?>
<?php if (empty($invitations)): ?>
<p class="text-muted"><?= t('inv_no_invitations') ?></p>
<?php else: ?>
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= t('inv_col_org') ?></th>
                <th><?= t('inv_col_role') ?></th>
                <th><?= t('inv_col_email') ?></th>
                <th><?= t('inv_col_status') ?></th>
                <th><?= t('inv_col_expires') ?></th>
                <th><?= t('inv_col_created_by') ?></th>
                <th><?= t('inv_col_used_by') ?></th>
                <th><?= t('inv_col_created_at') ?></th>
                <th><?= t('col_actions') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($invitations as $inv):
            $isExpiredNow = $inv['status'] === 'pending'
                && strtotime($inv['expires_at']) < time();
            if ($isExpiredNow) {
                $pdo->prepare(
                    'UPDATE supplier_invitations SET status = "expired" WHERE id = ?'
                )->execute([$inv['id']]);
                $inv['status'] = 'expired';
            }
            $statusLabel = t('inv_status_' . $inv['status']);
            $statusClass = match($inv['status']) {
                'pending' => 'badge-pending',
                'used'    => 'badge-done',
                default   => 'badge-inactive',
            };
            $extraIds = $isOwner ? json_decode($inv['extra_org_ids'] ?? 'null', true) : null;
            // Revoke guard: owner can always revoke pending; admin requires non-support role
            $canRevoke = $isOwner
                ? ($inv['status'] === 'pending')
                : ($role !== 'support' && $inv['status'] === 'pending');
        ?>
            <tr>
                <td><?= (int) $inv['id'] ?></td>
                <td>
                    <?= htmlspecialchars($inv['org_name'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($isOwner && !empty($extraIds)): ?>
                    <span class="text-muted small" style="display:block;">
                        <?php foreach ($extraIds as $eid): ?>
                        + <?= htmlspecialchars($orgNamesById[$eid] ?? "Org #{$eid}", ENT_QUOTES, 'UTF-8') ?><br>
                        <?php endforeach; ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-supplier">
                        <?= htmlspecialchars(t('role_' . $inv['role']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td class="text-muted small">
                    <?= $inv['invited_email']
                        ? htmlspecialchars($inv['invited_email'], ENT_QUOTES, 'UTF-8')
                        : '<em>' . t('inv_any_email') . '</em>' ?>
                </td>
                <td>
                    <span class="badge <?= $statusClass ?>">
                        <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td class="text-muted small">
                    <?= htmlspecialchars($inv['expires_at'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($inv['created_by_username'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-muted small">
                    <?= $inv['used_by_username']
                        ? htmlspecialchars($inv['used_by_username'], ENT_QUOTES, 'UTF-8')
                        : '—' ?>
                </td>
                <td class="text-muted small">
                    <?= htmlspecialchars($inv['created_at'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td class="actions-cell">
                    <?php if ($canRevoke): ?>
                    <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="revoke_invitation">
                        <input type="hidden" name="inv_id"  value="<?= (int) $inv['id'] ?>">
                        <button type="submit" class="btn-tbl btn-danger"
                                onclick="return confirm(<?= htmlspecialchars(json_encode($revokeConfirm), ENT_QUOTES, 'UTF-8') ?>)">
                            <?= t('btn_revoke') ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
