<?php
/**
 * includes/views/users/users_table.php — User management table section.
 *
 * Rendered from users_page.php. Loops over $users and renders each row
 * via user_row.php → user_actions.php.
 *
 * Variables inherited from page scope:
 *   array  $users
 *   int    $usersPage, $usersPages, $usersTotal
 *   string $actorRole    — 'admin'|'owner'|'support'
 *   string $actionUrl    — Form action URL
 *   bool   $canChangeRole — true for owner
 */
?>
<!-- ── User management ────────────────────────────── -->
<section class="panel-section">
    <h2 class="section-title"><?= htmlspecialchars(t('user_management'), ENT_QUOTES, 'UTF-8') ?></h2>

    <?php if (empty($users)): ?>
        <p class="text-muted"><?= htmlspecialchars(t('no_users'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= t('col_id') ?></th>
                    <th><?= t('col_username') ?></th>
                    <th><?= t('col_email') ?></th>
                    <th><?= t('col_role') ?></th>
                    <th><?= t('col_org') ?></th>
                    <th><?= t('col_status') ?></th>
                    <th><?= t('col_first_login') ?></th>
                    <?php if (!$canChangeRole): ?>
                    <th><?= t('col_created_at') ?></th>
                    <?php endif; ?>
                    <th><?= t('col_attempts') ?></th>
                    <th><?= t('col_locked_until') ?></th>
                    <th><?= t('col_actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php require __DIR__ . '/user_row.php'; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($usersPages > 1): ?>
    <nav class="pagination"
         aria-label="<?= htmlspecialchars(t('pagination_label'), ENT_QUOTES, 'UTF-8') ?>"
         style="margin-top:16px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <?php for ($p = 1; $p <= $usersPages; $p++): ?>
        <a href="?upage=<?= $p ?>"
           class="btn-secondary btn-sm<?= $p === $usersPage ? ' active' : '' ?>"
           <?= $p === $usersPage ? 'aria-current="page"' : '' ?>>
            <?= $p ?>
        </a>
        <?php endfor; ?>
        <span class="text-muted small" style="margin-left:6px;">(<?= $usersTotal ?>)</span>
    </nav>
    <?php endif; ?>
</section>
