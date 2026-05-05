<?php
/**
 * includes/views/password_requests_section.php
 *
 * Shared partial: Password-requests management table.
 * Used by admin/users.php and owner/users.php.
 *
 * Expected variables (set before including this file):
 *   array  $requests   — rows from password_requests table
 *   string $actionUrl  — form action URL, e.g. '/login/admin/users.php'
 */
?>
<!-- ── Password requests ──────────────────────────── -->
<section class="panel-section" style="margin-top:36px;">
    <h2 class="section-title"><?= t('col_requests') ?></h2>

    <?php if (empty($requests)): ?>
        <p class="text-muted"><?= t('no_requests') ?></p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= t('req_company') ?></th>
                    <th><?= t('req_email') ?></th>
                    <th><?= t('req_user') ?></th>
                    <th><?= t('req_notes') ?></th>
                    <th><?= t('req_date') ?></th>
                    <th><?= t('req_status') ?></th>
                    <th><?= t('col_actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['company_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $r['username'] ? htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td class="small text-muted"><?= $r['notes'] ? htmlspecialchars($r['notes'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="badge <?= $r['status'] === 'pending' ? 'badge-pending' : 'badge-done' ?>">
                            <?= $r['status'] === 'pending' ? t('req_pending') : t('req_resolved') ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token"
                                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="action" value="resolve_request">
                            <button type="submit" class="btn-tbl btn-success"><?= t('btn_resolve') ?></button>
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
</section>
