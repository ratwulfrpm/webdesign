<?php
/**
 * /apple-login/index.php — Login page
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=utf-8');

// ── Bootstrap ────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,   // set to true when running HTTPS
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    header('Location: /apple-login/dashboard.php');
    exit;
}

// ── Handle POST ───────────────────────────────────────────────
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) CSRF check
    csrfValidate();

    // 2) Collect & sanitise input
    $identifier = trim(htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'));
    $password   = $_POST['password'] ?? '';

    // 3) Basic presence validation
    if ($identifier === '' || $password === '') {
        $error = 'Please enter your Apple\u00a0ID and password.';
    } else {
        // 4) Attempt authentication
        $user = attemptLogin($identifier, $password);

        if ($user === false) {
            // Generic message — don't reveal whether the user exists
            $error = 'Incorrect Apple\u00a0ID or password. Try again or&nbsp;<a href="#" class="link">reset your password</a>.';
        } else {
            createSession($user);
            header('Location: /apple-login/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Sign in — Local App</title>
    <link rel="stylesheet" href="/apple-login/css/style.css">
</head>
<body>

    <!-- Brand mark -->
    <div class="brand">
        <span class="brand-icon">
            <!-- Simple leaf/abstract SVG — NOT an Apple asset -->
            <svg viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M22 4C13.166 4 6 11.166 6 20c0 6.188 3.41 11.572 8.443 14.418
                         .518.288 1.098-.118 1.028-.704l-.584-4.867a.998.998 0 0
                         1 .456-.968C17.63 26.702 19.77 26 22 26s4.37.702 6.657 1.879a.998.998
                         0 0 1 .456.968l-.584 4.867c-.07.586.51.992 1.028.704C34.59 31.572 38
                         26.188 38 20c0-8.834-7.166-16-16-16Z"/>
            </svg>
        </span>
        <span class="brand-name">Local App</span>
    </div>

    <!-- Login card -->
    <div class="card" role="main">
        <h1 class="card-title">Sign in</h1>
        <p class="card-subtitle">Use your account to continue</p>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                <line x1="8" y1="4.75" x2="8" y2="8.75" stroke="#ff3b30" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
            </svg>
            <span><?= $error ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="/apple-login/index.php" novalidate autocomplete="on">
            <?= csrfField() ?>

            <div class="form-group">
                <!-- Email / username -->
                <div class="input-wrap">
                    <label for="identifier">Email or username</label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="name@example.com"
                        autocomplete="username"
                        autofocus
                        required
                        maxlength="254"
                        spellcheck="false"
                        autocapitalize="none"
                    >
                </div>

                <!-- Password -->
                <div class="input-wrap">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                        maxlength="128"
                    >
                    <button type="button" class="toggle-pw" aria-label="Show password" onclick="togglePassword()">
                        <svg id="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary">Sign in</button>
        </form>

        <div class="card-footer">
            <a href="#">Forgot password?</a>
            &nbsp;·&nbsp;
            <a href="#">Create account</a>
        </div>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <script>
    function togglePassword() {
        const input   = document.getElementById('password');
        const icon    = document.getElementById('eye-icon');
        const btn     = icon.closest('button');
        const showing = input.type === 'text';

        input.type = showing ? 'password' : 'text';
        btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');

        // Swap to "eye-off" icon when password is visible
        icon.innerHTML = showing
            ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
            : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
              '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>' +
              '<line x1="1" y1="1" x2="23" y2="23"/>';
    }

    // Prevent double-submit
    document.querySelector('form').addEventListener('submit', function () {
        const btn = this.querySelector('.btn-primary');
        btn.disabled = true;
        btn.textContent = 'Signing in…';
    });
    </script>
</body>
</html>
