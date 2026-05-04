<?php
/**
 * includes/tabs.php — Tab navigation component.
 *
 * Usage (after requireAuth(), initLang(), requireRole()):
 *   require_once __DIR__ . '/tabs.php';
 *   // then in HTML:
 *   <?= renderTabs('profile') ?>
 *
 * Tab IDs per role:
 *   supplier : profile | summary | documents* | orders*
 *   admin    : users   | reports* | settings*
 *   owner    : users   | reports* | settings*
 *   (* = always disabled until implemented)
 *
 * Supplier first-login rule: tabs other than 'profile' are
 * force-disabled until first_login = 0.
 */

/**
 * Returns tab definitions for the given role.
 * Each entry: ['id', 'label', 'url', 'disabled'(optional bool)]
 */
function getTabsForRole(string $role): array
{
    switch ($role) {
        case 'supplier':
            return [
                [
                    'id'    => 'summary',
                    'label' => t('tab_summary'),
                    'url'   => '/login/supplier/summary.php',
                ],
                [
                    'id'    => 'profile',
                    'label' => t('tab_profile'),
                    'url'   => '/login/supplier/profile.php',
                ],
                [
                    'id'    => 'add_product',
                    'label' => t('tab_add_product'),
                    'url'   => '/login/supplier/add_product.php',
                ],
                [
                    'id'    => 'my_products',
                    'label' => t('tab_my_products'),
                    'url'   => '/login/supplier/products.php',
                ],
                [
                    'id'  => 'documents',
                    'label' => t('tab_documents'),
                    'url'   => '/login/supplier/documents.php',
                ],
                [
                    'id'       => 'orders',
                    'label'    => t('tab_orders'),
                    'url'      => '#',
                    'disabled' => true,
                ],
            ];

        case 'admin':
            return [
                [
                    'id'    => 'all_products',
                    'label' => t('tab_all_products'),
                    'url'   => '/login/admin/products.php',
                ],
                [
                    'id'    => 'users',
                    'label' => t('tab_users'),
                    'url'   => '/login/admin/users.php',
                ],
                [
                    'id'    => 'assignments',
                    'label' => t('tab_assignments'),
                    'url'   => '/login/admin/assignments.php',
                ],
                [
                    'id'       => 'reports',
                    'label'    => t('tab_reports'),
                    'url'      => '#',
                    'disabled' => true,
                ],
                [
                    'id'       => 'settings',
                    'label'    => t('tab_settings'),
                    'url'      => '#',
                    'disabled' => true,
                ],
            ];

        case 'support':
            return [
                [
                    'id'    => 'all_products',
                    'label' => t('tab_all_products'),
                    'url'   => '/login/admin/products.php',
                ],
                [
                    'id'    => 'users',
                    'label' => t('tab_users'),
                    'url'   => '/login/admin/users.php',
                ],
                [
                    'id'    => 'invitations',
                    'label' => t('tab_invitations'),
                    'url'   => '/login/invitations.php',
                ],
                [
                    'id'    => 'assignments',
                    'label' => t('tab_assignments'),
                    'url'   => '/login/admin/assignments.php',
                ],
            ];

        case 'owner':
            return [
                [
                    'id'    => 'all_products',
                    'label' => t('tab_all_products'),
                    'url'   => '/login/admin/products.php',
                ],
                [
                    'id'    => 'users',
                    'label' => t('tab_users'),
                    'url'   => '/login/owner/users.php',
                ],
                [
                    'id'    => 'business_units',
                    'label' => t('tab_business_units'),
                    'url'   => '/login/owner/business_units.php',
                ],
                [
                    'id'    => 'assignments',
                    'label' => t('tab_assignments'),
                    'url'   => '/login/admin/assignments.php',
                ],
                [
                    'id'       => 'reports',
                    'label'    => t('tab_reports'),
                    'url'      => '#',
                    'disabled' => true,
                ],
                [
                    'id'       => 'settings',
                    'label'    => t('tab_settings'),
                    'url'      => '#',
                    'disabled' => true,
                ],
            ];

        default:
            return [];
    }
}

/**
 * Renders the role-aware tab navigation bar and returns the HTML string.
 *
 * @param string $activePage  ID of the currently active tab (must match one of
 *                            the IDs returned by getTabsForRole()).
 * @return string             HTML <nav> element (empty string if role has no tabs).
 */
function renderTabs(string $activePage): string
{
    $role       = $_SESSION['role']        ?? '';
    $firstLogin = (int) ($_SESSION['first_login'] ?? 0);
    $tabs       = getTabsForRole($role);

    if (empty($tabs)) {
        return '';
    }

    $navLabel = htmlspecialchars(t('tab_nav_label'), ENT_QUOTES, 'UTF-8');
    $safeRole = in_array($role, ['owner', 'admin', 'support', 'supplier'], true) ? $role : '';
    $roleClass = $safeRole !== '' ? ' tab-nav--role-' . htmlspecialchars($safeRole, ENT_QUOTES, 'UTF-8') : '';
    $roleAttr  = $safeRole !== '' ? ' data-role="' . htmlspecialchars($safeRole, ENT_QUOTES, 'UTF-8') . '"' : '';

    $html     = '<nav class="tab-nav' . $roleClass . '" aria-label="' . $navLabel . '"' . $roleAttr . '>' . "\n";

    foreach ($tabs as $tab) {
        $isActive   = ($tab['id'] === $activePage);
        $isDisabled = !empty($tab['disabled']);

        // Supplier first-login rule: lock every tab except 'profile'
        if ($role === 'supplier' && $firstLogin === 1 && $tab['id'] !== 'profile') {
            $isDisabled = true;
        }

        $label = htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8');

        if ($isActive) {
            $html .= '    <span class="tab-item tab-item--active" aria-current="page">'
                   . $label . '</span>' . "\n";
        } elseif ($isDisabled) {
            $soon  = htmlspecialchars(t('tab_coming_soon'), ENT_QUOTES, 'UTF-8');
            $html .= '    <span class="tab-item tab-item--disabled" aria-disabled="true" title="' . $soon . '">'
                   . $label . '</span>' . "\n";
        } else {
            $url   = htmlspecialchars($tab['url'], ENT_QUOTES, 'UTF-8');
            $html .= '    <a href="' . $url . '" class="tab-item">'
                   . $label . '</a>' . "\n";
        }
    }

    $html .= '</nav>' . "\n";

    // Support-only BU selector (displayed only if support has >1 assigned BU).
    if ($safeRole === 'support') {
        $supportOrgs = $_SESSION['support_orgs'] ?? [];
        if (is_array($supportOrgs) && count($supportOrgs) > 1) {
            $currentOrgId = (int) ($_SESSION['org_id'] ?? 0);
            $html .= '<div class="support-org-switch" style="margin:10px 0 0;display:flex;align-items:center;gap:8px;">';
            $html .= '<form method="POST" action="/login/switch_org.php" style="display:flex;gap:8px;align-items:center;">';
            $html .= csrfField();
            $html .= '<input type="hidden" name="return_to" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/login/admin/products.php', ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<label for="support-org-switcher" style="font-size:.85rem;opacity:.8;">Business Unit:</label>';
            $html .= '<select id="support-org-switcher" name="org_id" class="filter-select" style="min-width:180px;">';
            foreach ($supportOrgs as $org) {
                $oid = (int) ($org['id'] ?? 0);
                if ($oid <= 0) {
                    continue;
                }
                $selected = $oid === $currentOrgId ? ' selected' : '';
                $html .= '<option value="' . $oid . '"' . $selected . '>'
                      . htmlspecialchars((string) ($org['name'] ?? ('BU #' . $oid)), ENT_QUOTES, 'UTF-8')
                      . '</option>';
            }
            $html .= '</select>';
            $html .= '<button type="submit" class="btn-secondary btn-sm">Switch</button>';
            $html .= '</form>';
            $html .= '</div>' . "\n";
        }
    }

    if ($safeRole !== '') {
        $roleJs = json_encode($safeRole, JSON_UNESCAPED_UNICODE);
        $html .= '<script>(function(){var r=' . $roleJs . ';if(!r||!document.body)return;document.body.classList.add("role-"+r);document.body.setAttribute("data-role",r);})();</script>' . "\n";
    }

    return $html;
}

