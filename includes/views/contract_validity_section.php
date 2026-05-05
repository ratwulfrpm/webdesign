<?php
/**
 * includes/views/contract_validity_section.php
 *
 * Shared partial: Contract-validity review requests table.
 * Used by admin/users.php and owner/users.php.
 *
 * Expected variables (set before including this file):
 *   array  $validityRequests — rows from cvrListValidityRequests()
 *   string $actionUrl        — form action URL, e.g. '/login/admin/users.php'
 */
?>
<!-- ── Contract validity requests ──────────────────── -->
<section class="panel-section" style="margin-top:36px;">
    <h2 class="section-title"><?= t('contract_review_requests_title') ?></h2>

    <?php if (empty($validityRequests)): ?>
        <p class="text-muted"><?= t('contract_review_no_requests') ?></p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= t('col_supplier') ?></th>
                    <th><?= t('contract_review_business_unit') ?></th>
                    <th><?= t('contract_review_requested_contract') ?></th>
                    <th><?= t('contract_review_current_contract') ?></th>
                    <th><?= t('col_contract_signed_date') ?></th>
                    <th><?= t('col_contract_start') ?></th>
                    <th><?= t('col_contract_end') ?></th>
                    <th><?= t('col_contract_uploaded_at') ?></th>
                    <th><?= t('contract_review_requested_by') ?></th>
                    <th><?= t('contract_review_requested_at') ?></th>
                    <th><?= t('req_status') ?></th>
                    <th><?= t('col_actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($validityRequests as $vr): ?>
                <tr>
                    <td><?= (int) $vr['id'] ?></td>
                    <td><?= htmlspecialchars((string) $vr['supplier_username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $vr['org_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $vr['requested_contract_file'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($vr['current_contract_file'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($vr['requested_signed_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($vr['requested_start_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($vr['requested_end_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(substr((string) $vr['requested_uploaded_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($vr['requested_by_username'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $vr['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="badge <?= $vr['status'] === 'pending' ? 'badge-pending' : 'badge-done' ?>">
                            <?= htmlspecialchars(t('contract_review_status_' . $vr['status']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($vr['status'] === 'pending'): ?>
                        <div class="user-actions-row" style="display:flex;gap:6px;flex-wrap:wrap;">
                            <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="approve_contract_validity_request">
                                <input type="hidden" name="validity_request_id" value="<?= (int) $vr['id'] ?>">
                                <button type="submit" class="btn-tbl btn-success"><?= t('btn_approve') ?></button>
                            </form>
                            <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="reject_contract_validity_request">
                                <input type="hidden" name="validity_request_id" value="<?= (int) $vr['id'] ?>">
                                <input type="text" name="review_comment" maxlength="1000" placeholder="<?= htmlspecialchars(t('contract_review_comment_optional'), ENT_QUOTES, 'UTF-8') ?>" style="max-width:180px;">
                                <button type="submit" class="btn-tbl btn-danger"><?= t('btn_reject') ?></button>
                            </form>
                        </div>
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
