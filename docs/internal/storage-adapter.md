# Storage Adapter

## Overview

All file uploads and image serving are routed through the **Storage abstraction layer** (`includes/storage.php`).

This decouples the application from the physical filesystem so that moving to S3/R2/any object store in the future requires changes in only one place.

---

## Configuration

| Variable | Default | Description |
|---|---|---|
| `APP_STORAGE_ROOT` | `(project root)/uploads` | Absolute path to the upload root. Set outside the webroot for best security. |
| `STORAGE_DRIVER` | `local` | `local` (implemented) or `s3` (Phase 2 stub). |
| `STORAGE_PUBLIC_BASE` | `/login/storage-file` | Web path prefix used by `Storage::imageUrl()`. |

Set these in a `.env` file (or PHP environment) before the application starts.  
Copy `.env.example` → `.env` and adjust paths.

---

## Storage Class (`Storage`)

### Key constants

```php
Storage::DRIVER_LOCAL     // 'local'
Storage::CAT_PRODUCTS     // 'products'
Storage::CAT_CONTRACTS    // 'contracts'
Storage::CAT_DOCUMENTS    // 'documents'
Storage::CAT_ENROLLMENT   // 'enrollment'
Storage::PRODUCT_MIMES    // allowed image MIME array
Storage::DOCUMENT_MIMES   // allowed contract/doc MIME array
```

### Public methods

#### `Storage::driver(): string`
Returns the active driver (`'local'` or `'s3'`).

#### `Storage::imageUrl(string $relativePath, string $quoteToken = ''): string`
Generates a secure URL for a product image, routing through the serving endpoint.

```php
// Authenticated context (session)
$url = Storage::imageUrl('uploads/products/42/abc.jpg');
// → /login/storage-file?path=dXBsb2Fkcy9...

// Public quote context (token-only)
$url = Storage::imageUrl('uploads/products/42/abc.jpg', $plainToken);
// → /login/storage-file?path=dXBsb2Fkcy9...&t=abc123...
```

**Never output raw `file_path` values from the database to HTML or API responses.**
Always use `Storage::imageUrl()`.

#### `Storage::url(string $relativePath, string $quoteToken = ''): string`
Alias for `imageUrl()`.

#### `Storage::safeFilename(string $name): string`
Strips dangerous characters from a filename (path separators, null bytes, collapses dots, trimming).

#### `Storage::validateUpload(array $file, array $allowedMimes, int $maxBytes): array`
5-layer upload validation:
1. PHP upload error check
2. File size limit
3. Blocked dangerous extensions (`.php`, `.phar`, `.exe`, etc.)
4. Executable content sniff (checks for PHP open tags)
5. `getimagesize()` MIME verification

Returns `['ok' => bool, 'error' => string]`.

#### `Storage::putUploadedFile(array $file, string $category, array $options = []): array`
Stores a validated uploaded file.

Options:
- `'owner_id'` — integer, creates `{category}/{owner_id}/` subdirectory
- `'allowed_mimes'` — override allowed MIME array
- `'max_bytes'` — override max bytes

Returns:
```php
[
    'ok'                => true,
    'relative_path'     => 'uploads/products/42/a1b2c3d4.jpg',
    'original_filename' => 'photo.jpg',
    'mime'              => 'image/jpeg',
    'ext'               => 'jpg',
    'size'              => 84231,
    'hash'              => 'sha256hex...',
    '_abs_path'         => '/srv/app_storage/products/42/a1b2c3d4.jpg',  // internal use only
]
```

The stored filename is a **random UUID hex**, never the user-supplied name.

#### `Storage::delete(string $relativePath): bool`
Safely deletes a file given its relative path. Returns `false` if the path is empty or the file does not exist.

#### `Storage::serve(string $relativePath, string $bucket, ?string $mime, ?string $originalName, bool $download): void`
Streams a file to the browser with path traversal protection and MIME whitelist enforcement.
Used internally by `storage-file.php` and `supplier/contract_file.php`.

---

## Secure Serving Endpoints

### Product images — `storage-file.php`

Route: `GET /login/storage-file?path={base64url_encoded_path}[&t={plain_quote_token}]`

Access control:
- **Authenticated users** (any role): full access.
- **Public quote token** (`?t=...`): grants access if token matches an active, non-expired `quote_assignments` or `product_assignments` row that covers the product owning the image.
- Otherwise: **403 Forbidden**.

Only serves files under `uploads/products/`. Returns 403 for any other path prefix.

### Contract files — `supplier/contract_file.php`

Route: `GET /login/supplier/contract-file?uid={supplier_id}&file={filename}`

Access control:
- **supplier role**: own contracts only (IDOR-enforced by `supplier_id === session user_id`).
- **admin / owner roles**: all contracts.

---

## Physical Directory Layout

```
APP_STORAGE_ROOT/
  products/
    {product_id}/
      {uuid}.jpg
      {uuid}.webp
  contracts/
    {supplier_id}/
      {uuid}.pdf
  documents/
    {supplier_id}/
      {uuid}.pdf
  enrollment/
    {new_user_id}/
      {uuid}.pdf
```

If `APP_STORAGE_ROOT` is not set, it falls back to `{project_root}/uploads`.

---

## Adding a New Upload Category

1. Add a constant: `const CAT_MYCAT = 'mycat';`
2. Create the directory automatically via `appStorageDir(Storage::CAT_MYCAT)`.
3. Call `Storage::putUploadedFile($_FILES['f'], Storage::CAT_MYCAT, ['owner_id' => $userId])`.
4. Serve through `Storage::serve()` from a new or existing endpoint.

---

## Phase 2 — S3/R2 Migration

When `STORAGE_DRIVER=s3`:
- `Storage::putUploadedFile()` will call `Storage::_s3Put()` instead of `move_uploaded_file()`.
- `Storage::imageUrl()` will call `Storage::_s3Url()` to return a signed or public CDN URL.
- `Storage::serve()` will redirect to the signed URL instead of streaming locally.

All call sites remain unchanged. Set `STORAGE_DRIVER=s3` and fill in the S3 env vars.

---

## Security Notes

- Files are **never served from webroot** (`/uploads/` has no direct HTTP access — see `uploads/.htaccess`).
- The path parameter in `storage-file.php` is **base64url-encoded** and validated against `..` traversal.
- `realpath()` is used to resolve symlinks before the bucket root check.
- Uploaded filenames are always replaced with a random UUID — user-supplied names go into `original_name` metadata only.
- SVG files are blocked at the MIME and extension level to prevent XSS via `<svg>` injection.
