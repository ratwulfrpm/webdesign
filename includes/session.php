<?php
/**
 * includes/session.php — Centralized session bootstrap.
 *
 * USAGE: Include this file ONCE as the very first statement of any PHP
 * entry point (web pages, API front controller, CLI scripts that need
 * a session).  It sets secure cookie params and starts the session
 * exactly once.  Safe to require_once from multiple files — the inner
 * PHP_SESSION_NONE guard prevents double-start.
 *
 * Cookie hardening applied here:
 *  - lifetime  = 0   (browser-session cookie; no persistent cookie)
 *  - path      = /   (entire site)
 *  - secure    = auto-detected from HTTPS server variable
 *  - httponly  = true  (JavaScript cannot read the cookie)
 *  - samesite  = Lax   (CSRF mitigation; Strict breaks OAuth/SSO flows)
 *
 * Cache-Control must be set per page for sensitive views; add
 *   header('Cache-Control: no-store, no-cache, must-revalidate');
 * in every authenticated page (already present in existing files).
 */

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
