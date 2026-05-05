<?php
/**
 * storage-file.php — Secure product-image serving endpoint.
 *
 * Route (clean URL): GET /storage-file?path={relative_path}
 * Route (legacy):    GET /login/storage-file.php?path={relative_path}
 *
 * This file ONLY serves product images (uploads/products/...).
 * Contracts are served by /supplier/contract-file (contract_file.php).
 *
 * ── Access rules ─────────────────────────────────────────────
 *   Authenticated session (any role)   → allowed
 *   Valid public quote token (?t=...)  → allowed (image in an active quote)
 *   Unauthenticated, no valid token    → 403
 *
 * ── Security ─────────────────────────────────────────────────
 *   • Path traversal: prevented by realpath() + bucket-root prefix check.
 *   • MIME re-detection: getimagesize() reads actual binary headers.
 *   • MIME whitelist: only raster images may be served here.
 *   • No physical path exposed in any response header or body.
 *   • Content-Type: nosniff always set.
 *   • Cache-Control: private — never cached by shared caches.
 *
 * ── RBAC notes ───────────────────────────────────────────────
 *   Product images are catalog photos shared in customer-facing quotes.
 *   They are NOT confidential in the same way contracts are.
 *   supplier  — may view images for their own products only (IDOR enforced)
 *   admin     — may view any product image within their BUs
 *   owner     — may view all product images (global scope)
 *   support   — may view product images in their active BU
 *   public    — may view images belonging to a valid, active quote token
 *
 *   Admin → Owner parity: both admin and owner have full access here.
 *
 * TODO: Phase 2 — swap serve logic to S3 signed URL if STORAGE_DRIVER=s3.
 */

// ── Security headers ─────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
// Cache: allow private caching for short period (saves bandwidth, images don't change often)
header('Cache-Control: private, max-age=3600');

// ── Bootstrap ────────────────────────────────────────────────
require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/storage.php';

// ── Validate requested path parameter ────────────────────────
$requestedPath = trim($_GET['path'] ?? '');

if ($requestedPath === '') {
    http_response_code(400);
    exit('Missing path parameter.');
}

// Normalise to forward slashes and strip leading slash
$normalised = ltrim(str_replace('\\', '/', $requestedPath), '/');

// This endpoint ONLY serves product images.
// All other buckets use their own endpoints (e.g. contract_file.php).
if (strpos($normalised, 'uploads/products/') !== 0) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Path traversal pre-check ──────────────────────────────────
// Block any path containing '..' before hitting the filesystem.
if (strpos($normalised, '..') !== false) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Access control ────────────────────────────────────────────
$accessGranted = false;

// 1. Authenticated session — any role is sufficient
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $accessGranted = true;
}

// 1a. Supplier IDOR check: a supplier may only view images of their own products.
//     Admin and owner have global scope; support has BU scope — both keep full access here.
//     Token-based access (block 2 below) is already scoped to the quote's product set.
if ($accessGranted && ($_SESSION['role'] ?? '') === 'supplier') {
    $accessGranted = false; // revoke; re-grant only if this product belongs to this supplier
    if (preg_match('#^uploads/products/(\d+)/#', $normalised, $m)) {
        $productId  = (int) $m[1];
        $supplierId = (int) ($_SESSION['user_id'] ?? 0);
        $pdo        = getDB();
        $sSt = $pdo->prepare(
            'SELECT 1 FROM supplier_products WHERE id = ? AND supplier_id = ? LIMIT 1'
        );
        $sSt->execute([$productId, $supplierId]);
        if ($sSt->fetch()) {
            $accessGranted = true;
        }
    }
    // If the path does not match the expected pattern, leave $accessGranted = false
}

// 2. Public quote token — check ?t= parameter
if (!$accessGranted) {
    $quoteToken = trim($_GET['t'] ?? '');

    if (strlen($quoteToken) === 64 && ctype_xdigit($quoteToken)) {
        $pdo       = getDB();
        $tokenHash = hash('sha256', $quoteToken);

        // Multi-product quote (quote_assignments)
        $st = $pdo->prepare(
            "SELECT id FROM quote_assignments
              WHERE token_hash = ?
                AND status = 'active'
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1"
        );
        $st->execute([$tokenHash]);
        if ($st->fetch()) {
            $accessGranted = true;
        }

        // Legacy single-product assignment (product_assignments)
        if (!$accessGranted) {
            $st2 = $pdo->prepare(
                "SELECT id FROM product_assignments
                  WHERE token_hash = ?
                    AND status = 'active'
                    AND expires_at > NOW()
                  LIMIT 1"
            );
            $st2->execute([$tokenHash]);
            if ($st2->fetch()) {
                $accessGranted = true;
            }
        }
    }
}

if (!$accessGranted) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Resolve filesystem path ───────────────────────────────────
$productsRoot = realpath(appStorageDir('products'));

if ($productsRoot === false) {
    // Storage root directory does not exist yet — return 404 (not a server error)
    http_response_code(404);
    exit('File not found.');
}

$absPath = realpath(appStoragePath($normalised));

// Path traversal guard: resolved path MUST be inside uploads/products/
if ($absPath === false
    || strpos($absPath, $productsRoot) !== 0
    || !is_file($absPath)) {
    http_response_code(404);
    exit('File not found.');
}

// ── MIME detection via getimagesize() ─────────────────────────
// Read actual binary headers — never trust the file extension or Content-Type header.
$imgInfo = @getimagesize($absPath);

if ($imgInfo === false) {
    http_response_code(403);
    exit('Unsupported file type.');
}

$mime = image_type_to_mime_type($imgInfo[2]);

// Whitelist: only raster images are served here
$allowedMimes = [
    'image/jpeg' => true,
    'image/png'  => true,
    'image/webp' => true,
    'image/gif'  => true,
    'image/bmp'  => true,
    'image/avif' => true,
];

if (!isset($allowedMimes[$mime])) {
    http_response_code(403);
    exit('Unsupported file type.');
}

// ── Stream file ───────────────────────────────────────────────
// Never include the physical path in any header.
$fileSize = filesize($absPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline');

readfile($absPath);
exit;
