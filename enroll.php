<?php
/**
 * /login/enroll.php — Supplier self-registration via invitation token
 *
 * Public page — no authentication required.
 *
 * GET  ?t=<plain_token>  → validate token, render registration form
 * POST                   → validate form, create user + org_member, mark invitation used
 *
 * Token flow:
 *  1. Admin generates invitation → plain_token stored only in URL, DB holds SHA-256 hash.
 *  2. Supplier opens link, we compute hash('sha256', $_GET['t']) and look up in DB.
 *  3. On successful enroll: mark invitation status='used', insert user, insert org_member.
 *  4. Redirect to index.php with a one-time success flash.
 */

// ── Security headers ─────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');
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

require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/db.php';

// ── Language (no session-based auth, detect from GET/cookie) ─
$supportedLangs = ['es', 'en'];
if (isset($_GET['set_lang']) && in_array($_GET['set_lang'], $supportedLangs, true)) {
    $_SESSION['lang'] = $_GET['set_lang'];
    // Preserve token in redirect
    $token = htmlspecialchars($_GET['t'] ?? '', ENT_QUOTES, 'UTF-8');
    $qs    = $token !== '' ? '?t=' . rawurlencode($_GET['t'] ?? '') : '';
    header('Location: /login/enroll.php' . $qs);
    exit;
}
if (empty($_SESSION['lang'])) {
    $_SESSION['lang'] = 'es';
}
$lang = in_array($_SESSION['lang'], $supportedLangs, true) ? $_SESSION['lang'] : 'es';
// Re-init lang so t() works
initLang();

$pdo = getDB();

// ── Token validation helper ───────────────────────────────────

/**
 * Looks up an invitation by the plain token.
 * Returns the invitation row or a string error key.
 *
 * @param  string $plainToken  Raw token from URL
 * @return array|string        Row array on success, or lang-key string on failure
 */
function loadInvitation(PDO $pdo, string $plainToken): array|string
{
    if (strlen($plainToken) !== 64 || !ctype_xdigit($plainToken)) {
        return 'enroll_token_invalid';
    }

    $tokenHash = hash('sha256', $plainToken);

    $stmt = $pdo->prepare(
        'SELECT si.id, si.org_id, si.role, si.invited_email,
                si.status, si.expires_at
           FROM supplier_invitations si
          WHERE si.token_hash = ?
          LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $inv = $stmt->fetch();

    if (!$inv) {
        return 'enroll_token_invalid';
    }

    // Lazily expire if past expiry
    if ($inv['status'] === 'pending' && strtotime($inv['expires_at']) < time()) {
        $pdo->prepare('UPDATE supplier_invitations SET status = "expired" WHERE id = ?')
            ->execute([$inv['id']]);
        $inv['status'] = 'expired';
    }

    return match ($inv['status']) {
        'pending' => $inv,
        'used'    => 'enroll_token_used',
        'expired' => 'enroll_token_expired',
        'revoked' => 'enroll_token_revoked',
        default   => 'enroll_token_invalid',
    };
}

// ── Read plain token from GET / POST ─────────────────────────
$plainToken = trim($_GET['t'] ?? ($_POST['_token'] ?? ''));

// ── POST handler ──────────────────────────────────────────────
$formError    = '';
$formData     = [];     // repopulate form on error
$tokenError   = '';     // invalid/expired/used token message

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $inv = loadInvitation($pdo, $plainToken);

    if (is_string($inv)) {
        // Token invalid/expired/used — show error, do NOT reveal details
        $tokenError = t($inv);
    } else {
        // ── Collect & sanitise form fields ────────────────────
        $fullName  = trim($_POST['full_name']        ?? '');
        $email     = trim($_POST['email']            ?? '');
        $username  = trim($_POST['username']         ?? '');
        $password  = $_POST['password']              ?? '';
        $confirm   = $_POST['confirm_password']      ?? '';

        $formData = [
            'full_name' => $fullName,
            'email'     => $email,
            'username'  => $username,
        ];

        // ── Validation ────────────────────────────────────────
        if ($fullName === '' || $email === '' || $username === '' || $password === '' || $confirm === '') {
            $formError = t('enroll_err_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formError = t('enroll_err_email');
        } elseif ($inv['invited_email'] !== null
               && strtolower($email) !== strtolower($inv['invited_email'])) {
            $formError = t('enroll_err_email_mismatch');
        } elseif (strlen($username) < 3 || strlen($username) > 60) {
            $formError = t('enroll_err_username_len');
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $formError = t('enroll_err_username_chars');
        } elseif (strlen($password) < 8) {
            $formError = t('enroll_err_password_len');
        } elseif ($password !== $confirm) {
            $formError = t('enroll_err_password_match');
        } else {
            // ── Check uniqueness ──────────────────────────────
            $chkEmail = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chkEmail->execute([$email]);
            if ($chkEmail->fetch()) {
                $formError = t('enroll_err_email_taken');
            } else {
                $chkUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $chkUser->execute([$username]);
                if ($chkUser->fetch()) {
                    $formError = t('enroll_err_username_taken');
                }
            }
        }

        if ($formError === '') {
            // ── Create user + org_member in a transaction ─────
            try {
                $pdo->beginTransaction();

                // 1. Insert user
                $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $insUser = $pdo->prepare(
                    'INSERT INTO users
                        (username, email, password_hash, is_active, role,
                         full_name, preferred_language, first_login)
                     VALUES (?, ?, ?, 1, ?, ?, ?, 1)'
                );
                $insUser->execute([
                    $username,
                    $email,
                    $passwordHash,
                    $inv['role'],       // global role = invitation role
                    $fullName,
                    $lang,
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                // 2. Insert org membership
                $insOrg = $pdo->prepare(
                    'INSERT INTO org_members (user_id, org_id, role, is_active)
                     VALUES (?, ?, ?, 1)'
                );
                $insOrg->execute([$newUserId, $inv['org_id'], $inv['role']]);

                // 3. Mark invitation as used
                $updInv = $pdo->prepare(
                    'UPDATE supplier_invitations
                        SET status = "used",
                            used_by_user_id = ?,
                            used_at = NOW()
                      WHERE id = ?'
                );
                $updInv->execute([$newUserId, $inv['id']]);

                // TODO: if audit_log table exists, log successful enrollment here.

                $pdo->commit();

                // ── Redirect to login with success flash ──────
                $_SESSION['enroll_success'] = t('enroll_success');
                header('Location: /login/index.php');
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $formError = t('enroll_err_save');
            }
        }
    }

} else {
    // GET — just validate the token
    $inv = loadInvitation($pdo, $plainToken);
    if (is_string($inv)) {
        $tokenError = t($inv);
        $inv        = null;
    }
}

// ── HTML ──────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('enroll_page_title') ?></title>
    <link rel="stylesheet" href="/login/css/style.css?v=6">
</head>
<body>

    <!-- Language selector -->
    <div class="lang-selector">
        <a href="?set_lang=es&t=<?= rawurlencode($plainToken) ?>"
           class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>">ES</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=en&t=<?= rawurlencode($plainToken) ?>"
           class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>">EN</a>
    </div>

    <div class="card" style="max-width:480px;">

        <!-- Brand -->
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg"
                     aria-hidden="true" focusable="false">
                    <rect width="44" height="44" rx="10" fill="currentColor" opacity=".08"/>
                    <path d="M22 10C15.373 10 10 15.373 10 22s5.373 12 12 12 12-5.373
                             12-12S28.627 10 22 10zm0 4a8 8 0 1 1 0 16 8 8 0 0 1
                             0-16zm0 3a5 5 0 1 0 0 10 5 5 0 0 0 0-10z"
                          fill="currentColor" opacity=".7"/>
                </svg>
            </div>
        </div>

        <?php if ($tokenError !== ''): ?>
        <!-- ── Token error state ──────────────────────────────── -->
        <div class="alert alert-error" style="margin-bottom:16px;" role="alert">
            <span><?= htmlspecialchars($tokenError, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <p style="text-align:center;font-size:.85rem;color:var(--color-text-muted);">
            <a href="/login/index.php" class="link">← <?= t('btn_back') ?></a>
        </p>

        <?php else: ?>
        <!-- ── Registration form ──────────────────────────────── -->
        <h1 class="card-title"><?= t('enroll_title') ?></h1>
        <p class="card-subtitle"><?= t('enroll_subtitle') ?></p>

        <?php if (isset($inv['expires_at'])): ?>
        <p class="enroll-notice">
            <?= sprintf(
                htmlspecialchars(t('enroll_org_notice'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($inv['expires_at'], ENT_QUOTES, 'UTF-8')
            ) ?>
        </p>
        <?php endif; ?>

        <?php if ($formError !== ''): ?>
        <div class="alert alert-error" style="margin-bottom:16px;" role="alert">
            <span><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="/login/enroll.php" novalidate>
            <?= csrfField() ?>
            <!-- Carry the plain token through POST so validation still works -->
            <input type="hidden" name="_token"
                   value="<?= htmlspecialchars($plainToken, ENT_QUOTES, 'UTF-8') ?>">

            <!-- Full name -->
            <div class="input-wrap">
                <label for="full_name"><?= t('enroll_fullname_label') ?></label>
                <input type="text"
                       id="full_name"
                       name="full_name"
                       placeholder="<?= t('enroll_fullname_ph') ?>"
                       value="<?= htmlspecialchars($formData['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="name"
                       maxlength="200"
                       required>
            </div>

            <!-- Email -->
            <?php
            $emailLocked = isset($inv['invited_email']) && $inv['invited_email'] !== null;
            ?>
            <?php if ($emailLocked): ?>
            <div class="input-wrap">
                <label for="email"><?= t('enroll_email_label') ?></label>
                <p class="input-help" style="margin-bottom:4px;"><?= t('enroll_email_locked') ?></p>
                <input type="email"
                       id="email"
                       name="email"
                       value="<?= htmlspecialchars($inv['invited_email'], ENT_QUOTES, 'UTF-8') ?>"
                       readonly
                       style="background:var(--color-bg);color:var(--color-text-muted);"
                       autocomplete="email"
                       maxlength="254">
            </div>
            <?php else: ?>
            <div class="input-wrap">
                <label for="email"><?= t('enroll_email_label') ?></label>
                <input type="email"
                       id="email"
                       name="email"
                       placeholder="<?= t('enroll_email_ph') ?>"
                       value="<?= htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="email"
                       maxlength="254"
                       required>
            </div>
            <?php endif; ?>

            <!-- Username -->
            <div class="input-wrap">
                <label for="username"><?= t('enroll_username_label') ?></label>
                <input type="text"
                       id="username"
                       name="username"
                       placeholder="<?= t('enroll_username_ph') ?>"
                       value="<?= htmlspecialchars($formData['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off"
                       maxlength="60"
                       pattern="[a-zA-Z0-9_\-]+"
                       required>
                <span class="input-help"><?= t('enroll_username_help') ?></span>
            </div>

            <!-- Password -->
            <div class="input-wrap">
                <label for="password"><?= t('enroll_password_label') ?></label>
                <div style="position:relative;">
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="<?= t('enroll_password_ph') ?>"
                           autocomplete="new-password"
                           minlength="8"
                           required>
                    <button type="button" class="toggle-pw" aria-label="<?= t('show_password') ?>"
                            onclick="togglePw('password', this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Confirm password -->
            <div class="input-wrap">
                <label for="confirm_password"><?= t('enroll_confirm_label') ?></label>
                <div style="position:relative;">
                    <input type="password"
                           id="confirm_password"
                           name="confirm_password"
                           placeholder="<?= t('enroll_confirm_ph') ?>"
                           autocomplete="new-password"
                           minlength="8"
                           required>
                    <button type="button" class="toggle-pw" aria-label="<?= t('show_password') ?>"
                            onclick="togglePw('confirm_password', this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary"><?= t('btn_enroll') ?></button>
        </form>
        <?php endif; ?>

    </div><!-- /.card -->

<script>
// Auto-fill username from the locked email local-part (only when field is empty)
(function () {
    var emailInput    = document.getElementById('email');
    var usernameInput = document.getElementById('username');
    if (!emailInput || !usernameInput) return;

    function deriveUsername(email) {
        var local = email.split('@')[0];
        // keep only allowed chars
        return local.replace(/[^a-zA-Z0-9_\-]/g, '_').slice(0, 60);
    }

    // Pre-fill on page load if username is empty and email has a value
    if (usernameInput.value === '' && emailInput.value.indexOf('@') !== -1) {
        usernameInput.value = deriveUsername(emailInput.value);
    }

    // Also react live when email is editable (open invitation — no locked email)
    if (!emailInput.readOnly) {
        emailInput.addEventListener('blur', function () {
            if (usernameInput.value === '' && emailInput.value.indexOf('@') !== -1) {
                usernameInput.value = deriveUsername(emailInput.value);
            }
        });
    }
}());

function togglePw(fieldId, btn) {
    var input = document.getElementById(fieldId);
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? '<?= t('hide_password') ?>' : '<?= t('show_password') ?>');
}
</script>

</body>
</html>
