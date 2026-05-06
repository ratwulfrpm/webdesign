<?php
/**
 * includes/views/users/user_actions.php — Action buttons for a user row.
 *
 * Rendered from user_row.php for non-self rows.
 *
 * Variables inherited from row scope:
 *   array  $u            — User row data from DB
 *   string $actorRole    — 'admin'|'owner'|'support'
 *   string $actionUrl    — Form action URL
 *   string $primaryRole  — Target user's primary role
 *   bool   $canChangeRole — true for owner (shows role selector)
 *   bool   $isLocked     — true if user account is currently locked
 *
 * Security:
 *   - Role selector is only rendered for owner ($canChangeRole = true).
 *     Admin NEVER sees the change_role form — UI omission + backend enforcement.
 *   - Reset password button hidden for support actor (support cannot reset).
 *   - Reset password hidden when target is owner (owners reset themselves only).
 *   - Backend re-validates all permissions regardless of what is shown here.
 */
?>
<div class="user-actions">

    <!-- Row 1: activate/deactivate + unlock -->
    <div class="user-actions-row">
        <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <?php if ((int) $u['is_active']): ?>
            <input type="hidden" name="action" value="deactivate">
            <button type="submit" class="btn-tbl btn-danger">
                <?= htmlspecialchars(t('btn_deactivate'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php else: ?>
            <input type="hidden" name="action" value="activate">
            <button type="submit" class="btn-tbl btn-success">
                <?= htmlspecialchars(t('btn_activate'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>
        </form>

        <?php if ($isLocked): ?>
        <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <input type="hidden" name="action" value="unlock">
            <button type="submit" class="btn-tbl btn-secondary">
                <?= htmlspecialchars(t('btn_unlock'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($canChangeRole): ?>
    <!-- Row 2: change role — OWNER ONLY.
         This form is never rendered for admin or support actors.
         Backend also enforces: admin sending change_role action returns 403. -->
    <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>"
          class="user-actions-row">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <input type="hidden" name="action" value="change_role">
        <select name="new_role" class="role-select">
            <option value="owner"    <?= $primaryRole === 'owner'    ? 'selected' : '' ?>><?= htmlspecialchars(t('role_owner'),    ENT_QUOTES, 'UTF-8') ?></option>
            <option value="admin"    <?= $primaryRole === 'admin'    ? 'selected' : '' ?>><?= htmlspecialchars(t('role_admin'),    ENT_QUOTES, 'UTF-8') ?></option>
            <option value="support"  <?= $primaryRole === 'support'  ? 'selected' : '' ?>><?= htmlspecialchars(t('role_support'),  ENT_QUOTES, 'UTF-8') ?></option>
            <option value="supplier" <?= $primaryRole === 'supplier' ? 'selected' : '' ?>><?= htmlspecialchars(t('role_supplier'), ENT_QUOTES, 'UTF-8') ?></option>
        </select>
        <button type="submit" class="btn-tbl btn-secondary">
            <?= htmlspecialchars(t('btn_set_role'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </form>
    <?php endif; ?>

    <?php if ($primaryRole !== 'owner' && $actorRole !== 'support'): ?>
    <!-- Row 3: reset password.
         Hidden when target is owner (cannot reset owner).
         Hidden when actor is support (support cannot reset anyone).
         Backend enforces these rules independently of UI. -->
    <div class="user-actions-row">
        <form method="POST" action="<?= htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') ?>"
              onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('reset_password_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <input type="hidden" name="action" value="reset_password">
            <button type="submit" class="btn-tbl btn-secondary">
                <?= htmlspecialchars(t('btn_reset_password'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>
