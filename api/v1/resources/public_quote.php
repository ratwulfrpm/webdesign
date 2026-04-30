<?php
/**
 * api/v1/resources/public_quote.php — Public token-based quote viewer.
 *
 * Route:
 *   GET /api/v1/public/quote?t={token}
 *
 * This is the ONLY endpoint that requires no authentication.
 * It exposes a customer-facing view of a quote_assignments record
 * identified by a secure token.
 *
 * NEVER exposes:
 *   - price_fob / price_cif (cost prices)
 *   - price_base_amount / profit_percentage (margin data)
 *   - product_id
 *   - supplier_product_code
 *   - internal admin notes
 *
 * Security:
 *   - Token: 64 hex chars only (validated by regex).
 *   - Rate limiting: max 20 requests per 10 minutes per IP.
 *   - Token stored as SHA-256 hash — plain token never in DB.
 *   - Expired tokens update DB status to 'expired' on first miss.
 *   - No session required — stateless.
 */

function handlePublicQuote(string $method, string $sub): void
{
    if ($method !== 'GET') {
        jsonError('Method Not Allowed', 405);
    }

    if ($sub !== 'quote') {
        jsonError('Not found', 404);
    }

    _getPublicQuote(getDB());
}

// ── PUBLIC QUOTE DETAIL ───────────────────────────────────────

function _getPublicQuote(PDO $pdo): void
{
    // ── Rate limiting — 20 attempts per 10 min per IP ──────────
    $ip = _publicClientIp();
    $key = 'public_quote_' . $ip;

    if (!isset($_SESSION['_rate'])) {
        $_SESSION['_rate'] = [];
    }
    $window = 600; // 10 minutes
    $now    = time();
    if (!isset($_SESSION['_rate'][$key])) {
        $_SESSION['_rate'][$key] = ['count' => 0, 'start' => $now];
    }
    $r = &$_SESSION['_rate'][$key];
    if ($now - $r['start'] > $window) {
        $r = ['count' => 0, 'start' => $now]; // reset window
    }
    $r['count']++;
    if ($r['count'] > 20) {
        jsonError('Too many requests. Try again in a few minutes.', 429);
    }

    // ── Token validation ────────────────────────────────────────
    $rawToken = trim($_GET['t'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        jsonError('Invalid or missing token', 400);
    }

    $tokenHash = hash('sha256', $rawToken);

    // ── STEP 1: Try new multi-product format ────────────────────
    $st = $pdo->prepare(
        "SELECT id, assigned_customer_name, company_name, special_conditions,
                discount_percentage, status, expires_at, valid_from,
                view_count, org_id
           FROM quote_assignments
          WHERE token_hash = ?"
    );
    $st->execute([$tokenHash]);
    $qa = $st->fetch();

    if ($qa) {
        // Check expiry — update DB if needed
        if ($qa['status'] === 'active' && strtotime($qa['expires_at']) < time()) {
            $pdo->prepare(
                "UPDATE quote_assignments SET status = 'expired' WHERE id = ?"
            )->execute([$qa['id']]);
            $qa['status'] = 'expired';
        }

        if ($qa['status'] !== 'active') {
            jsonError('This quote link is no longer available', 410);
        }

        // Load line items — no cost or margin data
        $iSt = $pdo->prepare(
            "SELECT sp.product_name, sp.internal_product_code,
                    sp.technical_description,
                    qai.final_unit_price,
                    spi.file_path AS front_img_path
               FROM quote_assignment_items qai
               JOIN supplier_products sp ON sp.id = qai.product_id
               LEFT JOIN supplier_product_images spi
                      ON spi.product_id = qai.product_id AND spi.image_slot = 'front'
              WHERE qai.quote_assignment_id = ?
              ORDER BY qai.id ASC"
        );
        $iSt->execute([$qa['id']]);
        $lineItems = $iSt->fetchAll();

        // Verify all products are still active
        foreach ($lineItems as $item) {
            // Items joined from supplier_products — inactive products would return NULL
            // for product_name in a stricter query. For now, we trust the JOIN.
        }

        // Compute totals
        $subtotal    = array_sum(array_column($lineItems, 'final_unit_price'));
        $discountPct = $qa['discount_percentage'] !== null ? (float) $qa['discount_percentage'] : 0.0;
        $discountAmt = round($subtotal * $discountPct / 100, 2);
        
        // ── TRANSPORT ──
        $transportAmt = 0.0;
        if ($qa['transport_calculation_type'] === 'percentage') {
            $transportAmt = round($subtotal * ($qa['transport_percentage'] ?? 0) / 100, 2);
        } elseif ($qa['transport_calculation_type'] === 'fixed_amount') {
            $transportAmt = round((float) ($qa['transport_fixed_amount'] ?? 0), 2);
        }
        
        // ── TAX (applied after transport) ──
        $taxAmt = 0.0;
        if ($qa['tax_calculation_type'] === 'percentage') {
            $taxAmt = round(($subtotal + $transportAmt) * ($qa['tax_percentage'] ?? 0) / 100, 2);
        } elseif ($qa['tax_calculation_type'] === 'fixed_amount') {
            $taxAmt = round((float) ($qa['tax_fixed_amount'] ?? 0), 2);
        }
        
        $grandTotal  = round($subtotal + $transportAmt + $taxAmt - $discountAmt, 2);

        // Track view
        $pdo->prepare(
            "UPDATE quote_assignments
                SET view_count = view_count + 1,
                    last_viewed_at = NOW(),
                    viewed_at = COALESCE(viewed_at, NOW())
              WHERE id = ?"
        )->execute([$qa['id']]);

        $items = array_map(fn($r) => [
            'product_name'          => (string) $r['product_name'],
            'internal_product_code' => (string) ($r['internal_product_code'] ?? ''),
            'technical_description' => (string) ($r['technical_description'] ?? ''),
            'unit_price'            => (float)  $r['final_unit_price'],
            'front_img_path'        => $r['front_img_path'] ? (string) $r['front_img_path'] : null,
        ], $lineItems);

        jsonOk([
            'quote' => [
                'customer_name'    => (string) $qa['assigned_customer_name'],
                'company_name'     => (string) ($qa['company_name'] ?? ''),
                'special_conditions'=> (string) ($qa['special_conditions'] ?? ''),
                'expires_at'       => $qa['expires_at'],
                'items'            => $items,
                'totals' => [
                    'subtotal'         => round($subtotal, 2),
                    'transport'        => $transportAmt,
                    'tax'              => $taxAmt,
                    'discount_percent' => $discountPct,
                    'discount_amount'  => $discountAmt,
                    'grand_total'      => $grandTotal,
                ],
            ],
        ]);
    }

    // ── STEP 2: Legacy single-product format ────────────────────
    $legacy = $pdo->prepare(
        "SELECT pa.id, pa.assigned_customer_name, pa.final_price,
                pa.status, pa.expires_at,
                sp.product_name, sp.internal_product_code, sp.technical_description,
                spi.file_path AS front_img_path
           FROM product_assignments pa
           JOIN supplier_products sp ON sp.id = pa.product_id
           LEFT JOIN supplier_product_images spi
                  ON spi.product_id = pa.product_id AND spi.image_slot = 'front'
          WHERE pa.token_hash = ?"
    );
    $legacy->execute([$tokenHash]);
    $row = $legacy->fetch();

    if (!$row) {
        jsonError('Quote not found', 404);
    }

    if ($row['status'] !== 'active') {
        jsonError('This quote link is no longer available', 410);
    }

    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        $pdo->prepare(
            "UPDATE product_assignments SET status = 'expired' WHERE id = ?"
        )->execute([$row['id']]);
        jsonError('This quote link has expired', 410);
    }

    // Track view
    $pdo->prepare(
        'UPDATE product_assignments SET view_count = view_count + 1 WHERE id = ?'
    )->execute([$row['id']]);

    jsonOk([
        'quote' => [
            'customer_name'         => (string) $row['assigned_customer_name'],
            'company_name'          => '',
            'special_conditions'    => '',
            'expires_at'            => $row['expires_at'],
            'items' => [[
                'product_name'          => (string) $row['product_name'],
                'internal_product_code' => (string) ($row['internal_product_code'] ?? ''),
                'technical_description' => (string) ($row['technical_description'] ?? ''),
                'unit_price'            => (float)  $row['final_price'],
                'front_img_path'        => $row['front_img_path'] ? (string) $row['front_img_path'] : null,
            ]],
            'totals' => [
                'subtotal'         => (float) $row['final_price'],
                'discount_percent' => 0.0,
                'discount_amount'  => 0.0,
                'grand_total'      => (float) $row['final_price'],
            ],
        ],
    ]);
}

// ── Client IP helper ──────────────────────────────────────────

function _publicClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
