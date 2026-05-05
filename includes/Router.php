<?php
/**
 * includes/Router.php — Progressive front controller router.
 *
 * A zero-dependency, lightweight router that coexists with the existing
 * direct-access PHP file structure.  Legacy .php URLs continue to work
 * exactly as before — this router only activates when requests flow
 * through router.php (the front controller entry point).
 *
 * Features:
 *  - Register GET / POST / ANY routes with optional role restrictions.
 *  - Delegates authentication to the existing requireAuth() / requireRole()
 *    functions from includes/auth.php — NO duplicate auth logic.
 *  - Supports {param} path placeholders for parameter extraction.
 *  - Marks token-only public routes so no session privilege is applied.
 *  - Falls through gracefully when no route matches (returns false).
 *
 * Role handling:
 *  - public = true      → no authentication check (login page, enroll, quote).
 *  - tokenRoute = true  → public token-only link (quote); logged-in users are
 *                         not treated as authenticated for this route.
 *  - roles = [...]      → requireAuth() + requireRole($roles) enforced.
 *  - roles = []         → requireAuth() only (any authenticated role allowed).
 *
 * Admin → Owner parity:
 *  Every route that grants access to 'admin' also grants access to 'owner'
 *  unless the route is owner-exclusive (e.g. /owner/users, /owner/business-units).
 *  This is enforced in config/routes.php, not here.
 *
 * Usage (in router.php):
 *   require_once __DIR__ . '/includes/Router.php';
 *   require_once __DIR__ . '/config/routes.php';   // registers routes
 *   Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
 */

final class Router
{
    /** @var array<int, array<string,mixed>> Registered routes. */
    private static array $routes = [];

    /**
     * URL base path if the app is mounted at a sub-directory.
     * E.g. '/login' when served at http://localhost/login/
     * Set via Router::setBasePath() in config/routes.php.
     */
    private static string $basePath = '';

    // ── Configuration ────────────────────────────────────────

    /**
     * Set the URL base path prefix for this deployment.
     * Strip trailing slash; keep leading slash.
     * Examples:
     *   Router::setBasePath('/login');   // MAMP at /login/
     *   Router::setBasePath('');         // Root deployment
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    /** Returns the configured base path. */
    public static function basePath(): string
    {
        return self::$basePath;
    }

    // ── Route registration ────────────────────────────────────

    /**
     * Register a GET route.
     *
     * @param string   $path        URI path relative to basePath, e.g. '/admin/products'.
     *                              Supports {param} placeholders: '/quote/{token}'.
     * @param callable $handler     Invoked when the route matches.
     *                              Receives array $params (path parameter values).
     * @param string[] $roles       Allowed roles. Empty array = any authenticated user.
     *                              Ignored when $public or $tokenRoute is true.
     * @param bool     $public      If true, no authentication check is applied.
     * @param bool     $tokenRoute  If true, route is public and token-isolated.
     *                              Logged-in users are served the page WITHOUT their
     *                              session privileges (internal data stays hidden).
     */
    public static function get(
        string   $path,
        callable $handler,
        array    $roles      = [],
        bool     $public     = false,
        bool     $tokenRoute = false
    ): void {
        self::register('GET', $path, $handler, $roles, $public, $tokenRoute);
    }

    /**
     * Register a POST route.
     *
     * @see Router::get() for parameter documentation.
     */
    public static function post(
        string   $path,
        callable $handler,
        array    $roles      = [],
        bool     $public     = false,
        bool     $tokenRoute = false
    ): void {
        self::register('POST', $path, $handler, $roles, $public, $tokenRoute);
    }

    /**
     * Register a route that responds to both GET and POST.
     * Internally calls Router::get() + Router::post().
     *
     * Most legacy pages accept both methods (GET renders form, POST processes it),
     * so this is the most common registration method.
     *
     * @see Router::get() for parameter documentation.
     */
    public static function any(
        string   $path,
        callable $handler,
        array    $roles      = [],
        bool     $public     = false,
        bool     $tokenRoute = false
    ): void {
        self::get($path,  $handler, $roles, $public, $tokenRoute);
        self::post($path, $handler, $roles, $public, $tokenRoute);
    }

    // ── Dispatch ─────────────────────────────────────────────

    /**
     * Match the current request against registered routes and execute the handler.
     *
     * Returns true if a route matched and was dispatched.
     * Returns false if no route matched (caller should render a 404).
     *
     * @param string $method  HTTP verb (GET, POST, PUT, …).
     * @param string $uri     Full REQUEST_URI, e.g. '/login/admin/products?q=foo'.
     */
    public static function dispatch(string $method, string $uri): bool
    {
        // ── Normalize URI ────────────────────────────────────
        // Strip query string; keep only the path segment.
        $path = (string) strtok($uri, '?');

        // Strip the configured base path prefix.
        $base = self::$basePath;
        if ($base !== '' && strncmp($path, $base, strlen($base)) === 0) {
            $path = substr($path, strlen($base));
        }

        // Ensure exactly one leading slash.
        $path = '/' . ltrim($path, '/');

        // Remove trailing slash (except for root '/').
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $method = strtoupper($method);

        // ── Route matching ────────────────────────────────────
        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = [];
            if (!self::matchPath($route['path'], $path, $params)) {
                continue;
            }

            // Route matched — enforce access control before invoking handler.
            self::enforceAccess($route);

            // Execute the handler (typically: require the legacy PHP file).
            ($route['handler'])($params);
            return true;
        }

        return false;
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Emit a 404 Not Found response.
     * The front controller calls this when dispatch() returns false.
     */
    public static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="UTF-8">'
            . '<title>404 — Page Not Found</title>'
            . '</head><body>'
            . '<h1>404 — Page Not Found</h1>'
            . '<p>The page you requested does not exist.</p>'
            . '<p><a href="/login/">Return to login</a></p>'
            . '</body></html>';
    }

    // ── Private helpers ───────────────────────────────────────

    /** Internal: add a route definition to the registry. */
    private static function register(
        string   $method,
        string   $path,
        callable $handler,
        array    $roles,
        bool     $public,
        bool     $tokenRoute
    ): void {
        self::$routes[] = [
            'method'     => strtoupper($method),
            'path'       => $path,
            'handler'    => $handler,
            'roles'      => $roles,
            'public'     => $public,
            'tokenRoute' => $tokenRoute,
        ];
    }

    /**
     * Match a route pattern against the request path.
     *
     * Converts {param} placeholders to named regex groups.
     * Only alphanumeric characters, hyphens, and underscores are accepted
     * as placeholder names.
     *
     * @param  string  $pattern  Route definition, e.g. '/admin/products/{id}'.
     * @param  string  $path     Actual request path, e.g. '/admin/products/42'.
     * @param  array   &$params  Populated with extracted parameter key→value pairs.
     * @return bool              True if the path matches the pattern.
     */
    private static function matchPath(string $pattern, string $path, array &$params): bool
    {
        // Escape the pattern for regex, then restore placeholders.
        $escaped = preg_quote($pattern, '#');
        $regex   = preg_replace('/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/', '(?P<$1>[^/]+)', $escaped);
        $regex   = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return false;
        }

        // Extract named captures only (skip integer-keyed full/partial matches).
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return true;
    }

    /**
     * Enforce authentication and role requirements for a matched route.
     *
     * Delegates entirely to the existing auth layer (includes/auth.php):
     *   requireAuth()   — checks session, idle timeout, DB revalidation.
     *   requireRole()   — checks session role against the allowed list.
     *
     * Token routes and public routes bypass authentication entirely.
     * This ensures quote.php and enroll.php behave identically whether
     * accessed via direct URL or via the clean-URL router.
     */
    private static function enforceAccess(array $route): void
    {
        // Token-only public routes — no session privileges applied.
        // Logged-in admins/owners accessing a quote link must NOT see
        // internal data; the quote.php file handles its own isolation.
        if ($route['tokenRoute'] || $route['public']) {
            return;
        }

        // Authenticated route — full session check + idle timeout enforcement.
        requireAuth();

        // Optional role restriction.
        if (!empty($route['roles'])) {
            requireRole($route['roles']);
        }
    }
}
