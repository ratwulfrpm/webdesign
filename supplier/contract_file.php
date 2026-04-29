<?php
/**
 * /login/supplier/contract_file.php — Secure contract file viewer
 *
 * Streams a supplier contract file to the browser after RBAC validation.
 *
 * Access rules:
 *   supplier  — may only access contracts where supplier_id = their own user_id
 *   admin     — may access any contract (read-only)
 *   owner     — may access any contract (read-only)
 *
 * GET ?id=<contract_id>
 *
 * Delivered inline so the browser can display it natively (PDF viewer,
 * image viewer). The file is NEVER served from a direct public URL.
 *
 * Security notes:
 *   - Path traversal is prevented by validating that the resolved absolute
 *     path starts with the expected uploads/contracts/ prefix.
 *   - Only application/pdf, image/jpeg and image/png are allowed MIME types.
 *   - Requires an active, authenticated session.
 *
 * TODO: if audit_log table exists, log contract view event here.
 */

// ── Security headers ─────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: private, no-store');
// X-Frame-Options SAMEORIGIN allows inline display in browser tabs
header('X-Frame-Options: SAMEORIGIN');

// ── Bootstrap ────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

requireAuth();

$role       = $_SESSION['role']    ?? '';
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$contractId = (int) ($_GET['id']   ?? 0);

if ($contractId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

// Only supplier, admin and owner may access contracts
if (!in_array($role, ['supplier', 'admin', 'owner'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT id, supplier_id, storage_path, original_filename, mime_type, file_size
       FROM supplier_contracts
      WHERE id = ?
      LIMIT 1'
);
$stmt->execute([$contractId]);
$contract = $stmt->fetch();

if (!$contract) {
    http_response_code(404);
    exit('Contract not found.');
}

// RBAC: supplier may only see their own contracts
if ($role === 'supplier' && (int) $contract['supplier_id'] !== $userId) {
    http_response_code(403);
    exit('Access denied.');
}

// ── Resolve and validate filesystem path ─────────────────────
$projectRoot       = realpath(__DIR__ . '/..');
$contractsRootReal = realpath($projectRoot . '/uploads/contracts');

if ($projectRoot === false || $contractsRootReal === false) {
    http_response_code(500);
    exit('Storage configuration error.');
}

// Build absolute path from stored relative path (always forward slashes)
$relPath = ltrim(str_replace('\\', '/', $contract['storage_path']), '/');
$absPath = realpath($projectRoot . '/' . $relPath);

// Path traversal guard: resolved path must be inside uploads/contracts/
if ($absPath === false
    || strpos($absPath, $contractsRootReal) !== 0
    || !is_file($absPath)) {
    http_response_code(404);
    exit('File not found.');
}

// Whitelist of allowed MIME types
$allowedMimes = [
    'application/pdf' => true,
    'image/jpeg'      => true,
    'image/png'       => true,
];

$mime = $contract['mime_type'];
if (!isset($allowedMimes[$mime])) {
    http_response_code(403);
    exit('Unsupported file type.');
}

// ── Stream file ──────────────────────────────────────────────
// Use original filename in Content-Disposition but sanitise it
$safeName = preg_replace('/[^\w.\-]/', '_', basename((string) $contract['original_filename']));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absPath));
header('Content-Disposition: inline; filename="' . $safeName . '"');

readfile($absPath);
exit;
