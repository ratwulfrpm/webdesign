<?php
/**
 * includes/image_validate.php
 *
 * Shared image validation helpers used by add_product.php and product_view.php.
 *
 * Security notes
 * ──────────────
 * • SVG files are EXPLICITLY BLOCKED regardless of what getimagesize() or
 *   any other function reports. SVG/SVGZ can embed JavaScript and cause
 *   Stored XSS. The check is triple-layered:
 *     1. File extension (.svg / .svgz)
 *     2. First-bytes sniff for the UTF-8 BOM + "<svg" pattern and "<?xml" + "<svg"
 *     3. Declared MIME type contains "svg"
 * • Allowed formats: JPEG, PNG, WEBP, GIF, BMP, AVIF.
 *   All are validated by getimagesize() which reads actual binary headers,
 *   so a renamed file (e.g. evil.php → evil.jpg) will be rejected.
 * • Extension is derived from the validated MIME, never from the original filename.
 */

// ─────────────────────────────────────────────────────────────
// Allowed MIME → safe file extension map
// ─────────────────────────────────────────────────────────────
const ALLOWED_IMAGE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/bmp'  => 'bmp',
    'image/avif' => 'avif',
];

// ─────────────────────────────────────────────────────────────
// SVG detection — returns true if the file is (or looks like) SVG
// ─────────────────────────────────────────────────────────────
function isSvgFile(string $tmpPath, string $originalName, string $declaredMime): bool
{
    // 1. Extension check (case-insensitive)
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'svg' || $ext === 'svgz') {
        return true;
    }

    // 2. Declared MIME contains "svg"
    if (stripos($declaredMime, 'svg') !== false) {
        return true;
    }

    // 3. Binary content sniff — read first 1 KB and look for SVG signatures
    $handle = @fopen($tmpPath, 'rb');
    if ($handle === false) {
        return false; // can't read — treat as not SVG (other validators will catch it)
    }
    $header = fread($handle, 1024);
    fclose($handle);

    if ($header === false || $header === '') {
        return false;
    }

    // Strip UTF-8 BOM if present
    if (substr($header, 0, 3) === "\xEF\xBB\xBF") {
        $header = substr($header, 3);
    }

    $headerLower = strtolower($header);

    // SVG/SVGZ magic patterns
    if (
        strpos($headerLower, '<svg')               !== false ||  // plain SVG
        strpos($headerLower, '<!doctype svg')       !== false ||  // SVG doctype
        (strpos($headerLower, '<?xml') !== false &&
         strpos($headerLower, 'svg')   !== false)                // XML + svg namespace
    ) {
        return true;
    }

    // SVGZ is gzip-compressed SVG — gzip magic bytes: 0x1F 0x8B
    if (strlen($header) >= 2 && $header[0] === "\x1F" && $header[1] === "\x8B") {
        // Could be SVGZ — decompress first 512 bytes and check
        $decompressed = @gzdecode(substr($header, 0, 1024));
        if ($decompressed !== false) {
            $dcLower = strtolower($decompressed);
            if (strpos($dcLower, '<svg') !== false || strpos($dcLower, '<!doctype svg') !== false) {
                return true;
            }
        }
    }

    return false;
}

// ─────────────────────────────────────────────────────────────
// Contract file size limit — 10 MB
// ─────────────────────────────────────────────────────────────
if (!defined('CONTRACT_MAX_BYTES')) {
    define('CONTRACT_MAX_BYTES', 10 * 1024 * 1024);
}

// ─────────────────────────────────────────────────────────────
// Validate a contract upload (PDF, JPEG or PNG).
//
// Does NOT use finfo (unavailable on some MAMP builds).
// Detection strategy:
//   PDF  → magic bytes check (%PDF-)
//   JPEG → getimagesize() MIME detection
//   PNG  → getimagesize() MIME detection
//   SVG  → explicitly blocked via isSvgFile()
//
// Returns: ['ok' => true, 'mime' => '...', 'ext' => '...']
//       or ['ok' => false, 'error' => 'lang_key']
// ─────────────────────────────────────────────────────────────
function validateContractFile(array $file, int $maxBytes): array
{
    // No file selected
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'err_contract_required'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'err_contract_save'];
    }

    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'err_contract_size'];
    }

    $tmpPath      = $file['tmp_name'];
    $originalName = $file['name'] ?? '';

    // ── PDF: check magic bytes (%PDF-) ────────────────────────
    $handle = @fopen($tmpPath, 'rb');
    if ($handle !== false) {
        $header = (string) fread($handle, 5);
        fclose($handle);
        if ($header === '%PDF-') {
            return ['ok' => true, 'mime' => 'application/pdf', 'ext' => 'pdf'];
        }
    }

    // ── Image: JPEG or PNG via getimagesize() ─────────────────
    $imgInfo = @getimagesize($tmpPath);
    $imgMime = ($imgInfo !== false) ? image_type_to_mime_type($imgInfo[2]) : '';

    // Block SVG using existing helper
    if (isSvgFile($tmpPath, $originalName, $imgMime)) {
        return ['ok' => false, 'error' => 'err_contract_type'];
    }

    $allowedContractImages = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    if (!array_key_exists($imgMime, $allowedContractImages)) {
        return ['ok' => false, 'error' => 'err_contract_type'];
    }

    return ['ok' => true, 'mime' => $imgMime, 'ext' => $allowedContractImages[$imgMime]];
}

// ─────────────────────────────────────────────────────────────
// Validate a single uploaded image file
//
// Returns: ['ok' => true, 'mime' => '...', 'ext' => '...']
//       or ['ok' => false, 'error' => 'lang_key']
// ─────────────────────────────────────────────────────────────
function validateUploadedImage(array $file, int $maxBytes): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'err_img_upload_failed'];
    }

    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'err_img_size'];
    }

    $originalName  = $file['name'] ?? '';
    $tmpPath       = $file['tmp_name'];

    // Derive MIME from actual binary content via getimagesize()
    $imgInfo      = @getimagesize($tmpPath);
    $detectedMime = $imgInfo ? image_type_to_mime_type($imgInfo[2]) : '';

    // Block SVG — checked independently of all other MIME detection
    if (isSvgFile($tmpPath, $originalName, $detectedMime)) {
        return ['ok' => false, 'error' => 'err_img_type'];
    }

    // Must be a recognised raster image type
    if (!array_key_exists($detectedMime, ALLOWED_IMAGE_MIMES)) {
        return ['ok' => false, 'error' => 'err_img_type'];
    }

    return [
        'ok'   => true,
        'mime' => $detectedMime,
        'ext'  => ALLOWED_IMAGE_MIMES[$detectedMime],
    ];
}
