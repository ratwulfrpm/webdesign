<?php
/**
 * /login/admin/assignments.php — Multi-product quotation management
 *
 * Access  : role = 'admin' OR role = 'owner'
 * Purpose : Search products, select multiple, configure pricing, generate a
 *           secure token-based quotation link for a customer client.
 *
 * Security:
 *  - SHA-256 hash of token stored in DB — never the plain token.
 *  - All prices computed server-side from DB; frontend preview is informational only.
 *  - No product_id accepted from URL in the public quote view (anti-IDOR).
 *  - Prepared statements for all queries.
 *  - CSRF on every POST.
 *  - XSS: all output escaped.
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => false,
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tabs.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/org_scope.php';

requireAuth();
initLang();
requireRole(['admin', 'owner']);

$pdo    = getDB();
$userId = (int) $_SESSION['user_id'];
$orgId  = (int) ($_SESSION['org_id'] ?? 0);
$role   = (string) ($_SESSION['role'] ?? '');
$lang   = currentLang();
$accessibleOrgs = loadAccessibleOrganizations($pdo, $userId, $role);
$accessibleOrgIds = array_map('intval', array_column($accessibleOrgs, 'id'));
$assignmentOrgId = orgScopeContainsOrgId($accessibleOrgIds, $orgId)
    ? $orgId
    : (int) ($accessibleOrgIds[0] ?? 0);

// ── Helper: build public quote URL ───────────────────────────
function buildQuoteLink(string $plainToken): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/login/quote.php?t=' . rawurlencode($plainToken);
}

function buildQuoteQrUrl(string $quoteLink, int $size = 260): string
{
    $size = max(120, min(600, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&data=' . rawurlencode($quoteLink);
}

// ═════════════════════════════════════════════════════════════
//  POST HANDLER
// ═════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = $_POST['action'] ?? '';

    // ─────────────────────────────────────────────────────────
    //  CREATE ASSIGNMENT (multi-product)
    // ─────────────────────────────────────────────────────────
    if ($action === 'create_assignment') {

        $errors = [];
        $targetOrgId = (int) ($_POST['org_id'] ?? $assignmentOrgId);

        if (!orgScopeContainsOrgId($accessibleOrgIds, $targetOrgId)) {
            $errors[] = t('error_no_org');
        }

        // 1. Customer name (required)
        $customerName = mb_substr(trim($_POST['customer_name'] ?? ''), 0, 200);
        if ($customerName === '') {
            $errors[] = t('asgn_err_customer_required');
        }

        // 2. Company (optional)
        $companyName = mb_substr(trim($_POST['company_name'] ?? ''), 0, 200);

        // 3. Special conditions (optional)
        $specialConditions = mb_substr(trim($_POST['special_conditions'] ?? ''), 0, 2000);

        // 4. Discount percentage (optional, 0–100)
        $discountRaw = trim($_POST['discount_percentage'] ?? '');
        $discountPct = null;
        if ($discountRaw !== '') {
            if (!is_numeric($discountRaw) || (float)$discountRaw < 0 || (float)$discountRaw > 100) {
                $errors[] = t('asgn_err_discount_invalid');
            } else {
                $discountPct = round((float)$discountRaw, 2);
            }
        }

        // 5. Price base type (whitelist)
        $baseType = strtolower(trim($_POST['price_base_type'] ?? ''));
        if (!in_array($baseType, ['fob', 'cif'], true)) {
            $errors[] = t('asgn_err_base_invalid');
        }

        // 6. Profit (now with calculation type)
        $profitType = strtolower(trim($_POST['profit_calculation_type'] ?? ''));
        $profitPct = null;
        $profitAmt = null;
        if ($profitType !== '') {
            if (!in_array($profitType, ['percentage', 'fixed_amount'], true)) {
                $errors[] = t('asgn_err_invalid_fee');
            } elseif ($profitType === 'percentage') {
                $profitRaw = trim($_POST['profit_percentage'] ?? '');
                if ($profitRaw !== '' && is_numeric($profitRaw)) {
                    $profitPct = (float) $profitRaw;
                    if ($profitPct < 0 || $profitPct > 999) {
                        $errors[] = t('asgn_err_profit_invalid');
                    }
                } else {
                    $errors[] = t('asgn_err_profit_invalid');
                }
            } elseif ($profitType === 'fixed_amount') {
                $profitRaw = trim($_POST['profit_fixed_amount'] ?? '');
                if ($profitRaw !== '' && is_numeric($profitRaw)) {
                    $profitAmt = round((float) $profitRaw, 2);
                    if ($profitAmt < 0) {
                        $errors[] = t('asgn_err_invalid_fee');
                    }
                } else {
                    $errors[] = t('asgn_err_invalid_fee');
                }
            }
        }
        if ($profitType === '' || ($profitType === 'percentage' && $profitPct === null) || 
            ($profitType === 'fixed_amount' && $profitAmt === null)) {
            $errors[] = t('asgn_err_profit_required');
        }

        // 6b. Transport (optional)
        $transportType = strtolower(trim($_POST['transport_calculation_type'] ?? ''));
        $transportPct = null;
        $transportAmt = null;
        if ($transportType !== '') {
            if (!in_array($transportType, ['percentage', 'fixed_amount'], true)) {
                $errors[] = t('asgn_err_invalid_transport');
            } elseif ($transportType === 'percentage') {
                $transportRaw = trim($_POST['transport_percentage'] ?? '');
                if ($transportRaw !== '' && is_numeric($transportRaw)) {
                    $transportPct = (float) $transportRaw;
                    if ($transportPct < 0 || $transportPct > 100) {
                        $errors[] = t('asgn_err_invalid_transport');
                    }
                }
            } elseif ($transportType === 'fixed_amount') {
                $transportRaw = trim($_POST['transport_fixed_amount'] ?? '');
                if ($transportRaw !== '' && is_numeric($transportRaw)) {
                    $transportAmt = round((float) $transportRaw, 2);
                    if ($transportAmt < 0) {
                        $errors[] = t('asgn_err_invalid_transport');
                    }
                }
            }
        }

        // 6c. Tax (optional)
        $taxType = strtolower(trim($_POST['tax_calculation_type'] ?? ''));
        $taxPct = null;
        $taxAmt = null;
        if ($taxType !== '') {
            if (!in_array($taxType, ['percentage', 'fixed_amount'], true)) {
                $errors[] = t('asgn_err_invalid_tax');
            } elseif ($taxType === 'percentage') {
                $taxRaw = trim($_POST['tax_percentage'] ?? '');
                if ($taxRaw !== '' && is_numeric($taxRaw)) {
                    $taxPct = (float) $taxRaw;
                    if ($taxPct < 0 || $taxPct > 100) {
                        $errors[] = t('asgn_err_invalid_tax');
                    }
                }
            } elseif ($taxType === 'fixed_amount') {
                $taxRaw = trim($_POST['tax_fixed_amount'] ?? '');
                if ($taxRaw !== '' && is_numeric($taxRaw)) {
                    $taxAmt = round((float) $taxRaw, 2);
                    if ($taxAmt < 0) {
                        $errors[] = t('asgn_err_invalid_tax');
                    }
                }
            }
        }

        // 6d. Validity (hours/days, max 7 days)
        $validityAmount = (int) ($_POST['validity_amount'] ?? 7);
        $validityUnit = strtolower(trim($_POST['validity_unit'] ?? 'days'));
        if (!in_array($validityUnit, ['hours', 'days'], true)) {
            $validityUnit = 'days';
        }
        $validityHours = $validityUnit === 'hours' ? $validityAmount : ($validityAmount * 24);
        if ($validityHours <= 0 || $validityHours > 168) {
            $errors[] = t('asgn_err_validity_exceeded');
        }

        // 6e. Max visits (optional, if set must be positive)
        $maxVisits = null;
        $maxVisitsRaw = trim($_POST['max_visits'] ?? '');
        if ($maxVisitsRaw !== '') {
            if (!is_numeric($maxVisitsRaw)) {
                $errors[] = t('asgn_err_max_visits_invalid');
            } else {
                $maxVisits = (int) $maxVisitsRaw;
                if ($maxVisits <= 0) {
                    $errors[] = t('asgn_err_max_visits_invalid');
                }
            }
        }

        // 7. Product IDs (must be array of positive ints)
        $rawProductIds = $_POST['product_ids'] ?? [];
        if (!is_array($rawProductIds)) {
            $rawProductIds = [];
        }
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $rawProductIds),
            fn($id) => $id > 0
        )));
        if (empty($productIds)) {
            $errors[] = t('asgn_err_no_products');
        }

        // If validation failed — redirect with errors
        if (!empty($errors)) {
            $_SESSION['asgn_errors'] = $errors;
            header('Location: /login/admin/assignments.php');
            exit;
        }

        // 8. Load all selected products from DB (authoritative source)
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $prodStmt     = $pdo->prepare(
            "SELECT id, product_name, price_fob, price_cif, active
               FROM supplier_products
              WHERE org_id = ? AND id IN ({$placeholders}) AND active = 1"
        );
          $prodStmt->execute(array_merge([$targetOrgId], $productIds));
        $validProducts = [];
        foreach ($prodStmt->fetchAll() as $p) {
            $validProducts[(int)$p['id']] = $p;
        }

        // Verify all requested IDs exist and are active
        foreach ($productIds as $pid) {
            if (!isset($validProducts[$pid])) {
                $errors[] = t('asgn_err_product_invalid');
                break;
            }
        }

        // Verify base price exists for each product
        foreach ($productIds as $pid) {
            if (!isset($validProducts[$pid])) continue;
            $p = $validProducts[$pid];
            if ($baseType === 'fob' && ($p['price_fob'] === null || $p['price_fob'] === '')) {
                $errors[] = sprintf('%s: %s', $p['product_name'], t('asgn_err_no_fob'));
            } elseif ($baseType === 'cif' && ($p['price_cif'] === null || $p['price_cif'] === '')) {
                $errors[] = sprintf('%s: %s', $p['product_name'], t('asgn_err_no_cif'));
            }
        }

        if (!empty($errors)) {
            $_SESSION['asgn_errors'] = $errors;
            header('Location: /login/admin/assignments.php');
            exit;
        }

        // 9. Generate token
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $validFrom  = date('Y-m-d H:i:s');
        $expiresAt  = date('Y-m-d H:i:s', time() + ($validityHours * 3600));

        // 10. Insert into DB (transaction)
        try {
            $pdo->beginTransaction();

            $insQuote = $pdo->prepare(
                'INSERT INTO quote_assignments
                    (org_id, assigned_customer_name, company_name,
                     special_conditions, discount_percentage,
                     profit_calculation_type, profit_percentage, profit_fixed_amount,
                     transport_calculation_type, transport_percentage, transport_fixed_amount,
                     tax_calculation_type, tax_percentage, tax_fixed_amount,
                     validity_amount, validity_unit, max_visits,
                     token_hash, status, valid_from, expires_at, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?, ?)'
            );
            $insQuote->execute([
                $targetOrgId,
                $customerName,
                $companyName !== '' ? $companyName : null,
                $specialConditions !== '' ? $specialConditions : null,
                $discountPct,
                $profitType,
                $profitPct,
                $profitAmt,
                $transportType !== '' ? $transportType : null,
                $transportPct,
                $transportAmt,
                $taxType !== '' ? $taxType : null,
                $taxPct,
                $taxAmt,
                $validityAmount,
                $validityUnit,
                $maxVisits,
                $tokenHash,
                $validFrom,
                $expiresAt,
                $userId,
            ]);
            $quoteId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO quote_assignment_items
                    (quote_assignment_id, product_id, price_base_type,
                     price_base_amount, profit_calculation_type, profit_percentage, profit_fixed_amount, final_unit_price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $itemSummary = [];
            foreach ($productIds as $pid) {
                $p          = $validProducts[$pid];
                $baseAmount = $baseType === 'fob' ? (float)$p['price_fob'] : (float)$p['price_cif'];
                
                // Calculate final price based on profit type
                if ($profitType === 'percentage' && $profitPct !== null) {
                    $finalPrice = round($baseAmount * (1 + $profitPct / 100), 2);
                } elseif ($profitType === 'fixed_amount' && $profitAmt !== null) {
                    $finalPrice = round($baseAmount + $profitAmt, 2);
                } else {
                    $finalPrice = $baseAmount;
                }
                
                $insItem->execute([
                    $quoteId, $pid, $baseType,
                    $baseAmount, $profitType, $profitPct, $profitAmt, $finalPrice,
                ]);
                $itemSummary[] = ['name' => $p['product_name'], 'price' => $finalPrice];
            }

            $pdo->commit();

            auditLog('assignment_created', 'info', $quoteId, $userId, [
                'org_id'           => $targetOrgId,
                'customer'         => $customerName,
                'company'          => $companyName,
                'product_count'    => count($productIds),
                'base_type'        => $baseType,
                'profit_type'      => $profitType,
                'profit_value'     => $profitType === 'percentage' ? $profitPct : $profitAmt,
                'transport_type'   => $transportType,
                'tax_type'         => $taxType,
                'validity_hours'   => $validityHours,
                'max_visits'       => $maxVisits,
                'discount_pct'     => $discountPct,
                'expires_at'       => $expiresAt,
            ]);

        } catch (\PDOException $e) {
            $pdo->rollBack();
            error_log('assignments.php create_assignment failed: ' . $e->getMessage());
            $_SESSION['asgn_errors'] = [t('asgn_err_save')];
            header('Location: /login/admin/assignments.php');
            exit;
        }

        // 11. PRG: store link info in session
        $_SESSION['asgn_new_link']         = buildQuoteLink($plainToken);
        $_SESSION['asgn_new_customer']     = $customerName;
        $_SESSION['asgn_new_company']      = $companyName;
        $_SESSION['asgn_new_items']        = $itemSummary;
        $_SESSION['asgn_new_items_count']  = count($itemSummary);
        $_SESSION['asgn_new_items_total']  = array_sum(array_column($itemSummary, 'price'));
        $_SESSION['asgn_new_expires']      = $expiresAt;
        $_SESSION['asgn_feedback']         = t('asgn_success');
        $_SESSION['asgn_feedback_type']    = 'success';

        header('Location: /login/admin/assignments.php');
        exit;

    // ─────────────────────────────────────────────────────────
    //  SHARE ASSIGNMENT LINK AS QR (EMAIL)
    // ─────────────────────────────────────────────────────────
    } elseif ($action === 'send_assignment_qr') {

        $toEmail   = mb_substr(trim($_POST['share_email'] ?? ''), 0, 190);
        $shareLink = trim($_POST['share_link'] ?? '');
        $shareCust = mb_substr(trim($_POST['share_customer'] ?? ''), 0, 200);
        $shareComp = mb_substr(trim($_POST['share_company'] ?? ''), 0, 200);
        $shareExp  = trim($_POST['share_expires'] ?? '');
        $itemCount = (int) ($_POST['share_item_count'] ?? 0);
        $itemsTot  = (float) ($_POST['share_items_total'] ?? 0);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['asgn_share_feedback'] = t('asgn_link_err_invalid_email');
            $_SESSION['asgn_share_feedback_type'] = 'error';
        } elseif ($shareLink === '' || !filter_var($shareLink, FILTER_VALIDATE_URL)) {
            $_SESSION['asgn_share_feedback'] = t('asgn_link_err_invalid_url');
            $_SESSION['asgn_share_feedback_type'] = 'error';
        } else {
            $qrUrl = buildQuoteQrUrl($shareLink, 300);
            $sent = sendAssignmentQrEmail(
                $toEmail,
                $shareLink,
                $qrUrl,
                $shareCust,
                $shareComp,
                $lang
            );

            if (!empty($sent['sent'])) {
                $_SESSION['asgn_share_feedback'] = t('asgn_link_mail_sent');
                $_SESSION['asgn_share_feedback_type'] = 'success';
            } elseif (!empty($sent['logged'])) {
                $_SESSION['asgn_share_feedback'] = t('asgn_link_mail_logged');
                $_SESSION['asgn_share_feedback_type'] = 'info';
            } else {
                $_SESSION['asgn_share_feedback'] = t('asgn_link_mail_error');
                $_SESSION['asgn_share_feedback_type'] = 'error';
            }
        }

        // Keep the generated-link card visible after share submit (PRG).
        $_SESSION['asgn_new_link']         = $shareLink;
        $_SESSION['asgn_new_customer']     = $shareCust;
        $_SESSION['asgn_new_company']      = $shareComp;
        $_SESSION['asgn_new_items_count']  = max(0, $itemCount);
        $_SESSION['asgn_new_items_total']  = max(0, round($itemsTot, 2));
        $_SESSION['asgn_new_expires']      = $shareExp;

        header('Location: /login/admin/assignments.php');
        exit;

    // ─────────────────────────────────────────────────────────
    //  REVOKE
    // ─────────────────────────────────────────────────────────
    } elseif ($action === 'revoke_assignment') {

        $quoteId = (int) ($_POST['assignment_id'] ?? 0);
        if ($quoteId <= 0) {
            $_SESSION['asgn_feedback']      = t('asgn_err_assignment_invalid');
            $_SESSION['asgn_feedback_type'] = 'error';
            header('Location: /login/admin/assignments.php');
            exit;
        }
        try {
            if (empty($accessibleOrgIds)) {
                throw new \PDOException('No accessible organizations for revoke');
            }
            $placeholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
            $upd = $pdo->prepare(
                "UPDATE quote_assignments
                    SET status = 'revoked', revoked_at = NOW(),
                        revoked_by_user_id = ?, updated_at = NOW()
                  WHERE id = ?
                    AND org_id IN ({$placeholders})
                    AND status = 'active'"
            );
            $upd->execute(array_merge([$userId, $quoteId], $accessibleOrgIds));
            if ($upd->rowCount() > 0) {
                auditLog('assignment_revoked', 'info', $quoteId, $userId);
                $_SESSION['asgn_feedback']      = t('asgn_revoked_success');
                $_SESSION['asgn_feedback_type'] = 'success';
            } else {
                $_SESSION['asgn_feedback']      = t('asgn_err_revoke_failed');
                $_SESSION['asgn_feedback_type'] = 'error';
            }
        } catch (\PDOException $e) {
            error_log('assignments.php revoke failed: ' . $e->getMessage());
            $_SESSION['asgn_feedback']      = t('asgn_err_revoke_failed');
            $_SESSION['asgn_feedback_type'] = 'error';
        }
        header('Location: /login/admin/assignments.php');
        exit;

    // ─────────────────────────────────────────────────────────
    //  SOFT-DELETE
    // ─────────────────────────────────────────────────────────
    } elseif ($action === 'delete_assignment') {

        $quoteId = (int) ($_POST['assignment_id'] ?? 0);
        if ($quoteId <= 0) {
            $_SESSION['asgn_feedback']      = t('asgn_err_assignment_invalid');
            $_SESSION['asgn_feedback_type'] = 'error';
            header('Location: /login/admin/assignments.php');
            exit;
        }
        try {
            if (empty($accessibleOrgIds)) {
                throw new \PDOException('No accessible organizations for delete');
            }
            $placeholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
            $upd = $pdo->prepare(
                "UPDATE quote_assignments
                    SET status = 'deleted', deleted_at = NOW(),
                        deleted_by_user_id = ?, updated_at = NOW()
                  WHERE id = ?
                    AND org_id IN ({$placeholders})
                    AND status IN ('active','expired','revoked')"
            );
            $upd->execute(array_merge([$userId, $quoteId], $accessibleOrgIds));
            if ($upd->rowCount() > 0) {
                auditLog('assignment_deleted', 'info', $quoteId, $userId);
                $_SESSION['asgn_feedback']      = t('asgn_deleted_success');
                $_SESSION['asgn_feedback_type'] = 'success';
            } else {
                $_SESSION['asgn_feedback']      = t('asgn_err_delete_failed');
                $_SESSION['asgn_feedback_type'] = 'error';
            }
        } catch (\PDOException $e) {
            error_log('assignments.php delete failed: ' . $e->getMessage());
            $_SESSION['asgn_feedback']      = t('asgn_err_delete_failed');
            $_SESSION['asgn_feedback_type'] = 'error';
        }
        header('Location: /login/admin/assignments.php');
        exit;

    // ─────────────────────────────────────────────────────────
    //  REGEN LINK
    // ─────────────────────────────────────────────────────────
    } elseif ($action === 'regen_link') {

        $parentId        = (int) ($_POST['assignment_id'] ?? 0);
        $newCustomerName = mb_substr(trim($_POST['customer_name'] ?? ''), 0, 200);

        if ($parentId <= 0) {
            $_SESSION['asgn_feedback']      = t('asgn_err_assignment_invalid');
            $_SESSION['asgn_feedback_type'] = 'error';
            header('Location: /login/admin/assignments.php');
            exit;
        }
        if ($newCustomerName === '') {
            $_SESSION['asgn_feedback']      = t('asgn_err_customer_required');
            $_SESSION['asgn_feedback_type'] = 'error';
            header('Location: /login/admin/assignments.php');
            exit;
        }

        try {
            // Load parent (must not be deleted)
            if (empty($accessibleOrgIds)) {
                $_SESSION['asgn_feedback']      = t('asgn_err_assignment_invalid');
                $_SESSION['asgn_feedback_type'] = 'error';
                header('Location: /login/admin/assignments.php');
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
            $sel = $pdo->prepare(
                "SELECT id, org_id, company_name, special_conditions, discount_percentage
                   FROM quote_assignments
                  WHERE id = ?
                    AND org_id IN ({$placeholders})
                    AND status != 'deleted'
                  LIMIT 1"
            );
            $sel->execute(array_merge([$parentId], $accessibleOrgIds));
            $parent = $sel->fetch();

            if (!$parent) {
                $_SESSION['asgn_feedback']      = t('asgn_err_assignment_invalid');
                $_SESSION['asgn_feedback_type'] = 'error';
                header('Location: /login/admin/assignments.php');
                exit;
            }

            // Load parent items
            $itemsSel = $pdo->prepare(
                'SELECT product_id, price_base_type, price_base_amount,
                        profit_percentage, final_unit_price
                   FROM quote_assignment_items
                  WHERE quote_assignment_id = ?'
            );
            $itemsSel->execute([$parentId]);
            $parentItems = $itemsSel->fetchAll();

            // Generate new token
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash  = hash('sha256', $plainToken);
            $validFrom  = date('Y-m-d H:i:s');
            $expiresAt  = date('Y-m-d H:i:s', strtotime('+7 days'));

            $pdo->beginTransaction();

            $insQuote = $pdo->prepare(
                'INSERT INTO quote_assignments
                    (org_id, assigned_customer_name, company_name,
                     special_conditions, discount_percentage,
                     token_hash, status, valid_from, expires_at,
                     created_by_user_id, parent_quote_id)
                 VALUES (?, ?, ?, ?, ?, ?, "active", ?, ?, ?, ?)'
            );
            $insQuote->execute([
                $parent['org_id'],
                $newCustomerName,
                $parent['company_name'],
                $parent['special_conditions'],
                $parent['discount_percentage'],
                $tokenHash,
                $validFrom,
                $expiresAt,
                $userId,
                $parentId,
            ]);
            $newQuoteId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO quote_assignment_items
                    (quote_assignment_id, product_id, price_base_type,
                     price_base_amount, profit_percentage, final_unit_price)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $itemSummary = [];
            foreach ($parentItems as $item) {
                $insItem->execute([
                    $newQuoteId,
                    $item['product_id'],
                    $item['price_base_type'],
                    $item['price_base_amount'],
                    $item['profit_percentage'],
                    $item['final_unit_price'],
                ]);
                $itemSummary[] = ['price' => (float)$item['final_unit_price']];
            }

            $pdo->commit();

            auditLog('assignment_link_regenerated', 'info', $newQuoteId, $userId, [
                'parent_id' => $parentId, 'customer' => $newCustomerName,
            ]);

            $_SESSION['asgn_new_link']      = buildQuoteLink($plainToken);
            $_SESSION['asgn_new_customer']  = $newCustomerName;
            $_SESSION['asgn_new_company']   = $parent['company_name'] ?? '';
            $_SESSION['asgn_new_items']     = $itemSummary;
            $_SESSION['asgn_new_items_count'] = count($itemSummary);
            $_SESSION['asgn_new_items_total'] = array_sum(array_column($itemSummary, 'price'));
            $_SESSION['asgn_new_expires']   = $expiresAt;
            $_SESSION['asgn_feedback']      = t('asgn_regen_success');
            $_SESSION['asgn_feedback_type'] = 'success';

        } catch (\PDOException $e) {
            $pdo->rollBack();
            error_log('assignments.php regen_link failed: ' . $e->getMessage());
            $_SESSION['asgn_feedback']      = t('asgn_err_save');
            $_SESSION['asgn_feedback_type'] = 'error';
        }
        header('Location: /login/admin/assignments.php');
        exit;
    }
}

// ─── Flash messages ───────────────────────────────────────────
$feedback     = $_SESSION['asgn_feedback']      ?? null;
$feedbackType = $_SESSION['asgn_feedback_type'] ?? 'info';
$formErrors   = $_SESSION['asgn_errors']        ?? [];
$newLink      = $_SESSION['asgn_new_link']      ?? null;
$newCustomer  = $_SESSION['asgn_new_customer']  ?? null;
$newCompany   = $_SESSION['asgn_new_company']   ?? null;
$newItems     = $_SESSION['asgn_new_items']     ?? [];
$newItemsCount = $_SESSION['asgn_new_items_count'] ?? null;
$newItemsTotal = $_SESSION['asgn_new_items_total'] ?? null;
$newExpires   = $_SESSION['asgn_new_expires']   ?? null;
$shareFeedback = $_SESSION['asgn_share_feedback'] ?? null;
$shareFeedbackType = $_SESSION['asgn_share_feedback_type'] ?? 'info';
unset(
    $_SESSION['asgn_feedback'], $_SESSION['asgn_feedback_type'],
    $_SESSION['asgn_errors'],
    $_SESSION['asgn_new_link'],  $_SESSION['asgn_new_customer'],
    $_SESSION['asgn_new_company'], $_SESSION['asgn_new_items'],
    $_SESSION['asgn_new_items_count'], $_SESSION['asgn_new_items_total'],
    $_SESSION['asgn_new_expires'],
    $_SESSION['asgn_share_feedback'], $_SESSION['asgn_share_feedback_type']
);

// ─── Recent assignments (last 30, exclude deleted) ────────────
$recentAssignments = [];
if (!empty($accessibleOrgIds)) {
        $placeholders = implode(',', array_fill(0, count($accessibleOrgIds), '?'));
        $recentStmt = $pdo->prepare(
                "SELECT qa.id,
                                qa.assigned_customer_name,
                                qa.company_name,
                                qa.discount_percentage,
                                qa.status,
                                qa.expires_at,
                                qa.view_count,
                                qa.created_at,
                                qa.revoked_at,
                                u.username AS creator_username,
                                o.name AS org_name,
                                COUNT(qi.id) AS item_count,
                                SUM(qi.final_unit_price) AS subtotal
                     FROM quote_assignments qa
                     JOIN users u ON u.id = qa.created_by_user_id
                     JOIN organizations o ON o.id = qa.org_id
                     LEFT JOIN quote_assignment_items qi ON qi.quote_assignment_id = qa.id
                    WHERE qa.status != 'deleted'
                        AND qa.org_id IN ({$placeholders})
                    GROUP BY qa.id, qa.assigned_customer_name, qa.company_name,
                                     qa.discount_percentage, qa.status, qa.expires_at,
                                     qa.view_count, qa.created_at, qa.revoked_at, u.username, o.name
                    ORDER BY qa.created_at DESC
                    LIMIT 30"
        );
        $recentStmt->execute($accessibleOrgIds);
        $recentAssignments = $recentStmt->fetchAll();
}

// ─── View helpers ─────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial  = strtoupper(substr($username, 0, 1));
$orgName  = htmlspecialchars($_SESSION['org_name'] ?? '', ENT_QUOTES, 'UTF-8');
$csrf     = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
$fmtPrice = fn($v) => '$ ' . number_format((float) $v, 2);

$statusClass = [
    'active'  => 'status-badge--active',
    'expired' => 'status-badge--inactive',
    'revoked' => 'status-badge--inactive',
    'deleted' => 'status-badge--inactive',
];

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= $esc(t('asgn_page_title')) ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=15">
    <style>
        /* ── Layout ── */
        .asgn-layout { display:flex; gap:24px; align-items:flex-start; }
        .asgn-col-search { flex:1 1 0; min-width:0; }
        .asgn-col-form   { flex:1 1 0; min-width:0; }
        @media (max-width:1100px) {
            .asgn-layout { flex-direction:column; }
            .asgn-col-form { width:100%; }
        }

        /* ── Search panel ── */
        .search-filter-grid {
            display:grid; grid-template-columns:1fr 1fr;
            gap:8px; margin-bottom:10px;
        }
        @media (max-width:600px) { .search-filter-grid { grid-template-columns:1fr; } }
        .search-filter-grid input {
            padding:7px 10px; border:1.5px solid #d0d0d5;
            border-radius:7px; font-size:0.9rem; width:100%; box-sizing:border-box;
        }
        .search-actions { display:flex; gap:8px; margin-top:6px; }

        /* ── Results ── */
        #resultsPanel, #detailPanel { margin-top:12px; }
        .result-grid { display:flex; flex-direction:column; gap:8px; }
        .result-card {
            display:flex; gap:12px; align-items:flex-start;
            padding:10px 12px; border:1.5px solid #e5e5ea;
            border-radius:10px; background:#fff;
            transition:border-color 0.15s;
        }
        .result-card.is-selected { border-color:#0071e3; background:#f0f7ff; }
        .result-thumb {
            width:56px; height:56px; object-fit:cover;
            border-radius:6px; flex-shrink:0;
            border:1px solid #e5e5ea; background:#f5f5f7;
        }
        .result-thumb-placeholder {
            width:56px; height:56px; border-radius:6px;
            background:#f0f0f5; flex-shrink:0; display:flex;
            align-items:center; justify-content:center;
            border:1px solid #e5e5ea;
        }
        .result-thumb-placeholder svg { opacity:0.25; }
        .result-body { flex:1; min-width:0; }
        .result-name { font-weight:600; font-size:0.93rem; color:#1d1d1f; margin-bottom:2px; }
        .result-code { font-size:0.78rem; font-family:monospace; color:#0071e3; background:#eaf4ff;
                       padding:1px 5px; border-radius:4px; display:inline-block; margin-bottom:3px; }
        .result-meta { font-size:0.8rem; color:#666; }
        .result-prices { font-size:0.8rem; color:#555; margin-top:3px; }
        .result-price-badge {
            display:inline-block; padding:1px 7px; border-radius:10px;
            background:#f0f0f5; margin-right:4px; font-weight:600;
        }
        .result-actions { display:flex; gap:6px; flex-shrink:0; flex-direction:column; align-items:flex-end; }
        .result-keywords { font-size:0.75rem; color:#888; margin-top:2px; }

        /* ── Pagination ── */
        .result-pagination { display:flex; gap:6px; margin-top:10px; align-items:center; flex-wrap:wrap; }
        .pg-btn {
            padding:5px 12px; border:1.5px solid #d0d0d5; border-radius:6px;
            background:#fff; cursor:pointer; font-size:0.85rem;
            transition:background 0.12s;
        }
        .pg-btn:hover, .pg-btn.active { background:#0071e3; border-color:#0071e3; color:#fff; }
        .pg-info { font-size:0.83rem; color:#888; }

        /* ── Detail panel ── */
        .detail-panel {
            border:1.5px solid #e5e5ea; border-radius:12px;
            padding:18px 20px; background:#fff;
        }
        .detail-images { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .detail-img {
            width:80px; height:80px; object-fit:cover;
            border-radius:6px; border:1px solid #e5e5ea; cursor:zoom-in;
        }
        .detail-prices-row { display:flex; gap:12px; flex-wrap:wrap; margin:8px 0; }
        .detail-price-chip {
            padding:5px 14px; border-radius:8px; font-size:0.9rem;
            font-weight:600; background:#f0f0f5;
        }
        .detail-desc {
            font-size:0.9rem; color:#333; white-space:pre-line;
            line-height:1.6; max-height:180px; overflow-y:auto;
        }
        .detail-keywords { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; }
        .detail-kw-chip {
            padding:2px 10px; border-radius:14px;
            background:#f0f0f5; font-size:0.79rem; color:#3a3a4a;
        }

        /* ── Selected products panel ── */
        .selected-panel {
            border:1.5px solid #0071e3; border-radius:12px;
            padding:14px 16px; background:#f0f7ff;
            margin-bottom:16px;
        }
        .selected-item {
            display:flex; align-items:center; gap:8px;
            padding:7px 0; border-bottom:1px solid #d6e8fa;
        }
        .selected-item:last-child { border-bottom:none; }
        .selected-item-name { flex:1; font-size:0.9rem; color:#1d1d1f; }
        .selected-item-price { font-size:0.88rem; font-weight:600; color:#0071e3; }
        .selected-total-row {
            display:flex; justify-content:flex-end; gap:12px;
            margin-top:10px; padding-top:8px; border-top:2px solid #b8d6f5;
            font-size:0.95rem; font-weight:700; color:#1d1d1f;
        }

        /* ── Form field styles (matches global input-wrap pattern) ── */
        .form-label {
            display:block; font-size:0.8125rem; font-weight:600;
            color:var(--color-text-muted); margin-bottom:6px; letter-spacing:0.01em;
        }
        .form-input {
            width:100%; height:48px; padding:0 16px;
            font-family:var(--font-system); font-size:0.9375rem; color:var(--color-text);
            background:#f9f9fb; border:1.5px solid var(--color-border);
            border-radius:var(--radius-input); outline:none; box-sizing:border-box;
            transition:border-color var(--transition),background var(--transition),box-shadow var(--transition);
            -webkit-appearance:none; appearance:none;
        }
        .form-input:hover { border-color:#b0b0b8; }
        .form-input:focus {
            background:#fff; border-color:var(--color-border-focus);
            box-shadow:0 0 0 3px rgba(0,113,227,0.15);
        }
        textarea.form-input { height:auto; padding:12px 16px; resize:vertical; }
        select.form-input {
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236e6e73' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat:no-repeat; background-position:right 14px center; cursor:pointer;
        }
        .form-help { font-size:0.8rem; color:var(--color-text-muted); margin-top:5px; display:block; }
        .form-group { margin-bottom:18px; }

        /* ── Form section dividers ── */
        .asgn-form-section { margin-bottom:24px; padding-bottom:8px; }
        .asgn-form-section-title {
            font-size:0.75rem; font-weight:700; text-transform:uppercase;
            letter-spacing:0.07em; color:#6e6e73;
            padding-bottom:8px; margin-bottom:16px;
            border-bottom:1.5px solid #e5e5ea;
            display:flex; align-items:center; gap:7px;
        }
        .asgn-form-section-title svg { flex-shrink:0; opacity:0.6; }

        /* ── Price config ── */
        .base-btn-group { display:flex; gap:8px; margin-top:6px; }
        .base-btn {
            flex:1; height:44px; padding:0 14px;
            border:1.5px solid var(--color-border);
            border-radius:var(--radius-input); background:#f9f9fb; cursor:pointer;
            font-size:0.9rem; font-weight:500; text-align:center;
            transition:border-color 0.15s, background 0.15s, color 0.15s;
        }
        .base-btn:hover { border-color:var(--color-accent); background:#eaf4ff; }
        .base-btn.selected { background:var(--color-accent); border-color:var(--color-accent); color:#fff; font-weight:600; }
        .profit-grid { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
        .profit-btn {
            height:36px; padding:0 16px;
            border:1.5px solid var(--color-border); border-radius:18px;
            background:#f9f9fb; cursor:pointer; font-size:0.85rem; font-weight:500;
            white-space:nowrap;
            transition:border-color 0.15s, background 0.15s, color 0.15s;
        }
        .profit-btn:hover { border-color:var(--color-accent); background:#eaf4ff; }
        .profit-btn.selected { background:var(--color-accent); border-color:var(--color-accent); color:#fff; font-weight:600; }
        .free-profit-row { display:none; align-items:center; gap:8px; margin-top:8px; }
        .free-profit-row.visible { display:flex; }
        .free-profit-input {
            flex:1; height:44px; padding:0 14px;
            border:1.5px solid var(--color-border); border-radius:var(--radius-input);
            background:#f9f9fb; font-size:0.9rem; outline:none; box-sizing:border-box;
            font-family:var(--font-system);
            transition:border-color var(--transition),background var(--transition);
        }
        .free-profit-input:focus {
            background:#fff; border-color:var(--color-border-focus);
            box-shadow:0 0 0 3px rgba(0,113,227,0.15);
        }
        .fee-unit-label { font-size:0.9rem; font-weight:600; color:#6e6e73; flex-shrink:0; }

        /* ── Action buttons on list ── */
        .asgn-actions { display:flex; gap:4px; flex-wrap:wrap; }
        .btn-asgn-action {
            padding:3px 9px; font-size:0.76rem; font-weight:500;
            border-radius:5px; border:1.5px solid transparent;
            cursor:pointer; white-space:nowrap; transition:background 0.12s;
        }
        .btn-asgn-action--danger { background:#fff0f0; border-color:#f5c2c2; color:#c0392b; }
        .btn-asgn-action--danger:hover { background:#fde8e8; border-color:#e74c3c; }
        .btn-asgn-action--primary { background:#eaf4ff; border-color:#b8d6f5; color:#0071e3; }
        .btn-asgn-action--primary:hover { background:#d6ebff; border-color:#0071e3; }

        /* ── Result stats bar ── */
        .result-stats { font-size:0.83rem; color:#666; margin-bottom:6px; }

        /* ── Link result box (elegant card + QR) ── */
        .link-result-box {
            margin-bottom:22px;
            border-radius:16px;
            border:1.5px solid #b8d6f5;
            background:
                radial-gradient(900px 220px at -10% -40%, rgba(0,113,227,0.17), rgba(0,113,227,0) 55%),
                linear-gradient(135deg, #f7fbff 0%, #eef6ff 44%, #f9fcff 100%);
            box-shadow:0 8px 24px rgba(0, 65, 130, 0.09);
            padding:18px;
        }
        .link-result-header {
            display:flex; align-items:center; justify-content:space-between; gap:14px;
            margin-bottom:12px;
        }
        .link-result-title {
            margin:0; font-size:1rem; color:#154273; display:flex; align-items:center; gap:8px;
        }
        .link-result-sub {
            margin:2px 0 0; font-size:0.82rem; color:#4d6480;
        }
        .link-result-body {
            display:grid; grid-template-columns:minmax(0, 1fr) 230px;
            gap:16px;
        }
        @media (max-width:960px) {
            .link-result-body { grid-template-columns:1fr; }
        }
        .link-copy-row { display:flex; gap:8px; }
        .link-copy-input {
            flex:1; height:44px; padding:0 14px;
            border:1.5px solid #c5d9f0;
            border-radius:10px;
            font-size:0.85rem; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; background:#fff;
            color:#0b335f;
        }
        .link-meta {
            margin-top:10px; font-size:0.83rem; color:#264869;
            display:flex; flex-wrap:wrap; gap:10px;
        }
        .link-meta-chip {
            display:inline-flex; align-items:center; gap:6px;
            background:#ffffffc7; border:1px solid #d7e6f5;
            border-radius:999px; padding:6px 12px;
        }
        .link-meta-chip strong { color:#153f6f; }
        .link-share-row {
            display:flex; flex-wrap:wrap; gap:8px; margin-top:12px;
        }
        .link-share-form {
            margin-top:10px;
            display:flex; gap:8px; align-items:center;
            max-width:640px;
        }
        .link-share-email {
            flex:1 1 380px; min-width:300px;
            height:42px; padding:0 12px;
            border:1.5px solid #c5d9f0;
            border-radius:10px;
            background:#fff;
            font-size:0.86rem;
        }
        .link-share-form .btn-share-email {
            width:auto;
            height:42px;
            margin-top:0;
            padding:0 12px;
            font-size:0.8rem;
            font-weight:700;
            border-radius:10px;
            flex-shrink:0;
            border:none;
            color:#fff;
            background:#0071e3;
            cursor:pointer;
            white-space:nowrap;
            letter-spacing:0.01em;
            transition:background 0.15s, box-shadow 0.15s;
        }
        .link-share-form .btn-share-email:hover { background:#0a62be; box-shadow:0 4px 14px rgba(0,113,227,0.28); }
        .link-share-form .btn-share-email:active { transform:translateY(1px); }
        .btn-whatsapp-share {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            height:42px;
            padding:0 16px;
            border-radius:10px;
            border:1px solid #1fa855;
            background:#25d366;
            color:#fff;
            text-decoration:none;
            font-size:0.84rem;
            font-weight:700;
            line-height:1;
            transition:background 0.15s, box-shadow 0.15s, border-color 0.15s;
        }
        .btn-whatsapp-share:hover {
            background:#1ebe5d;
            border-color:#179c4b;
            box-shadow:0 4px 14px rgba(37,211,102,0.35);
        }
        @media (max-width:680px) {
            .link-share-form { flex-direction:column; align-items:stretch; max-width:none; }
            .link-share-email { min-width:0; width:100%; }
            .link-share-form .btn-share-email { width:100%; }
        }
        .share-badge {
            margin-top:10px; display:inline-flex; align-items:center;
            padding:7px 12px; border-radius:999px; font-size:0.82rem;
            font-weight:600;
        }
        .share-badge--success { background:#eaf9ef; color:#256d41; border:1px solid #b8ebc9; }
        .share-badge--error { background:#ffeef0; color:#a3293d; border:1px solid #ffccd5; }
        .share-badge--info { background:#eef5ff; color:#1f4f8a; border:1px solid #c9dcf5; }

        .link-qr-card {
            background:#fff;
            border:1.5px solid #d4e2f3;
            border-radius:14px;
            padding:12px;
            text-align:center;
        }
        .link-qr-img {
            width:100%; max-width:200px; aspect-ratio:1/1; object-fit:contain;
            border-radius:10px; border:1px solid #e6effa; background:#fff;
        }
        .link-qr-caption {
            margin-top:8px; font-size:0.8rem; color:#456180;
        }

        /* ── Regen modal ── */
        .asgn-modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.45); z-index:1000;
            align-items:center; justify-content:center;
        }
        .asgn-modal-overlay.open { display:flex; }
        .asgn-modal {
            background:#fff; border-radius:14px; padding:26px 26px 20px;
            max-width:420px; width:92%; box-shadow:0 8px 40px rgba(0,0,0,0.18);
        }
        .asgn-modal h3 { margin:0 0 14px; font-size:1rem; }
        .asgn-modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
        .asgn-table-wrap { overflow-x:auto; }
        .loading-spinner {
            text-align:center; padding:20px;
            font-size:0.9rem; color:#666;
        }
    </style>
</head>
<body class="wide-layout role-<?= htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <!-- ── Top bar ───────────────────────────────────────────── -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title">
                <?= $username ?>
                <span class="org-badge"><?= $orgName ?></span>
            </span>
        </div>
        <div class="top-bar-right">
            <nav class="top-bar-lang">
                <a href="?set_lang=es" class="lang-btn<?= $lang==='es'?' active':'' ?>" hreflang="es">ES</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=en" class="lang-btn<?= $lang==='en'?' active':'' ?>" hreflang="en">EN</a>
                <span class="lang-sep">|</span>
                <a href="?set_lang=zh" class="lang-btn<?= $lang==='zh'?' active':'' ?>" hreflang="zh">中文</a>
            </nav>
            <form method="POST" action="/login/logout.php" class="top-bar-logout">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
            </form>
        </div>
    </div>

    <?= renderTabs('assignments') ?>

    <div class="page-content">

        <!-- ── Generated link result (flash) ── -->
        <?php if ($newLink): ?>
        <?php
            $itemsCountForCard = $newItemsCount !== null
                ? (int)$newItemsCount
                : count($newItems);
            $itemsTotalForCard = $newItemsTotal !== null
                ? (float)$newItemsTotal
                : (float)array_sum(array_column($newItems, 'price'));
            $qrImageUrl = buildQuoteQrUrl($newLink, 320);
            $waMessage = sprintf(
                t('asgn_link_whatsapp_template'),
                (string)($newCustomer ?: '-'),
                (string)$newLink,
                (string)$qrImageUrl
            );
            $waShareUrl = 'https://wa.me/?text=' . rawurlencode($waMessage);
        ?>
        <div class="link-result-box">
            <div class="link-result-header">
                <div>
                    <h3 class="link-result-title">&#10004; <?= $esc(t('asgn_link_title')) ?></h3>
                    <p class="link-result-sub"><?= $esc(t('asgn_link_share_title')) ?></p>
                </div>
            </div>

            <div class="link-result-body">
                <div>
                    <div class="link-copy-row">
                        <input type="text" class="link-copy-input" id="generatedLink"
                               value="<?= $esc($newLink) ?>" readonly onclick="this.select()">
                        <button type="button" class="btn-secondary btn-sm" onclick="copyAssignmentLink()">
                            <?= $esc(t('asgn_link_copy')) ?>
                        </button>
                    </div>

                    <div class="link-meta">
                        <span class="link-meta-chip"><strong><?= $esc(t('asgn_link_client')) ?></strong><?= $esc($newCustomer) ?></span>
                        <?php if ($newCompany): ?>
                        <span class="link-meta-chip"><strong><?= $esc(t('quote_company_label')) ?>:</strong><?= $esc($newCompany) ?></span>
                        <?php endif; ?>
                        <?php if ($itemsCountForCard > 0): ?>
                        <span class="link-meta-chip">
                            <strong><?= $esc(t('asgn_col_items')) ?>:</strong>
                            <?= $itemsCountForCard ?> · <?= $esc($fmtPrice($itemsTotalForCard)) ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($newExpires): ?>
                        <span class="link-meta-chip"><strong><?= $esc(t('asgn_link_expires')) ?></strong><?= $esc(date('d/m/Y H:i', strtotime($newExpires))) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="link-share-row">
                        <a class="btn-whatsapp-share" href="<?= $esc($waShareUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <?= $esc(t('asgn_link_send_whatsapp_btn')) ?>
                        </a>
                    </div>

                    <form method="POST" action="/login/admin/assignments.php" class="link-share-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="send_assignment_qr">
                        <input type="hidden" name="share_link" value="<?= $esc($newLink) ?>">
                        <input type="hidden" name="share_customer" value="<?= $esc($newCustomer) ?>">
                        <input type="hidden" name="share_company" value="<?= $esc($newCompany) ?>">
                        <input type="hidden" name="share_expires" value="<?= $esc((string)$newExpires) ?>">
                        <input type="hidden" name="share_item_count" value="<?= $itemsCountForCard ?>">
                        <input type="hidden" name="share_items_total" value="<?= $esc((string)round($itemsTotalForCard, 2)) ?>">
                        <input type="email"
                               name="share_email"
                               class="link-share-email"
                               maxlength="190"
                               required
                               placeholder="<?= $esc(t('asgn_link_email_placeholder')) ?>">
                        <button type="submit" class="btn-share-email" title="<?= $esc(t('asgn_link_send_email_btn')) ?>">
                            <?= $esc(t('asgn_link_send_email_btn')) ?>
                        </button>
                    </form>

                    <?php if ($shareFeedback): ?>
                    <div class="share-badge share-badge--<?= $esc($shareFeedbackType) ?>">
                        <?= $esc($shareFeedback) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="link-qr-card">
                    <img class="link-qr-img"
                         src="<?= $esc($qrImageUrl) ?>"
                         alt="QR"
                         loading="lazy">
                    <div class="link-qr-caption"><?= $esc(t('asgn_link_qr_label')) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($feedback && !$newLink): ?>
        <div class="feedback-banner feedback-banner--<?= $esc($feedbackType) ?>" role="alert">
            <?= $esc($feedback) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($formErrors)): ?>
        <div class="feedback-banner feedback-banner--error" role="alert">
            <ul style="margin:0;padding-left:18px;">
                <?php foreach ($formErrors as $e): ?>
                <li><?= $esc($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════
             CREATE QUOTE FORM
        ════════════════════════════════════════════════ -->
        <div class="card profile-form-card" style="max-width:100%;margin-bottom:28px;">
            <h1 class="card-title"><?= $esc(t('asgn_form_title')) ?></h1>
            <p class="card-subtitle"><?= $esc(t('asgn_subtitle')) ?></p>

            <form method="POST" action="/login/admin/assignments.php"
                  id="createForm" onsubmit="return prepareFormSubmit()">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_assignment">
                <input type="hidden" name="price_base_type" id="price_base_type" value="">
                <input type="hidden" name="profit_calculation_type" id="profit_calculation_type" value="">
                <input type="hidden" name="profit_percentage" id="profit_percentage" value="">
                <input type="hidden" name="profit_fixed_amount" id="profit_fixed_amount" value="">
                <input type="hidden" name="transport_calculation_type" id="transport_calculation_type" value="">
                <input type="hidden" name="transport_percentage" id="transport_percentage" value="">
                <input type="hidden" name="transport_fixed_amount" id="transport_fixed_amount" value="">
                <input type="hidden" name="tax_calculation_type" id="tax_calculation_type" value="">
                <input type="hidden" name="tax_percentage" id="tax_percentage" value="">
                <input type="hidden" name="tax_fixed_amount" id="tax_fixed_amount" value="">
                <input type="hidden" name="validity_amount" id="validity_amount" value="7">
                <input type="hidden" name="validity_unit" id="validity_unit" value="days">
                <input type="hidden" name="max_visits" id="max_visits" value="">
                <!-- product_ids[] populated by JS before submit -->

                <div class="asgn-layout">

                    <!-- ══════ LEFT: Search + Results ══════ -->
                    <div class="asgn-col-search">

                        <!-- Search panel -->
                        <div style="margin-bottom:16px;">
                            <h2 class="card-title" style="font-size:1rem;margin-bottom:10px;">
                                <?= $esc(t('asgn_search_title')) ?>
                            </h2>
                            <div class="form-group">
                                <label class="form-label" for="assignment_org_id">
                                    <?= $esc(t('filter_org_label')) ?>
                                </label>
                                <select id="assignment_org_id" name="org_id" class="form-input" onchange="onAssignmentOrgChange()">
                                    <?php foreach ($accessibleOrgs as $accessibleOrg): ?>
                                    <option value="<?= (int) $accessibleOrg['id'] ?>"
                                        <?= (int) $accessibleOrg['id'] === $assignmentOrgId ? 'selected' : '' ?>>
                                        <?= $esc($accessibleOrg['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="search-filter-grid">
                                <div>
                                    <label class="form-label" style="font-size:0.82rem;">
                                        <?= $esc(t('asgn_search_label_keyword')) ?>
                                    </label>
                                    <input type="text" id="filter_keyword"
                                           placeholder="<?= $esc(t('asgn_search_ph_keyword')) ?>"
                                           maxlength="100" autocomplete="off">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:0.82rem;">
                                        <?= $esc(t('asgn_search_label_supplier')) ?>
                                    </label>
                                    <input type="text" id="filter_supplier"
                                           placeholder="<?= $esc(t('asgn_search_ph_supplier')) ?>"
                                           maxlength="100" autocomplete="off">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:0.82rem;">
                                        <?= $esc(t('asgn_search_label_name')) ?>
                                    </label>
                                    <input type="text" id="filter_name"
                                           placeholder="<?= $esc(t('asgn_search_ph_name')) ?>"
                                           maxlength="100" autocomplete="off">
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:0.82rem;">
                                        <?= $esc(t('asgn_search_label_description')) ?>
                                    </label>
                                    <input type="text" id="filter_description"
                                           placeholder="<?= $esc(t('asgn_search_ph_description')) ?>"
                                           maxlength="100" autocomplete="off">
                                </div>
                            </div>
                            <div class="search-actions">
                                <button type="button" class="btn-primary btn-sm" onclick="doSearch(1)">
                                    <?= $esc(t('asgn_btn_search')) ?>
                                </button>
                                <button type="button" class="btn-secondary btn-sm" onclick="clearFilters()">
                                    <?= $esc(t('asgn_btn_clear_filters')) ?>
                                </button>
                            </div>
                        </div>

                        <!-- Results panel -->
                        <div id="resultsPanel" style="display:none;">
                            <div class="result-stats" id="resultStats"></div>
                            <div class="result-grid" id="resultGrid"></div>
                            <div class="result-pagination" id="resultPagination"></div>
                        </div>

                        <!-- Detail panel -->
                        <div id="detailPanel" style="display:none;">
                            <button type="button" class="btn-secondary btn-sm"
                                    onclick="backToResults()" style="margin-bottom:12px;">
                                <?= $esc(t('asgn_btn_back_results')) ?>
                            </button>
                            <div class="detail-panel" id="detailContent">
                                <div class="loading-spinner">...</div>
                            </div>
                        </div>
                    </div>

                    <!-- ══════ RIGHT: Selected products + Form ══════ -->
                    <div class="asgn-col-form">

                        <!-- Selected products -->
                        <div class="selected-panel" id="selectedPanel" style="display:none;">
                            <h3 style="margin:0 0 10px;font-size:0.95rem;color:#0071e3;">
                                <?= $esc(t('asgn_selected_title')) ?>
                            </h3>
                            <div id="selectedList"></div>
                        </div>

                        <!-- ── SECCIÓN: Precio base y ganancia ── -->
                        <div class="asgn-form-section">
                            <div class="asgn-form-section-title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                <?= $esc(t('asgn_price_config_title')) ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= $esc(t('asgn_price_config_title')) ?></label>
                                <div class="base-btn-group">
                                    <button type="button" class="base-btn" id="btn-fob"
                                            onclick="selectBase('fob')">
                                        <?= $esc(t('asgn_btn_fob')) ?>
                                    </button>
                                    <button type="button" class="base-btn" id="btn-cif"
                                            onclick="selectBase('cif')">
                                        <?= $esc(t('asgn_btn_cif')) ?>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= $esc(t('asgn_profit_label')) ?></label>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="profit-btn" id="btn-profit-none"
                                            onclick="selectProfit('none')">
                                        <?= $esc(t('asgn_calc_type_none')) ?>
                                    </button>
                                    <button type="button" class="profit-btn" id="btn-profit-pct"
                                            onclick="selectProfit('percentage')">
                                        <?= $esc(t('asgn_calc_type_percentage')) ?>
                                    </button>
                                    <button type="button" class="profit-btn" id="btn-profit-amt"
                                            onclick="selectProfit('fixed_amount')">
                                        <?= $esc(t('asgn_calc_type_specific')) ?>
                                    </button>
                                </div>
                                <div class="free-profit-row" id="profitPctRow">
                                    <input type="number" class="free-profit-input" id="profitPctInput"
                                           min="0" max="999" step="0.01" placeholder="0"
                                           oninput="onProfitChange('percentage')">
                                    <span class="fee-unit-label">%</span>
                                </div>
                                <div class="free-profit-row" id="profitAmtRow">
                                    <span class="fee-unit-label">$</span>
                                    <input type="number" class="free-profit-input" id="profitAmtInput"
                                           min="0" max="999999" step="0.01" placeholder="0.00"
                                           oninput="onProfitChange('fixed_amount')">
                                </div>
                            </div>
                        </div>

                        <!-- ── SECCIÓN: Cargos adicionales ── -->
                        <div class="asgn-form-section">
                            <div class="asgn-form-section-title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M3 17l4-8 4 4 4-6 4 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= $esc(t('asgn_section_transport')) ?> &amp; <?= $esc(t('asgn_section_tax')) ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= $esc(t('asgn_section_transport')) ?></label>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="profit-btn" id="btn-transport-none"
                                            onclick="selectTransport('none')">
                                        <?= $esc(t('asgn_calc_type_none')) ?>
                                    </button>
                                    <button type="button" class="profit-btn" id="btn-transport-pct"
                                            onclick="selectTransport('percentage')">
                                        <?= $esc(t('asgn_calc_type_percentage')) ?>
                                    </button>
                                    <button type="button" class="profit-btn" id="btn-transport-amt"
                                            onclick="selectTransport('fixed_amount')">
                                        <?= $esc(t('asgn_calc_type_specific')) ?>
                                    </button>
                                </div>
                                <div class="free-profit-row" id="transportPctRow">
                                    <input type="number" class="free-profit-input" id="transportPctInput"
                                           min="0" max="100" step="0.01" placeholder="0"
                                           oninput="onTransportChange('percentage')">
                                    <span class="fee-unit-label">%</span>
                                </div>
                                <div class="free-profit-row" id="transportAmtRow">
                                    <span class="fee-unit-label">$</span>
                                    <input type="number" class="free-profit-input" id="transportAmtInput"
                                           min="0" max="999999" step="0.01" placeholder="0.00"
                                           oninput="onTransportChange('fixed_amount')">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= $esc(t('asgn_section_tax')) ?></label>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="profit-btn" id="btn-tax-none"
                                            onclick="selectTax('none')">
                                        <?= $esc(t('asgn_calc_type_none')) ?>
                                    </button>
                                    <button type="button" class="profit-btn" id="btn-tax-pct"
                                            onclick="selectTax('percentage')">
                                        <?= $esc(t('asgn_calc_type_percentage')) ?>
                                    </button>
                                    <button type="button" class="profit-btn" id="btn-tax-amt"
                                            onclick="selectTax('fixed_amount')">
                                        <?= $esc(t('asgn_calc_type_specific')) ?>
                                    </button>
                                </div>
                                <div class="free-profit-row" id="taxPctRow">
                                    <input type="number" class="free-profit-input" id="taxPctInput"
                                           min="0" max="100" step="0.01" placeholder="0"
                                           oninput="onTaxChange('percentage')">
                                    <span class="fee-unit-label">%</span>
                                </div>
                                <div class="free-profit-row" id="taxAmtRow">
                                    <span class="fee-unit-label">$</span>
                                    <input type="number" class="free-profit-input" id="taxAmtInput"
                                           min="0" max="999999" step="0.01" placeholder="0.00"
                                           oninput="onTaxChange('fixed_amount')">
                                </div>
                            </div>
                        </div>

                        <!-- ── SECCIÓN: Vigencia y límites ── -->
                        <div class="asgn-form-section">
                            <div class="asgn-form-section-title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                <?= $esc(t('asgn_section_validity')) ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <?= $esc(t('asgn_section_validity')) ?>
                                </label>
                                <div style="display:flex;gap:10px;">
                                    <input type="number" id="validityAmount" name="validity_amount"
                                           class="form-input" min="1" max="168" value="7" step="1"
                                           oninput="onValidityChange()" style="width:90px;text-align:center;">
                                    <select id="validityUnit" name="validity_unit"
                                            class="form-input" style="flex:1;" onchange="onValidityChange()">
                                        <option value="hours"><?= $esc(t('asgn_validity_unit_hours')) ?></option>
                                        <option value="days" selected><?= $esc(t('asgn_validity_unit_days')) ?></option>
                                    </select>
                                </div>
                                <span class="form-help"><?= $esc(t('asgn_validity_help')) ?></span>
                                <div id="validityPreview" style="margin-top:5px;font-size:0.82rem;color:#0071e3;font-weight:500;"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="maxVisitsInput">
                                    <?= $esc(t('asgn_max_visits_label')) ?>
                                </label>
                                <input type="number" id="maxVisitsInput" class="form-input"
                                       min="1" max="999999"
                                       placeholder="<?= $esc(t('asgn_max_visits_ph')) ?>"
                                       oninput="onMaxVisitsChange()">
                                <span class="form-help"><?= $esc(t('asgn_max_visits_help')) ?></span>
                            </div>
                        </div>

                        <!-- ── SECCIÓN: Información del cliente ── -->
                        <div class="asgn-form-section">
                            <div class="asgn-form-section-title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                <?= $esc(t('asgn_customer_label')) ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="customer_name">
                                    <?= $esc(t('asgn_customer_label')) ?> <span style="color:#e74c3c;">*</span>
                                </label>
                                <input type="text" id="customer_name" name="customer_name"
                                       class="form-input" maxlength="200" required
                                       placeholder="<?= $esc(t('asgn_customer_ph')) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="company_name">
                                    <?= $esc(t('asgn_field_company')) ?>
                                </label>
                                <input type="text" id="company_name" name="company_name"
                                       class="form-input" maxlength="200"
                                       placeholder="<?= $esc(t('asgn_field_company_ph')) ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="special_conditions">
                                    <?= $esc(t('asgn_field_conditions')) ?>
                                </label>
                                <textarea id="special_conditions" name="special_conditions"
                                          class="form-input" rows="3" maxlength="2000"
                                          placeholder="<?= $esc(t('asgn_field_conditions_ph')) ?>"></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="discount_percentage">
                                    <?= $esc(t('asgn_field_discount')) ?>
                                </label>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <input type="number" id="discount_percentage" name="discount_percentage"
                                           class="form-input" min="0" max="100" step="0.01"
                                           placeholder="<?= $esc(t('asgn_field_discount_ph')) ?>"
                                           oninput="updateSelectedPanel()">
                                    <span class="fee-unit-label">%</span>
                                </div>
                                <span class="form-help"><?= $esc(t('asgn_field_discount_hint')) ?></span>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary" id="submitBtn">
                                <?= $esc(t('btn_generate_link')) ?>
                            </button>
                        </div>

                    </div><!-- /col-form -->
                </div><!-- /layout -->
            </form>
        </div>

        <!-- ════════════════════════════════════════════════
             RECENT ASSIGNMENTS LIST
        ════════════════════════════════════════════════ -->
        <div class="card profile-form-card" style="max-width:100%;">
            <h2 class="card-title" style="font-size:1.1rem;"><?= $esc(t('asgn_list_title')) ?></h2>

            <?php if (empty($recentAssignments)): ?>
            <p class="text-muted"><?= $esc(t('asgn_no_assignments')) ?></p>
            <?php else: ?>
            <div class="asgn-table-wrap">
                <table class="data-table" style="min-width:820px;">
                    <thead>
                        <tr>
                            <th><?= $esc(t('col_org')) ?></th>
                            <th><?= $esc(t('asgn_col_customer')) ?></th>
                            <th><?= $esc(t('asgn_col_items')) ?></th>
                            <th><?= $esc(t('quote_total_label')) ?></th>
                            <th><?= $esc(t('asgn_col_status')) ?></th>
                            <th><?= $esc(t('asgn_col_expires')) ?></th>
                            <th><?= $esc(t('asgn_col_views')) ?></th>
                            <th><?= $esc(t('asgn_col_created_at')) ?></th>
                            <th><?= $esc(t('asgn_col_actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAssignments as $a): ?>
                        <?php
                            $expTs      = strtotime($a['expires_at']);
                            $isExpired  = ($a['status'] === 'active' && $expTs < time());
                            $dispStatus = $isExpired ? 'expired' : $a['status'];
                            $badgeClass = $statusClass[$dispStatus] ?? 'status-badge--inactive';
                            $statusLabel = t('asgn_status_' . $dispStatus);
                            $subtotal    = (float)($a['subtotal'] ?? 0);
                            $discPct     = $a['discount_percentage'] !== null ? (float)$a['discount_percentage'] : 0;
                            $total       = $subtotal * (1 - $discPct / 100);
                        ?>
                        <tr>
                            <td class="text-muted" style="font-size:0.83rem;">
                                <?= $esc($a['org_name']) ?>
                            </td>
                            <td>
                                <div style="font-weight:600;"><?= $esc($a['assigned_customer_name']) ?></div>
                                <?php if ($a['company_name']): ?>
                                <div style="font-size:0.8rem;color:#666;"><?= $esc($a['company_name']) ?></div>
                                <?php endif; ?>
                                <div style="font-size:0.78rem;color:#999;"><?= $esc($a['creator_username']) ?></div>
                            </td>
                            <td style="text-align:center;"><?= (int)$a['item_count'] ?></td>
                            <td style="font-weight:600;">
                                <?= $esc($fmtPrice($total)) ?>
                                <?php if ($discPct > 0): ?>
                                <div style="font-size:0.77rem;color:#888;">(-<?= number_format($discPct,1) ?>%)</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $badgeClass ?>">
                                    <?= $esc($statusLabel) ?>
                                </span>
                            </td>
                            <td class="text-muted" style="font-size:0.85rem;">
                                <?= $esc(date('d/m/Y H:i', $expTs)) ?>
                            </td>
                            <td style="text-align:center;"><?= (int)$a['view_count'] ?></td>
                            <td class="text-muted" style="font-size:0.83rem;">
                                <?= $esc(date('d/m/Y', strtotime($a['created_at']))) ?>
                            </td>
                            <td>
                                <div class="asgn-actions">
                                    <?php if ($dispStatus === 'active'): ?>
                                    <form method="POST" action="/login/admin/assignments.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="revoke_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                        <button type="button" class="btn-asgn-action btn-asgn-action--danger"
                                            onclick="confirmAction(this.form,'<?= $esc(t('asgn_confirm_revoke')) ?>')">
                                            <?= $esc(t('asgn_btn_revoke')) ?>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" action="/login/admin/assignments.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                        <button type="button" class="btn-asgn-action btn-asgn-action--danger"
                                            onclick="confirmAction(this.form,'<?= $esc(t('asgn_confirm_delete')) ?>')">
                                            <?= $esc(t('asgn_btn_delete')) ?>
                                        </button>
                                    </form>
                                    <button type="button" class="btn-asgn-action btn-asgn-action--primary"
                                        onclick="openRegenModal(<?= (int)$a['id'] ?>,'<?= $esc($a['assigned_customer_name']) ?>')">
                                        <?= $esc(t('asgn_btn_regen')) ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /page-content -->

    <!-- ════════ REGEN MODAL ════════ -->
    <div class="asgn-modal-overlay" id="regenModalOverlay" role="dialog" aria-modal="true">
        <div class="asgn-modal">
            <h3><?= $esc(t('asgn_regen_modal_title')) ?></h3>
            <form method="POST" action="/login/admin/assignments.php" id="regenForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="regen_link">
                <input type="hidden" name="assignment_id" id="regenAssignmentId" value="">
                <div class="form-group">
                    <label class="form-label" for="regenCustomerName">
                        <?= $esc(t('asgn_regen_customer_label')) ?>
                    </label>
                    <input type="text" id="regenCustomerName" name="customer_name"
                           class="form-input" maxlength="200"
                           placeholder="<?= $esc(t('asgn_regen_customer_ph')) ?>">
                </div>
                <div class="asgn-modal-actions">
                    <button type="button" class="btn-secondary btn-sm" onclick="closeRegenModal()">
                        <?= $esc(t('asgn_regen_cancel')) ?>
                    </button>
                    <button type="submit" class="btn-primary btn-sm">
                        <?= $esc(t('asgn_regen_submit')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    // ═══════════════════════════════════════════════════════════
    //  STATE
    // ═══════════════════════════════════════════════════════════
    var selectedBase   = '';
    var selectedProfit = null;
    // selectedProducts: { id: {id,name,price_fob,price_cif,internal_code} }
    var selectedProducts = {};
    // productRegistry: all products seen in search/detail, keyed by id
    // Avoids embedding JSON.stringify() inside onclick attributes (breaks HTML parsing)
    var productRegistry = {};
    // Last search results (preserved when viewing detail)
    var lastSearchData  = null;
    var lastSearchPage  = 1;

    // i18n strings passed from PHP
    var i18n = {
        btn_select:    <?= json_encode(t('asgn_btn_select')) ?>,
        btn_selected:  <?= json_encode(t('asgn_btn_selected')) ?>,
        btn_details:   <?= json_encode(t('asgn_btn_details')) ?>,
        btn_remove:    <?= json_encode(t('asgn_btn_remove')) ?>,
        results_empty: <?= json_encode(t('asgn_results_empty')) ?>,
        results_count: <?= json_encode(t('asgn_results_count')) ?>,
        selected_empty:<?= json_encode(t('asgn_selected_empty')) ?>,
        fob_label:     <?= json_encode(t('asgn_detail_fob_label')) ?>,
        cif_label:     <?= json_encode(t('asgn_detail_cif_label')) ?>,
        supplier_label:<?= json_encode(t('asgn_detail_supplier_label')) ?>,
        org_label:     <?= json_encode(t('asgn_detail_org_label')) ?>,
        desc_label:    <?= json_encode(t('quote_description_label')) ?>,
        kw_label:      <?= json_encode(t('quote_keywords_label')) ?>,
        subtotal_label:<?= json_encode(t('quote_subtotal_label')) ?>,
        discount_label:<?= json_encode(t('asgn_field_discount')) ?>,
        total_label:   <?= json_encode(t('quote_total_label')) ?>,
        items_label:   <?= json_encode(t('asgn_col_items')) ?>,
    };

    // ═══════════════════════════════════════════════════════════
    //  SEARCH
    // ═══════════════════════════════════════════════════════════
    function doSearch(page) {
        page = page || 1;
        lastSearchPage = page;
        var selectedOrg = document.getElementById('assignment_org_id');
        var params = new URLSearchParams({
            q:           document.getElementById('filter_keyword').value.trim(),
            supplier:    document.getElementById('filter_supplier').value.trim(),
            name:        document.getElementById('filter_name').value.trim(),
            description: document.getElementById('filter_description').value.trim(),
            org:         selectedOrg ? selectedOrg.value : '',
            page:        page,
        });

        document.getElementById('detailPanel').style.display = 'none';
        document.getElementById('resultsPanel').style.display = 'block';
        document.getElementById('resultGrid').innerHTML =
            '<div class="loading-spinner">&#8230;</div>';
        document.getElementById('resultStats').textContent = '';
        document.getElementById('resultPagination').innerHTML = '';

        fetch('/login/admin/api/product_search.php?' + params.toString(), {
            credentials: 'same-origin',
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            lastSearchData = data;
            renderResults(data);
        })
        .catch(function(err) {
            document.getElementById('resultGrid').innerHTML =
                '<p style="color:#c0392b;font-size:0.9rem;">Error: ' + err.message + '</p>';
        });
    }

    function clearFilters() {
        ['filter_keyword','filter_supplier','filter_name','filter_description']
            .forEach(function(id) { document.getElementById(id).value = ''; });
        document.getElementById('resultsPanel').style.display = 'none';
        document.getElementById('detailPanel').style.display  = 'none';
        lastSearchData = null;
    }

    function onAssignmentOrgChange() {
        selectedProducts = {};
        updateSelectedPanel();
        if (document.getElementById('resultsPanel').style.display === 'block') {
            doSearch(1);
        }
    }

    // ─── Enter key on filter fields triggers search ──────────
    ['filter_keyword','filter_supplier','filter_name','filter_description'].forEach(function(id) {
        document.getElementById(id).addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(1); }
        });
    });

    // ═══════════════════════════════════════════════════════════
    //  RENDER RESULTS
    // ═══════════════════════════════════════════════════════════
    function renderResults(data) {
        var grid = document.getElementById('resultGrid');
        var stats = document.getElementById('resultStats');
        var pager = document.getElementById('resultPagination');

        if (!data.success || data.items.length === 0) {
            grid.innerHTML = '<p style="color:#666;font-size:0.9rem;">' + esc(i18n.results_empty) + '</p>';
            stats.textContent = '';
            pager.innerHTML   = '';
            return;
        }

        stats.textContent = sprintf(i18n.results_count, data.total)
            + (data.pages > 1 ? '  (p. ' + data.page + '/' + data.pages + ')' : '');

        var html = '';
        data.items.forEach(function(item) {
            productRegistry[item.id] = item;  // store for onclick lookup
            var isSel = selectedProducts.hasOwnProperty(item.id);
            html += '<div class="result-card' + (isSel ? ' is-selected' : '') + '" id="rc-' + item.id + '">';

            // Thumbnail
            if (item.front_img_path) {
                html += '<img class="result-thumb" src="/login/' + esc(item.front_img_path) + '" alt="" loading="lazy">';
            } else {
                html += '<div class="result-thumb-placeholder">'
                    + '<svg width="24" height="24" viewBox="0 0 24 24" fill="none">'
                    + '<rect x="3" y="3" width="18" height="18" rx="3" stroke="#ccc" stroke-width="1.5"/>'
                    + '<path d="M3 15l5-5 4 4 3-3 6 6" stroke="#ccc" stroke-width="1.5" stroke-linecap="round"/>'
                    + '</svg></div>';
            }

            html += '<div class="result-body">';
            html += '<div class="result-name">' + esc(item.product_name) + '</div>';
            if (item.internal_product_code) {
                html += '<span class="result-code">' + esc(item.internal_product_code) + '</span>';
            }
            html += '<div class="result-meta">'
                + esc(item.supplier_company || item.supplier_username);
            if (item.org_name) html += ' &bull; ' + esc(item.org_name);
            html += '</div>';
            html += '<div class="result-prices">';
            if (item.price_fob !== null) {
                html += '<span class="result-price-badge">FOB: $' + item.price_fob.toFixed(2) + '</span>';
            }
            if (item.price_cif !== null) {
                html += '<span class="result-price-badge">CIF: $' + item.price_cif.toFixed(2) + '</span>';
            }
            html += '</div>';
            if (item.keywords_csv) {
                html += '<div class="result-keywords">' + esc(item.keywords_csv) + '</div>';
            }
            html += '</div>'; // /result-body

            html += '<div class="result-actions">';
            html += '<button type="button" class="btn-sm ' + (isSel ? 'btn-primary' : 'btn-secondary')
                + '" id="selbtn-' + item.id + '" onclick="toggleProduct(' + item.id + ')">'  
                + (isSel ? esc(i18n.btn_selected) : esc(i18n.btn_select)) + '</button>';
            html += '<button type="button" class="btn-secondary btn-sm" onclick="viewDetail('
                + item.id + ')">' + esc(i18n.btn_details) + '</button>';
            html += '</div></div>'; // /actions /card
        });
        grid.innerHTML = html;

        // Pagination
        if (data.pages > 1) {
            var ph = '';
            for (var p = 1; p <= data.pages; p++) {
                ph += '<button type="button" class="pg-btn' + (p === data.page ? ' active' : '')
                    + '" onclick="doSearch(' + p + ')">' + p + '</button>';
            }
            pager.innerHTML = ph;
        } else {
            pager.innerHTML = '';
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  PRODUCT DETAIL
    // ═══════════════════════════════════════════════════════════
    function viewDetail(productId) {
        document.getElementById('resultsPanel').style.display = 'none';
        document.getElementById('detailPanel').style.display  = 'block';
        document.getElementById('detailContent').innerHTML =
            '<div class="loading-spinner">&#8230;</div>';

        fetch('/login/admin/api/product_detail.php?id=' + encodeURIComponent(productId), {
            credentials: 'same-origin',
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('detailContent').innerHTML =
                    '<p style="color:#c0392b;">Error loading product.</p>';
                return;
            }
            renderDetail(data.product);
        })
        .catch(function(err) {
            document.getElementById('detailContent').innerHTML =
                '<p style="color:#c0392b;">Error: ' + err.message + '</p>';
        });
    }

    function renderDetail(p) {
        var isSel = selectedProducts.hasOwnProperty(p.id);
        var html  = '<h3 style="margin:0 0 6px;font-size:1.1rem;">' + esc(p.product_name) + '</h3>';
        if (p.internal_product_code) {
            html += '<span class="result-code" style="font-size:0.8rem;">' + esc(p.internal_product_code) + '</span>';
        }
        html += '<div style="font-size:0.85rem;color:#555;margin:6px 0 10px;">';
        html += esc(i18n.supplier_label) + ': <strong>' + esc(p.supplier_company || p.supplier_username) + '</strong>';
        if (p.org_name) html += ' &bull; ' + esc(i18n.org_label) + ': <strong>' + esc(p.org_name) + '</strong>';
        html += '</div>';

        // Images
        if (p.images && Object.keys(p.images).length > 0) {
            html += '<div class="detail-images">';
            ['front','back','left','right','aerial','bottom'].forEach(function(slot) {
                if (p.images[slot]) {
                    html += '<img class="detail-img" src="/login/' + esc(p.images[slot]) + '" alt="' + esc(slot) + '"'
                        + ' onclick="openDetailLightbox(this.src)">';
                }
            });
            html += '</div>';
        }

        // Prices
        html += '<div class="detail-prices-row">';
        if (p.price_fob !== null) html += '<span class="detail-price-chip">FOB: $' + p.price_fob.toFixed(2) + '</span>';
        if (p.price_cif !== null) html += '<span class="detail-price-chip">CIF: $' + p.price_cif.toFixed(2) + '</span>';
        html += '</div>';

        // Description
        if (p.technical_description) {
            html += '<div style="margin-top:10px;"><div style="font-size:0.78rem;font-weight:600;color:#888;text-transform:uppercase;margin-bottom:4px;">'
                + esc(i18n.desc_label) + '</div>';
            html += '<div class="detail-desc">' + escNl(p.technical_description) + '</div></div>';
        }

        // Keywords
        if (p.keywords && p.keywords.length > 0) {
            html += '<div class="detail-keywords">';
            p.keywords.forEach(function(k) {
                html += '<span class="detail-kw-chip">' + esc(k) + '</span>';
            });
            html += '</div>';
        }

        // Select button — store minimal record in registry so onclick only needs the ID
        productRegistry[p.id] = {
            id:                    p.id,
            product_name:          p.product_name,
            internal_product_code: p.internal_product_code,
            price_fob:             p.price_fob,
            price_cif:             p.price_cif,
        };
        html += '<div style="margin-top:14px;">';
        html += '<button type="button" class="' + (isSel ? 'btn-primary' : 'btn-secondary') + '" '
            + 'id="detail-selbtn" onclick="toggleProduct(' + p.id + ')">' 
            + (isSel ? esc(i18n.btn_selected) : esc(i18n.btn_select))
            + '</button>';
        html += '</div>';

        // Lightbox anchor (hidden)
        html += '<div id="detailLightboxOverlay" onclick="this.style.display=\'none\'" '
            + 'style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.88);'
            + 'z-index:9999;align-items:center;justify-content:center;cursor:zoom-out;">'
            + '<img id="detailLightboxImg" src="" style="max-width:90vw;max-height:88vh;border-radius:6px;"></div>';

        document.getElementById('detailContent').innerHTML = html;
    }

    function backToResults() {
        document.getElementById('detailPanel').style.display  = 'none';
        document.getElementById('resultsPanel').style.display = 'block';
        // Re-render results to reflect any selection changes
        if (lastSearchData) { renderResults(lastSearchData); }
    }

    function openDetailLightbox(src) {
        var o = document.getElementById('detailLightboxOverlay');
        if (!o) return;
        document.getElementById('detailLightboxImg').src = src;
        o.style.display = 'flex';
    }

    // ═══════════════════════════════════════════════════════════
    //  PRODUCT SELECTION
    // ═══════════════════════════════════════════════════════════
    function toggleProduct(productId) {
        // productId is always a numeric ID; data comes from productRegistry
        var item = productRegistry[productId];
        if (!item) { return; }
        if (selectedProducts.hasOwnProperty(productId)) {
            delete selectedProducts[productId];
        } else {
            selectedProducts[productId] = item;
        }
        updateSelectedPanel();
        // Update select button in result card if visible
        var rb = document.getElementById('selbtn-' + productId);
        if (rb) {
            var sel = selectedProducts.hasOwnProperty(productId);
            rb.textContent = sel ? i18n.btn_selected : i18n.btn_select;
            rb.className   = 'btn-sm ' + (sel ? 'btn-primary' : 'btn-secondary');
            var card = document.getElementById('rc-' + productId);
            if (card) card.classList.toggle('is-selected', sel);
        }
        // Update detail select button if visible
        var db = document.getElementById('detail-selbtn');
        if (db) {
            var selD = selectedProducts.hasOwnProperty(productId);
            db.textContent = selD ? i18n.btn_selected : i18n.btn_select;
            db.className   = selD ? 'btn-primary' : 'btn-secondary';
        }
    }

    function removeProduct(id) {
        delete selectedProducts[id];
        updateSelectedPanel();
        // Refresh result card button if visible
        var rb = document.getElementById('selbtn-' + id);
        if (rb) {
            rb.textContent = i18n.btn_select;
            rb.className   = 'btn-sm btn-secondary';
            var card = document.getElementById('rc-' + id);
            if (card) card.classList.remove('is-selected');
        }
    }

    function updateSelectedPanel() {
        var panel    = document.getElementById('selectedPanel');
        var list     = document.getElementById('selectedList');
        var ids      = Object.keys(selectedProducts);
        var discount = parseFloat(document.getElementById('discount_percentage').value) || 0;
        if (discount < 0 || discount > 100) discount = 0;

        if (ids.length === 0) {
            panel.style.display = 'none';
            list.innerHTML = '';
            return;
        }
        panel.style.display = 'block';

        // Read profit from DOM
        var profitType = document.getElementById('profit_calculation_type').value || '';
        var profitPct = parseFloat(document.getElementById('profit_percentage').value) || 0;
        var profitAmt = parseFloat(document.getElementById('profit_fixed_amount').value) || 0;

        var subtotal = 0;
        var html     = '';
        ids.forEach(function(id) {
            var p     = selectedProducts[id];
            var base  = selectedBase === 'fob' ? p.price_fob : (selectedBase === 'cif' ? p.price_cif : null);
            var price = null;
            
            if (base !== null && profitType) {
                if (profitType === 'percentage') {
                    price = Math.round(base * (1 + profitPct / 100) * 100) / 100;
                } else if (profitType === 'fixed_amount') {
                    price = Math.round((base + profitAmt) * 100) / 100;
                }
            }
            subtotal += price !== null ? price : 0;

            html += '<div class="selected-item">';
            html += '<div class="selected-item-name">' + esc(p.product_name);
            if (p.internal_product_code) {
                html += ' <code style="font-size:0.75rem;color:#0071e3;">' + esc(p.internal_product_code) + '</code>';
            }
            html += '</div>';
            html += '<div class="selected-item-price">'
                + (price !== null ? '$' + price.toFixed(2) : '—') + '</div>';
            html += '<button type="button" class="btn-sm btn-secondary"'
                + ' onclick="removeProduct(' + parseInt(id) + ')">'
                + esc(i18n.btn_remove) + '</button>';
            html += '</div>';
        });

        // Totals
        var discountAmt = subtotal * (discount / 100);
        var total       = subtotal - discountAmt;
        html += '<div class="selected-total-row">';
        if (discount > 0) {
            html += '<span style="font-weight:400;font-size:0.85rem;color:#888;">'
                + esc(i18n.subtotal_label) + ': $' + subtotal.toFixed(2) + '</span>';
            html += '<span style="font-weight:400;font-size:0.85rem;color:#e74c3c;">'
                + '-' + discount.toFixed(1) + '% = -$' + discountAmt.toFixed(2) + '</span>';
        }
        html += '<span>' + esc(i18n.total_label) + ': $' + total.toFixed(2) + '</span>';
        html += '</div>';

        list.innerHTML = html;
    }

    // ═══════════════════════════════════════════════════════════
    //  PRICE CONFIG
    // ═══════════════════════════════════════════════════════════
    function selectBase(type) {
        selectedBase = type;
        document.getElementById('price_base_type').value = type;
        document.getElementById('btn-fob').classList.toggle('selected', type === 'fob');
        document.getElementById('btn-cif').classList.toggle('selected', type === 'cif');
        updateSelectedPanel();
    }

    function selectProfit(type) {
        document.getElementById('profit_calculation_type').value = type !== 'none' ? type : '';
        document.getElementById('profit_percentage').value = '';
        document.getElementById('profit_fixed_amount').value = '';
        
        // Hide both rows
        document.getElementById('profitPctRow').style.display = 'none';
        document.getElementById('profitAmtRow').style.display = 'none';
        
        // Update button states
        document.getElementById('btn-profit-none').classList.toggle('selected', type === 'none');
        document.getElementById('btn-profit-pct').classList.toggle('selected', type === 'percentage');
        document.getElementById('btn-profit-amt').classList.toggle('selected', type === 'fixed_amount');
        
        // Show appropriate input
        if (type === 'percentage') {
            document.getElementById('profitPctRow').style.display = 'flex';
            document.getElementById('profitPctInput').focus();
        } else if (type === 'fixed_amount') {
            document.getElementById('profitAmtRow').style.display = 'flex';
            document.getElementById('profitAmtInput').focus();
        }
        updateSelectedPanel();
    }

    function onProfitChange(type) {
        if (type === 'percentage') {
            var val = parseFloat(document.getElementById('profitPctInput').value) || 0;
            if (val >= 0 && val <= 999) {
                document.getElementById('profit_percentage').value = val;
                document.getElementById('profit_fixed_amount').value = '';
            }
        } else if (type === 'fixed_amount') {
            var val = parseFloat(document.getElementById('profitAmtInput').value) || 0;
            if (val >= 0) {
                document.getElementById('profit_fixed_amount').value = val.toFixed(2);
                document.getElementById('profit_percentage').value = '';
            }
        }
        updateSelectedPanel();
    }

    // ═══════════════════════════════════════════════════════════
    //  TRANSPORT CONFIG
    // ═══════════════════════════════════════════════════════════
    function selectTransport(type) {
        document.getElementById('transport_calculation_type').value = type !== 'none' ? type : '';
        document.getElementById('transport_percentage').value = '';
        document.getElementById('transport_fixed_amount').value = '';
        
        // Hide both rows
        document.getElementById('transportPctRow').style.display = 'none';
        document.getElementById('transportAmtRow').style.display = 'none';
        
        // Update button states
        document.getElementById('btn-transport-none').classList.toggle('selected', type === 'none');
        document.getElementById('btn-transport-pct').classList.toggle('selected', type === 'percentage');
        document.getElementById('btn-transport-amt').classList.toggle('selected', type === 'fixed_amount');
        
        // Show appropriate input
        if (type === 'percentage') {
            document.getElementById('transportPctRow').style.display = 'flex';
            document.getElementById('transportPctInput').focus();
        } else if (type === 'fixed_amount') {
            document.getElementById('transportAmtRow').style.display = 'flex';
            document.getElementById('transportAmtInput').focus();
        }
    }

    function onTransportChange(type) {
        if (type === 'percentage') {
            var val = parseFloat(document.getElementById('transportPctInput').value) || 0;
            if (val >= 0 && val <= 100) {
                document.getElementById('transport_percentage').value = val;
                document.getElementById('transport_fixed_amount').value = '';
            }
        } else if (type === 'fixed_amount') {
            var val = parseFloat(document.getElementById('transportAmtInput').value) || 0;
            if (val >= 0) {
                document.getElementById('transport_fixed_amount').value = val.toFixed(2);
                document.getElementById('transport_percentage').value = '';
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  TAX CONFIG
    // ═══════════════════════════════════════════════════════════
    function selectTax(type) {
        document.getElementById('tax_calculation_type').value = type !== 'none' ? type : '';
        document.getElementById('tax_percentage').value = '';
        document.getElementById('tax_fixed_amount').value = '';
        
        // Hide both rows
        document.getElementById('taxPctRow').style.display = 'none';
        document.getElementById('taxAmtRow').style.display = 'none';
        
        // Update button states
        document.getElementById('btn-tax-none').classList.toggle('selected', type === 'none');
        document.getElementById('btn-tax-pct').classList.toggle('selected', type === 'percentage');
        document.getElementById('btn-tax-amt').classList.toggle('selected', type === 'fixed_amount');
        
        // Show appropriate input
        if (type === 'percentage') {
            document.getElementById('taxPctRow').style.display = 'flex';
            document.getElementById('taxPctInput').focus();
        } else if (type === 'fixed_amount') {
            document.getElementById('taxAmtRow').style.display = 'flex';
            document.getElementById('taxAmtInput').focus();
        }
    }

    function onTaxChange(type) {
        if (type === 'percentage') {
            var val = parseFloat(document.getElementById('taxPctInput').value) || 0;
            if (val >= 0 && val <= 100) {
                document.getElementById('tax_percentage').value = val;
                document.getElementById('tax_fixed_amount').value = '';
            }
        } else if (type === 'fixed_amount') {
            var val = parseFloat(document.getElementById('taxAmtInput').value) || 0;
            if (val >= 0) {
                document.getElementById('tax_fixed_amount').value = val.toFixed(2);
                document.getElementById('tax_percentage').value = '';
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  VALIDITY CONFIG
    // ═══════════════════════════════════════════════════════════
    function onValidityChange() {
        var amount = parseInt(document.getElementById('validityAmount').value) || 7;
        var unit = document.getElementById('validityUnit').value;
        var maxAmount = unit === 'hours' ? 168 : 7;
        
        // Constrain value
        if (amount < 1) amount = 1;
        if (amount > maxAmount) amount = maxAmount;
        
        document.getElementById('validityAmount').value = amount;
        document.getElementById('validity_amount').value = amount;
        document.getElementById('validity_unit').value = unit;
        
        // Update preview
        var now = new Date();
        var expiry;
        if (unit === 'hours') {
            expiry = new Date(now.getTime() + amount * 3600000);
        } else {
            expiry = new Date(now.getTime() + amount * 86400000);
        }
        var preview = <?= json_encode(t('asgn_validity_expires_at')) ?> + ' ' 
                    + expiry.toLocaleString('<?= $lang ?>');
        var prevEl = document.getElementById('validityPreview');
        if (prevEl) prevEl.textContent = preview;
    }

    // ═══════════════════════════════════════════════════════════
    //  MAX VISITS CONFIG
    // ═══════════════════════════════════════════════════════════
    function onMaxVisitsChange() {
        var val = document.getElementById('maxVisitsInput').value.trim();
        if (val === '') {
            document.getElementById('max_visits').value = '';
        } else if (isNaN(val) || parseInt(val) <= 0) {
            document.getElementById('maxVisitsInput').value = '';
            document.getElementById('max_visits').value = '';
        } else {
            document.getElementById('max_visits').value = parseInt(val);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  FORM SUBMIT
    // ═══════════════════════════════════════════════════════════
    function prepareFormSubmit() {
        // Remove any existing product_ids inputs
        document.querySelectorAll('input[name="product_ids[]"]')
            .forEach(function(el) { el.parentNode.removeChild(el); });

        var form = document.getElementById('createForm');
        var ids  = Object.keys(selectedProducts);

        if (ids.length === 0) {
            alert(<?= json_encode(t('asgn_err_no_products')) ?>);
            return false;
        }
        if (!selectedBase) {
            alert(<?= json_encode(t('asgn_err_base_invalid')) ?>);
            return false;
        }
        var profitType = document.getElementById('profit_calculation_type').value;
        if (!profitType) {
            alert(<?= json_encode(t('asgn_err_profit_required')) ?>);
            return false;
        }
        if (profitType === 'percentage' && !document.getElementById('profit_percentage').value) {
            alert(<?= json_encode(t('asgn_err_profit_invalid')) ?>);
            return false;
        }
        if (profitType === 'fixed_amount' && !document.getElementById('profit_fixed_amount').value) {
            alert(<?= json_encode(t('asgn_err_profit_invalid')) ?>);
            return false;
        }
        var custName = document.getElementById('customer_name').value.trim();
        if (!custName) {
            alert(<?= json_encode(t('asgn_err_customer_required')) ?>);
            document.getElementById('customer_name').focus();
            return false;
        }

        ids.forEach(function(id) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'product_ids[]';
            inp.value = id;
            form.appendChild(inp);
        });
        return true;
    }

    // ═══════════════════════════════════════════════════════════
    //  REGEN MODAL
    // ═══════════════════════════════════════════════════════════
    function openRegenModal(id, currentCustomer) {
        document.getElementById('regenAssignmentId').value = id;
        document.getElementById('regenCustomerName').value = currentCustomer;
        document.getElementById('regenModalOverlay').classList.add('open');
        document.getElementById('regenCustomerName').focus();
        document.getElementById('regenCustomerName').select();
    }
    function closeRegenModal() {
        document.getElementById('regenModalOverlay').classList.remove('open');
    }
    document.getElementById('regenModalOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeRegenModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeRegenModal();
    });

    // ═══════════════════════════════════════════════════════════
    //  LIST ACTION HELPERS
    // ═══════════════════════════════════════════════════════════
    function confirmAction(form, message) {
        if (window.confirm(message)) form.submit();
    }

    // ═══════════════════════════════════════════════════════════
    //  COPY LINK
    // ═══════════════════════════════════════════════════════════
    function copyAssignmentLink() {
        var input = document.getElementById('generatedLink');
        if (!input) return;
        input.select(); input.setSelectionRange(0, 99999);
        try {
            navigator.clipboard.writeText(input.value).then(showCopied).catch(function() {
                document.execCommand('copy'); showCopied();
            });
        } catch(e) { document.execCommand('copy'); showCopied(); }
    }
    function showCopied() {
        var btn = document.querySelector('.link-copy-row .btn-secondary');
        if (!btn) return;
        var orig = btn.textContent;
        btn.textContent = <?= json_encode(t('asgn_link_copied')) ?>;
        btn.disabled = true;
        setTimeout(function() { btn.textContent = orig; btn.disabled = false; }, 2000);
    }

    // ═══════════════════════════════════════════════════════════
    //  UTILS
    // ═══════════════════════════════════════════════════════════
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
                        .replace(/'/g,'&#039;');
    }
    function escNl(s) {
        return esc(s).replace(/\n/g, '<br>');
    }
    function sprintf(fmt, val) {
        return fmt.replace('%d', val).replace('%s', val);
    }

    // ═══════════════════════════════════════════════════════════
    //  IDLE TIMEOUT
    // ═══════════════════════════════════════════════════════════
    (function() {
        var TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        var last = Date.now();
        ['mousemove','keydown','click','scroll'].forEach(function(ev) {
            document.addEventListener(ev, function() { last = Date.now(); }, { passive: true });
        });
        setInterval(function() {
            if (Date.now() - last >= TIMEOUT_MS) {
                window.location.href = '/login/index.php?reason=timeout';
            }
        }, 10000);
    }());
    </script>
</body>
</html>

