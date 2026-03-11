<?php
/**
 * /jshop/forgot_password.php — Password request form
 *
 * The supplier fills in company name, email, and optional username.
 * The request is stored in the password_requests table and an email
 * notification is sent to the system administrator.
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
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/lang.php';

initLang();

// ── Administrator email to notify ─────────────────────────────
define('ADMIN_EMAIL', 'admin@local');   // Change to the real admin address

// ── Handle POST ───────────────────────────────────────────────
$error   = '';
$success = false;
$fields  = ['company_name' => '', 'email' => '', 'username' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrfValidate();

    $fields['company_name'] = trim(htmlspecialchars($_POST['company_name'] ?? '', ENT_QUOTES, 'UTF-8'));
    $fields['email']        = trim(htmlspecialchars($_POST['email']        ?? '', ENT_QUOTES, 'UTF-8'));
    $fields['username']     = trim(htmlspecialchars($_POST['username']     ?? '', ENT_QUOTES, 'UTF-8'));
    $fields['notes']        = trim(htmlspecialchars($_POST['notes']        ?? '', ENT_QUOTES, 'UTF-8'));

    // Validation
    if ($fields['company_name'] === '' || $fields['email'] === '') {
        $error = t('forgot_error_empty');
    } elseif (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $error = t('forgot_error_email');
    } else {
        // Persist request to DB
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            'INSERT INTO password_requests (company_name, email, username, notes)
             VALUES (:company, :email, :username, :notes)'
        );
        $stmt->execute([
            ':company'  => $fields['company_name'],
            ':email'    => $fields['email'],
            ':username' => $fields['username'] !== '' ? $fields['username'] : null,
            ':notes'    => $fields['notes']    !== '' ? $fields['notes']    : null,
        ]);

        // Email notification to admin (silent failure — request is already logged)
        $subject = '[Local App] Solicitud de clave — ' . $fields['company_name'];
        $body    = "Se ha recibido una solicitud de envío de clave:\n\n"
                 . "Compañía : " . $fields['company_name'] . "\n"
                 . "Correo   : " . $fields['email'] . "\n"
                 . "Usuario  : " . ($fields['username'] !== '' ? $fields['username'] : '(no indicado)') . "\n"
                 . "Notas    : " . ($fields['notes']    !== '' ? $fields['notes']    : '(ninguna)') . "\n\n"
                 . "Fecha    : " . date('Y-m-d H:i:s') . "\n";
        $headers = "From: no-reply@local\r\nContent-Type: text/plain; charset=utf-8\r\n";

        @mail(ADMIN_EMAIL, $subject, $body, $headers);

        $success = true;
    }
}

$lang = currentLang();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= t('forgot_page_title') ?></title>
    <link rel="stylesheet" href="/jshop/css/style.css?v=5">
</head>
<body>

    <!-- Language selector -->
    <div class="lang-selector" role="navigation" aria-label="<?= t('language_label') ?>">
        <a href="?set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>" hreflang="es">ES</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" hreflang="en">EN</a>
    </div>

    <!-- Brand -->
    <div class="brand">
        <span class="brand-icon">
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

    <div class="card" role="main">
        <h1 class="card-title"><?= t('forgot_title') ?></h1>
        <p class="card-subtitle"><?= t('forgot_subtitle') ?></p>

        <?php if ($success): ?>

            <div class="alert alert-success" role="status">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="7.25" stroke="#34c759" stroke-width="1.5"/>
                    <polyline points="4.5,8 7,10.5 11.5,5.5" stroke="#34c759" stroke-width="1.5"
                              stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><?= t('forgot_success') ?></span>
            </div>

            <a href="/jshop/index.php" class="btn-secondary" style="display:flex;margin-top:8px;">
                &larr; <?= t('btn_back') ?>
            </a>

        <?php else: ?>

            <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                    <line x1="8" y1="4.75" x2="8" y2="8.75" stroke="#ff3b30" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="/jshop/forgot_password.php" novalidate>
                <?= csrfField() ?>

                <div class="form-group">
                    <!-- Company name (required) -->
                    <div class="input-wrap">
                        <label for="company_name"><?= t('company_label') ?> *</label>
                        <input
                            type="text"
                            id="company_name"
                            name="company_name"
                            value="<?= htmlspecialchars($fields['company_name'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="<?= t('company_placeholder') ?>"
                            required
                            maxlength="200"
                            autofocus
                        >
                    </div>

                    <!-- Email (required) -->
                    <div class="input-wrap">
                        <label for="email"><?= t('email_label') ?> *</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars($fields['email'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="<?= t('email_placeholder') ?>"
                            autocomplete="email"
                            required
                            maxlength="254"
                        >
                    </div>

                    <!-- Username (optional) -->
                    <div class="input-wrap">
                        <label for="username"><?= t('opt_user_label') ?></label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value="<?= htmlspecialchars($fields['username'], ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="<?= t('opt_user_placeholder') ?>"
                            autocomplete="username"
                            maxlength="60"
                            spellcheck="false"
                            autocapitalize="none"
                        >
                    </div>

                    <!-- Notes (optional) -->
                    <div class="input-wrap">
                        <label for="notes"><?= t('notes_label') ?></label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            maxlength="1000"
                            class="form-textarea"
                        ><?= htmlspecialchars($fields['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <button type="submit" class="btn-primary"><?= t('btn_request') ?></button>

                <div style="margin-top:14px; text-align:center;">
                    <a href="/jshop/index.php" class="card-footer-link">
                        &larr; <?= t('btn_back') ?>
                    </a>
                </div>
            </form>

        <?php endif; ?>
    </div>

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

</body>
</html>
