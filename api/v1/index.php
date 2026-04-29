<?php
/**
 * api/v1/index.php — REST API v1 front controller & router.
 *
 * URL structure:  /api/v1/{resource}[/{id}[/{action_or_subresource}[/{subid}]]]
 *
 * Supported resources:
 *   products      GET|POST|PATCH|DELETE  + sub: images, keywords
 *   suppliers     GET
 *   contracts     GET|POST
 *   invitations   GET|POST  + action: revoke
 *   search        GET /search/products
 *   assignments   GET|POST|DELETE + action: revoke, clone
 *   public        GET /public/quote?t=TOKEN  (no auth)
 *
 * Security:
 *   - JSON-only responses (Content-Type: application/json).
 *   - Session auth enforced per resource (except /public).
 *   - No HTML output — XSS surface eliminated.
 *   - Directory listing disabled via .htaccess.
 */

// ── Security headers ──────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── Session bootstrap ─────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,      // set true in production (HTTPS)
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ── Core dependencies ─────────────────────────────────────────
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/_helpers.php';

// ── Path parsing ──────────────────────────────────────────────
// Detect base path dynamically so this works regardless of the
// subdirectory the app is installed in (e.g. /login/api/v1).
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$rawUri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rawUri    = rawurldecode($rawUri);

// Strip base dir prefix to get the resource path
$path = str_starts_with($rawUri, $scriptDir)
    ? substr($rawUri, strlen($scriptDir))
    : $rawUri;
$path = '/' . ltrim($path, '/');

// Split into segments, ignore empty parts
$segments = array_values(array_filter(explode('/', $path)));

// ── Segment breakdown ─────────────────────────────────────────
// Segment layout:
//   [0] resource        → e.g. "products", "suppliers", "search", "public"
//   [1] id OR sub-name  → numeric = id; alpha = sub-resource (for "search","public")
//   [2] action/sub      → e.g. "images", "keywords", "revoke", "clone", "quote"
//   [3] subId           → e.g. image slot, keyword string

$resource = $segments[0] ?? '';
$seg1     = $segments[1] ?? '';
$seg2     = $segments[2] ?? '';
$seg3     = $segments[3] ?? '';

$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Determine if seg1 is a numeric ID or a sub-resource name
$id          = ctype_digit($seg1) && $seg1 !== '' ? (int) $seg1 : null;
$sub         = $id !== null ? $seg2 : $seg1;   // action / sub-resource
$subId       = $id !== null ? $seg3 : $seg2;   // e.g. image slot or keyword

// ── Dispatch ──────────────────────────────────────────────────
switch ($resource) {

    case 'products':
        require_once __DIR__ . '/resources/products.php';
        handleProducts($method, $id, $sub, $subId);
        break;

    case 'suppliers':
        require_once __DIR__ . '/resources/suppliers.php';
        handleSuppliers($method, $id);
        break;

    case 'contracts':
        require_once __DIR__ . '/resources/contracts.php';
        handleContracts($method, $id);
        break;

    case 'invitations':
        require_once __DIR__ . '/resources/invitations.php';
        handleInvitations($method, $id, $sub);
        break;

    case 'search':
        // /search/products
        require_once __DIR__ . '/resources/search.php';
        handleSearch($method, $sub);
        break;

    case 'assignments':
        require_once __DIR__ . '/resources/assignments.php';
        handleAssignments($method, $id, $sub);
        break;

    case 'business-units':
        require_once __DIR__ . '/resources/business_units.php';
        handleBusinessUnits($method, $id, $sub, $subId);
        break;

    case 'public':
        // /public/quote?t=TOKEN  — no auth required
        require_once __DIR__ . '/resources/public_quote.php';
        handlePublicQuote($method, $sub);
        break;

    case '':
        jsonOk([
            'api'     => 'webdesign REST API',
            'version' => 'v1',
            'docs'    => 'See README for endpoint reference.',
        ]);

    default:
        jsonError('Not found', 404);
}
