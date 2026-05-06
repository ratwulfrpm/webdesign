<?php
/**
 * includes/views/users/invitation_section.php — Invitation form + list.
 *
 * Rendered from users_page.php.
 *
 * Variables inherited from page scope:
 *   string $actorRole       — 'admin'|'owner'|'support'
 *   string $actionUrl       — Base form action URL (no anchor)
 *   string $lang
 *   bool   $isOwner         — true for owner (shows admin invite option + multi-org)
 *   string $role            — Same as $actorRole (alias for invitations_list_table.php)
 *   array  $orgs            — Accessible org rows [{id, name}]
 *   int    $orgId           — Session org_id (admin preselect; 0 for owner)
 *   string $invFeedback     — Invitation-specific feedback message
 *   string $invFeedbackType — 'success'|'error'|'warning'
 *   string $invNewLink      — New invitation link (after generate; may be empty)
 *   string|null $invNewEmail — Invited email (nullable)
 *   array|null $invEmailResult — Email send result (nullable)
 *   array  $invitations     — Invitation rows
 *   array  $accessibleOrgIds — Used by invitations_list_table.php for extra_org lookup
 *
 * Owner-only:
 *   Invitation form shows admin role option with multi-org checkboxes.
 *   JS toggle (defined in users_page.php) switches between single-org and multi-org picker.
 *
 * Admin:
 *   Invitation form shows support + supplier only (no admin option).
 *
 * Support:
 *   No invite form rendered. List shown in read-only mode.
 *   Backend blocks support from creating or revoking invitations.
 */

// Anchor-qualified action URL used by the invite form and list table.
$_invActionUrl = $actionUrl . '#invitations';
?>
<!-- ── Invitations ───────────────────────────────── -->
<section class="panel-section" style="margin-top:36px;" id="invitations">
    <h2 class="section-title"><?= htmlspecialchars(t('inv_section_title'), ENT_QUOTES, 'UTF-8') ?></h2>

    <?php if ($invFeedback !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars(
        $invFeedbackType === 'error'   ? 'error'   :
        ($invFeedbackType === 'warning' ? 'warning' : 'success'),
        ENT_QUOTES, 'UTF-8'
    ) ?>" style="margin-bottom:20px;" role="status">
        <span><?= htmlspecialchars($invFeedback, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <?php if ($invNewLink !== ''): ?>
    <div class="inv-link-banner" role="region"
         aria-label="<?= htmlspecialchars(t('inv_link_banner_title'), ENT_QUOTES, 'UTF-8') ?>">
        <div class="inv-link-banner-header">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="9" stroke="#34c759" stroke-width="1.5"/>
                <polyline points="5.5,10 8.5,13 14.5,7" stroke="#34c759" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <strong><?= htmlspecialchars(t('inv_link_banner_title'), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <p class="inv-link-banner-desc"><?= htmlspecialchars(t('inv_link_banner_desc'), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="inv-link-row">
            <input type="text"
                   id="inv-link-input"
                   class="inv-link-input"
                   value="<?= htmlspecialchars($invNewLink, ENT_QUOTES, 'UTF-8') ?>"
                   readonly
                   aria-label="<?= htmlspecialchars(t('inv_link_banner_title'), ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="btn-secondary btn-sm inv-copy-btn"
                    onclick="copyInvLink()"
                    id="inv-copy-btn">
                <?= htmlspecialchars(t('btn_copy_link'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
        <?php if ($invEmailResult !== null && $invNewEmail !== null): ?>
            <?php if ($invEmailResult['sent']): ?>
            <p class="inv-email-status inv-email-ok">
                ✓ <?= htmlspecialchars(t('inv_email_sent_success'), ENT_QUOTES, 'UTF-8') ?>
                (<?= htmlspecialchars($invNewEmail, ENT_QUOTES, 'UTF-8') ?>)
            </p>
            <?php else: ?>
            <p class="inv-email-status inv-email-fail">
                ✗ <?= htmlspecialchars(t('inv_email_send_failed'), ENT_QUOTES, 'UTF-8') ?>
                — <?= htmlspecialchars($invNewEmail, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($actorRole !== 'support'): ?>
    <!-- Invite form — admin: support+supplier; owner: support+supplier+admin -->
    <div style="margin-top:24px;">
        <h3 class="section-subtitle"><?= htmlspecialchars(t('inv_form_title'), ENT_QUOTES, 'UTF-8') ?></h3>
        <form method="POST"
              action="<?= htmlspecialchars($_invActionUrl, ENT_QUOTES, 'UTF-8') ?>"
              class="inv-gen-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_invitation">
            <div class="form-row">

                <!-- Single-org selector (hidden when admin role selected — owner only) -->
                <div class="input-wrap" id="inv-org-wrap">
                    <label for="inv-org"><?= htmlspecialchars(t('inv_org_label'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="inv-org" name="org_id" class="input-select">
                        <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"
                            <?= (int) $o['id'] === $orgId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($isOwner): ?>
                <!-- Multi-org checkboxes — shown when admin role selected (owner only).
                     JS toggle in users_page.php controls visibility. -->
                <div class="input-wrap" id="inv-admin-orgs-wrap" style="display:none;">
                    <label><?= htmlspecialchars(t('inv_admin_orgs_label'), ENT_QUOTES, 'UTF-8') ?></label>
                    <p class="input-help" style="margin-bottom:8px;">
                        <?= htmlspecialchars(t('inv_admin_orgs_help'), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <?php foreach ($orgs as $o): ?>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer;">
                            <input type="checkbox"
                                   name="admin_org_ids[]"
                                   value="<?= (int) $o['id'] ?>"
                                   style="width:16px;height:16px;cursor:pointer;">
                            <?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="input-wrap">
                    <label for="inv-role"><?= htmlspecialchars(t('inv_role_label'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="inv-role" name="inv_role" class="input-select">
                        <option value="supplier" selected><?= htmlspecialchars(t('role_supplier'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="support"><?= htmlspecialchars(t('role_support'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php if ($isOwner): ?>
                        <!-- Admin option only visible to owner.
                             Backend also enforces: admin sending inv_role=admin is blocked with 403. -->
                        <option value="admin"><?= htmlspecialchars(t('role_admin'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="input-wrap" style="flex:2">
                    <label for="inv-email"><?= htmlspecialchars(t('inv_email_label'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="email"
                           id="inv-email"
                           name="invited_email"
                           placeholder="<?= htmlspecialchars(t('inv_email_ph'), ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off"
                           maxlength="254">
                    <span class="input-help"><?= htmlspecialchars(t('inv_email_help'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="width:auto;padding:0 28px;">
                <?= htmlspecialchars(t('btn_generate_link'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Invitation list table (shared partial) -->
    <div style="margin-top:32px;">
        <h3 class="section-subtitle"><?= htmlspecialchars(t('inv_list_title'), ENT_QUOTES, 'UTF-8') ?></h3>
        <?php
        // invitations_list_table.php uses $actionUrl (with anchor) and $isOwner.
        $actionUrl = $_invActionUrl;
        require __DIR__ . '/../../views/invitations_list_table.php';
        ?>
    </div>
</section>
