<?php
/**
 * includes/storage.php — Centralised storage abstraction layer.
 *
 * Phase 1: local filesystem driver.
 * Phase 2 (future): swap to S3/R2/object-storage driver without
 *   changing any business-logic files.
 *
 * ── Environment variables ─────────────────────────────────────
 *   APP_STORAGE_ROOT   Absolute path to the storage root.
 *                      Must be OUTSIDE the web-visible directory tree.
 *                      Example: /var/app-data/webdesign
 *                               C:/MAMP/app_storage/webdesign
 *   STORAGE_DRIVER     'local' (default). Future: 's3', 'r2', 'gcs'.
 *
 * ── Future S3/R2 variables (not used in Phase 1) ─────────────
 *   S3_BUCKET, S3_REGION, S3_ACCESS_KEY, S3_SECRET_KEY, S3_ENDPOINT
 *
 * ── Storage URL ───────────────────────────────────────────────
 *   STORAGE_PUBLIC_BASE  Base URL prefix used by Storage::imageUrl().
 *                        Default: /login/storage-file
 *                        Future S3 example: https://bucket.s3.region.amazonaws.com
 *
 * ── Relative paths in DB ──────────────────────────────────────
 *   Always store relative paths, never absolute.
 *   Example: uploads/products/42/abc123def.jpg
 *   Resolve with Storage::path() or appStoragePath() when reading from disk.
 *
 * If APP_STORAGE_ROOT is not set, the project root is used (backward compat).
 */

// ─────────────────────────────────────────────────────────────
// Legacy procedural helpers (kept for backward compatibility)
// All new code should use the Storage class below.
// ─────────────────────────────────────────────────────────────

function appStorageRoot(): string
{
    static $root = null;

    if ($root !== null) {
        return $root;
    }

    $envRoot   = trim((string) getenv('APP_STORAGE_ROOT'));
    $candidate = $envRoot !== '' ? $envRoot : dirname(__DIR__);

    $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
    $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);

    // Auto-create if it doesn't exist and we're using a custom root
    if ($envRoot !== '' && !is_dir($candidate)) {
        @mkdir($candidate, 0755, true);
    }

    $root = $candidate;
    return $root;
}

function appStoragePath(string $relativePath): string
{
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);

    return appStorageRoot() . DIRECTORY_SEPARATOR . $normalized;
}

function appStorageDir(string $bucket): string
{
    $bucket = trim($bucket);
    $bucket = trim($bucket, '/\\');

    if ($bucket === '') {
        throw new InvalidArgumentException('Storage bucket cannot be empty');
    }

    $dir = appStoragePath('uploads' . DIRECTORY_SEPARATOR . $bucket);

    // Auto-create bucket directory if missing
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

// ─────────────────────────────────────────────────────────────
// Storage — centralised storage abstraction class
//
// Phase 1: local driver only.
// Designed to be swapped to S3/R2 in a future phase by implementing
// a driver interface without touching business-logic files.
// ─────────────────────────────────────────────────────────────

class Storage
{
    // ── Driver constants ──────────────────────────────────────
    const DRIVER_LOCAL = 'local';
    // const DRIVER_S3 = 's3';   // Phase 2 — not yet implemented

    // ── Bucket / category constants ───────────────────────────
    const CAT_PRODUCTS   = 'products';
    const CAT_CONTRACTS  = 'contracts';
    const CAT_DOCUMENTS  = 'documents';
    const CAT_ENROLLMENT = 'enrollment';

    // ── Allowed MIME types per category ───────────────────────
    const PRODUCT_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/bmp'  => 'bmp',
        'image/avif' => 'avif',
    ];

    const DOCUMENT_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    // ─────────────────────────────────────────────────────────
    // Driver / config
    // ─────────────────────────────────────────────────────────

    /**
     * Active storage driver ('local', future: 's3', 'r2').
     */
    public static function driver(): string
    {
        $d = strtolower(trim((string) getenv('STORAGE_DRIVER')));
        return $d !== '' ? $d : self::DRIVER_LOCAL;
    }

    /**
     * Absolute path to the storage root directory.
     */
    public static function root(): string
    {
        return appStorageRoot();
    }

    // ─────────────────────────────────────────────────────────
    // Path & URL helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Resolve a relative storage path to an absolute filesystem path.
     * Equivalent to appStoragePath(). Does NOT check existence.
     */
    public static function path(string $relativePath): string
    {
        return appStoragePath($relativePath);
    }

    /**
     * Check whether a stored file exists on disk.
     */
    public static function exists(string $relativePath): bool
    {
        if ($relativePath === '') {
            return false;
        }
        return is_file(appStoragePath($relativePath));
    }

    /**
     * Read file contents as a string. Returns false on failure.
     */
    public static function read(string $relativePath): string|false
    {
        if ($relativePath === '') {
            return false;
        }
        $abs = appStoragePath($relativePath);
        return is_file($abs) ? file_get_contents($abs) : false;
    }

    /**
     * Generate a secure serving URL for a product image.
     *
     * Phase 1: routes through /login/storage-file?path={encoded}
     * Phase 2 (S3): would return https://bucket.example.com/{key}?signed=...
     *
     * @param  string $relativePath  Value stored in DB (e.g. uploads/products/5/abc.jpg)
     * @param  string $quoteToken    Optional plain quote token (for public quote pages)
     * @return string
     */
    public static function imageUrl(string $relativePath, string $quoteToken = ''): string
    {
        if ($relativePath === '') {
            return '';
        }

        // Phase 2 placeholder: if STORAGE_DRIVER === 's3', build CDN URL here.
        // if (self::driver() === 's3') { return self::_s3Url($relativePath); }

        $base = rtrim((string) (getenv('STORAGE_PUBLIC_BASE') ?: '/login/storage-file'), '/');
        $url  = $base . '?path=' . rawurlencode($relativePath);

        if ($quoteToken !== '') {
            $url .= '&t=' . rawurlencode($quoteToken);
        }

        return $url;
    }

    /**
     * Alias for imageUrl() — generic URL for any stored file.
     * Contracts should use the dedicated /supplier/contract-file endpoint.
     */
    public static function url(string $relativePath, string $quoteToken = ''): string
    {
        return self::imageUrl($relativePath, $quoteToken);
    }

    // ─────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────

    /**
     * Sanitise a filename for safe storage.
     * Returns only [a-zA-Z0-9._-], max 200 chars, collapses dots.
     * Never use the result as a final stored name — always use a random UUID name.
     * Use this to sanitise the original_filename value stored in the DB.
     */
    public static function safeFilename(string $originalName): string
    {
        $base = basename($originalName);
        // Strip dangerous chars
        $safe = preg_replace('/[^\w.\-]/', '_', $base) ?? '_';
        // Collapse consecutive dots (path traversal via extension confusion)
        $safe = preg_replace('/\.{2,}/', '.', $safe) ?? $safe;
        // Trim leading/trailing dots and underscores
        $safe = trim($safe, '._');
        // Ensure we have something
        if ($safe === '') {
            $safe = 'file';
        }
        return mb_substr($safe, 0, 200);
    }

    /**
     * Validate an uploaded file against MIME and size constraints.
     *
     * Security layers:
     *  1. Upload error check
     *  2. File size check
     *  3. Executable content detection (PHP/JS/HTML/script tags in first bytes)
     *  4. SVG blocking
     *  5. PDF magic-byte check
     *  6. Image MIME via getimagesize() (reads actual binary headers)
     *
     * @param  array  $file         $_FILES entry
     * @param  array  $allowedMimes ['mime/type' => 'ext', ...]
     * @param  int    $maxBytes     Maximum allowed file size
     * @return array  ['ok'=>true,'mime'=>...,'ext'=>...]
     *                or ['ok'=>false,'error'=>'lang_key']
     */
    public static function validateUpload(array $file, array $allowedMimes, int $maxBytes): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'err_file_required'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'err_file_upload_failed'];
        }

        if ($file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'err_file_size'];
        }

        $tmpPath      = $file['tmp_name'];
        $originalName = $file['name'] ?? '';

        // 1. Block executable file extensions regardless of content
        if (self::_hasBlockedExtension($originalName)) {
            return ['ok' => false, 'error' => 'err_file_type'];
        }

        // 2. Check first bytes for PHP/script content
        if (self::_hasExecutableContent($tmpPath)) {
            return ['ok' => false, 'error' => 'err_file_type'];
        }

        // 3. SVG blocking (triple-layer check via isSvgFile if available)
        if (function_exists('isSvgFile')) {
            $rawMime = isset($file['type']) ? (string) $file['type'] : '';
            if (isSvgFile($tmpPath, $originalName, $rawMime)) {
                return ['ok' => false, 'error' => 'err_file_type'];
            }
        } else {
            $extLower = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($extLower === 'svg' || $extLower === 'svgz') {
                return ['ok' => false, 'error' => 'err_file_type'];
            }
        }

        // 4. PDF: magic-byte check (%PDF-)
        $handle = @fopen($tmpPath, 'rb');
        if ($handle !== false) {
            $header = (string) fread($handle, 5);
            fclose($handle);
            if ($header === '%PDF-') {
                if (array_key_exists('application/pdf', $allowedMimes)) {
                    return ['ok' => true, 'mime' => 'application/pdf', 'ext' => 'pdf'];
                }
                return ['ok' => false, 'error' => 'err_file_type'];
            }
        }

        // 5. Image MIME via getimagesize() (trusts binary headers, not extension/Content-Type)
        $imgInfo = @getimagesize($tmpPath);
        if ($imgInfo !== false) {
            $mime = image_type_to_mime_type($imgInfo[2]);

            // Extra SVG guard (some renderers)
            if (stripos($mime, 'svg') !== false) {
                return ['ok' => false, 'error' => 'err_file_type'];
            }

            if (array_key_exists($mime, $allowedMimes)) {
                return ['ok' => true, 'mime' => $mime, 'ext' => $allowedMimes[$mime]];
            }
        }

        return ['ok' => false, 'error' => 'err_file_type'];
    }

    // ─────────────────────────────────────────────────────────
    // Upload
    // ─────────────────────────────────────────────────────────

    /**
     * Move an uploaded file into managed storage.
     *
     * Generates a random UUID filename — never uses the original name as the
     * stored filename. The original filename is returned in 'original_filename'
     * and should be saved in the DB.
     *
     * Always stores RELATIVE path in the DB (e.g. uploads/products/5/abc.jpg).
     * Resolve to absolute path with Storage::path() when needed.
     *
     * @param  array  $file      $_FILES entry
     * @param  string $category  Bucket name ('products', 'contracts', 'documents', ...)
     * @param  array  $options {
     *   'subdir'    => string,  Optional subdirectory within the bucket (e.g. product_id)
     *   'mimes'     => array,   Override allowed MIME map
     *   'max_bytes' => int,     Override max size (default 5 MB)
     * }
     * @return array ['ok'=>true,'relative_path','original_filename','mime','ext','size','hash']
     *               or ['ok'=>false,'error'=>'lang_key']
     */
    public static function putUploadedFile(array $file, string $category, array $options = []): array
    {
        $allowedMimes = $options['mimes']     ?? self::_defaultMimes($category);
        $maxBytes     = $options['max_bytes'] ?? 5 * 1024 * 1024;
        $subdir       = isset($options['subdir']) ? (string) $options['subdir'] : '';

        // Validate
        $result = self::validateUpload($file, $allowedMimes, $maxBytes);
        if (!$result['ok']) {
            return $result;
        }

        $ext      = $result['ext'];
        $mime     = $result['mime'];
        $origName = self::safeFilename($file['name'] ?? 'file');

        // Generate random storage name (never use original name as path)
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;

        // Build destination directory
        $bucket  = appStorageDir($category);
        $destDir = $bucket;
        if ($subdir !== '') {
            $subdirNorm = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $subdir), DIRECTORY_SEPARATOR);
            // Path traversal guard for subdir
            if (strpos($subdirNorm, '..') !== false) {
                return ['ok' => false, 'error' => 'err_file_store'];
            }
            $destDir = $bucket . DIRECTORY_SEPARATOR . $subdirNorm;
        }

        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            return ['ok' => false, 'error' => 'err_file_store'];
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . $safeName;

        // Relative path to store in DB (always forward slashes)
        $relDir       = 'uploads/' . $category . ($subdir !== '' ? '/' . str_replace('\\', '/', $subdir) : '');
        $relativePath = $relDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['ok' => false, 'error' => 'err_file_store'];
        }

        $fileHash = hash_file('sha256', $destPath) ?: null;

        return [
            'ok'                => true,
            'relative_path'     => $relativePath,
            'original_filename' => $origName,
            'mime'              => $mime,
            'ext'               => $ext,
            'size'              => (int) ($file['size'] ?? 0),
            'hash'              => $fileHash,
            // NOTE: abs_path is for internal use only — NEVER expose to clients
            '_abs_path'         => $destPath,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Delete
    // ─────────────────────────────────────────────────────────

    /**
     * Delete a stored file.
     * Business rules must be enforced by the caller before calling this.
     *
     * @return bool  true if deleted or already absent, false on error
     */
    public static function delete(string $relativePath): bool
    {
        if ($relativePath === '') {
            return true;
        }
        $abs = appStoragePath($relativePath);
        if (!is_file($abs)) {
            return true; // Already gone
        }
        return @unlink($abs);
    }

    // ─────────────────────────────────────────────────────────
    // Serve
    // ─────────────────────────────────────────────────────────

    /**
     * Stream a stored file to the HTTP client.
     *
     * Security contract:
     *  - Caller MUST perform RBAC authorization before calling this method.
     *  - Path traversal is prevented: resolved absolute path must start with
     *    the expected bucket root prefix.
     *  - MIME type is taken from DB (not re-detected) and must be whitelisted.
     *  - Physical path is never exposed in response headers or output.
     *
     * @param  string $relativePath  Relative path from DB
     * @param  string $bucket        Expected bucket ('products', 'contracts', ...)
     * @param  string $mime          MIME type from DB
     * @param  string $originalName  Original filename from DB (for Content-Disposition)
     * @param  bool   $download      Force download if true; inline display if false
     */
    public static function serve(
        string $relativePath,
        string $bucket,
        string $mime,
        string $originalName = '',
        bool   $download = false
    ): void {
        // MIME whitelist — only safe, non-executable types allowed
        $serveableMimes = [
            'image/jpeg'      => true,
            'image/png'       => true,
            'image/webp'      => true,
            'image/gif'       => true,
            'image/bmp'       => true,
            'image/avif'      => true,
            'application/pdf' => true,
        ];

        if (!isset($serveableMimes[$mime])) {
            http_response_code(403);
            exit('Unsupported file type.');
        }

        // Resolve expected bucket root
        $bucketRoot = realpath(appStorageDir($bucket));
        if ($bucketRoot === false) {
            http_response_code(500);
            exit('Storage configuration error.');
        }

        // Resolve requested path
        $relNorm = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        $absPath = realpath(appStoragePath($relNorm));

        // Path traversal guard: resolved path MUST be inside the bucket root
        if ($absPath === false
            || strpos($absPath, $bucketRoot) !== 0
            || !is_file($absPath)) {
            http_response_code(404);
            exit('File not found.');
        }

        $disposition = $download ? 'attachment' : 'inline';
        $safeName    = preg_replace('/[^\w.\-]/', '_', basename($originalName ?: $relativePath));

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($absPath));
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        readfile($absPath);
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // Phase 2 stubs — S3/R2 driver (not yet implemented)
    // ─────────────────────────────────────────────────────────

    /**
     * [PHASE 2 STUB] Upload file to S3/R2.
     * When STORAGE_DRIVER=s3, putUploadedFile() will delegate here.
     *
     * @codeCoverageIgnore
     * @todo Implement with aws/aws-sdk-php or Guzzle when migrating to S3.
     */
    private static function _s3Put(string $tmpPath, string $key, string $mime): bool
    {
        // Phase 2: use S3 SDK
        // $s3 = new \Aws\S3\S3Client([...]);
        // $s3->putObject(['Bucket' => S3_BUCKET, 'Key' => $key, 'Body' => ...]);
        throw new \RuntimeException('S3 driver not yet implemented.');
    }

    /**
     * [PHASE 2 STUB] Generate a pre-signed S3/CDN URL.
     *
     * @codeCoverageIgnore
     * @todo Return CloudFront / R2 signed URL when ready.
     */
    private static function _s3Url(string $key): string
    {
        // Phase 2: return signed URL from S3/CloudFront/R2
        throw new \RuntimeException('S3 driver not yet implemented.');
    }

    // ─────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Check whether the original filename has a blocked/executable extension.
     */
    private static function _hasBlockedExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $blocked = [
            'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'phps',
            'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx',
            'html', 'htm', 'xhtml', 'xml',
            'svg', 'svgz',
            'sh', 'bash', 'zsh', 'fish',
            'py', 'rb', 'pl', 'cgi', 'asp', 'aspx', 'htaccess',
            'exe', 'bat', 'cmd', 'ps1',
        ];
        return in_array($ext, $blocked, true);
    }

    /**
     * Check first 512 bytes of a file for PHP/script execution signatures.
     * Guards against polyglot attacks (e.g. image+PHP combined file).
     */
    private static function _hasExecutableContent(string $tmpPath): bool
    {
        $handle = @fopen($tmpPath, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = strtolower((string) fread($handle, 512));
        fclose($handle);

        return strpos($header, '<?php') !== false
            || strpos($header, '<?=')   !== false
            || strpos($header, '<script') !== false;
    }

    /**
     * Default allowed MIME types per bucket/category.
     */
    private static function _defaultMimes(string $category): array
    {
        return match ($category) {
            'contracts', 'documents', 'enrollment' => self::DOCUMENT_MIMES,
            'products', 'product_photos'            => self::PRODUCT_MIMES,
            default                                 => self::DOCUMENT_MIMES,
        };
    }
}
