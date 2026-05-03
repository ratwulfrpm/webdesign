<?php
/**
 * Runtime storage path helpers.
 *
 * APP_STORAGE_ROOT must point to a directory that contains the "uploads" tree.
 * Example:
 *   APP_STORAGE_ROOT=/var/app-data/webdesign
 * Then product images live in:
 *   /var/app-data/webdesign/uploads/products
 *
 * If APP_STORAGE_ROOT is not set, the project root is used.
 */

function appStorageRoot(): string
{
    static $root = null;

    if ($root !== null) {
        return $root;
    }

    $envRoot = trim((string) getenv('APP_STORAGE_ROOT'));
    $candidate = $envRoot !== '' ? $envRoot : dirname(__DIR__);

    $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
    $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);

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

    return appStoragePath('uploads' . DIRECTORY_SEPARATOR . $bucket);
}
