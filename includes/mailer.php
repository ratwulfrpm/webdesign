<?php
/**
 * includes/mailer.php � PHPMailer + Gmail SMTP helper
 *
 * Requires: config/mail.php (credentials)
 *           includes/phpmailer/PHPMailer.php
 *           includes/phpmailer/SMTP.php
 *           includes/phpmailer/Exception.php
 *
 * Returns: array ['sent' => bool, 'logged' => bool, 'log_path' => string|null]
 *
 * Dev fallback: if MAIL_USER is still the placeholder, or if sending fails,
 * the code is written to logs/mail.log so the flow works in local dev.
 */

// Load credentials (safe to call multiple times)
if (!defined('MAIL_USER')) {
    require_once __DIR__ . '/../config/mail.php';
}

// Environment-aware helpers
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/SafeLogger.php';

define('MAIL_LOG_DIR', __DIR__ . '/../logs');

// Use PHPMailer autoload (manual, no Composer)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
}

/**
 * Send the email-verification code to a new email address.
 *
 * @param  string $toEmail  Recipient address (the new / pending email)
 * @param  string $code     6-digit code to include
 * @param  string $lang     'es' | 'en'
 * @return array{sent: bool, logged: bool, log_path: string|null}
 */
function sendVerificationEmail(string $toEmail, string $code, string $lang = 'es'): array
{
    $subject  = $lang === 'en'
        ? 'Email verification code'
        : 'Codigo de verificacion de correo';

    $bodyHtml = buildVerificationBodyHtml($toEmail, $code, $lang);
    $bodyText = buildVerificationBody($toEmail, $code, $lang);

    // -- Dev-only: credentials not configured yet --------------------------
    $credPlaceholder = (
        MAIL_USER === 'TU_CORREO@gmail.com' ||
        MAIL_PASS === 'xxxx xxxx xxxx xxxx'
    );

    if ($credPlaceholder) {
        $logged  = writeMailLog($toEmail, $subject, $bodyText, $code);
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }

    // -- Real send via PHPMailer + Gmail -----------------------------------
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPSecure = MAIL_ENCRYPT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = str_replace(' ', '', MAIL_PASS);

        // Disable SSL peer verification in local dev (MAMP on Windows lacks cacert)
        if (!defined('MAIL_VERIFY_SSL') || !MAIL_VERIFY_SSL) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;

        $mail->send();

        return ['sent' => true, 'logged' => false, 'log_path' => null];

    } catch (PHPMailerException $e) {
        $logged = writeMailLog($toEmail, $subject, $bodyText, $code);
        writeErrorLog($e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }
}

// -- Plain-text body -------------------------------------------------------
function buildVerificationBody(string $toEmail, string $code, string $lang): string
{
    if ($lang === 'en') {
        return "Hello,\n\nYou requested to update your account email to: {$toEmail}\n\nYour verification code is:\n\n  {$code}\n\nEnter this code on the verification page within 2 hours.\nIf you did not request this change, you can ignore this message.\n\n- Notificaciones App";
    }
    return "Hola,\n\nHa solicitado actualizar el correo de su cuenta a: {$toEmail}\n\nSu codigo de verificacion es:\n\n  {$code}\n\nIngrese este codigo en la pagina de verificacion dentro de las proximas 2 horas.\nSi usted no solicito este cambio, puede ignorar este mensaje.\n\n- Notificaciones App";
}

// -- HTML body -------------------------------------------------------------
function buildVerificationBodyHtml(string $toEmail, string $code, string $lang): string
{
    $esc = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $h1 = $lang === 'en' ? 'Email verification' : 'Verificacion de correo electronico';
    $p1 = $lang === 'en'
        ? 'You requested to update your account email to:'
        : 'Ha solicitado actualizar el correo de su cuenta a:';
    $p2 = $lang === 'en' ? 'Your verification code:' : 'Su codigo de verificacion:';
    $p3 = $lang === 'en'
        ? 'Enter this code in the app within <strong>2 hours</strong>. If you did not request this change, ignore this message.'
        : 'Ingrese este codigo en la aplicacion dentro de <strong>2 horas</strong>. Si no solicito este cambio, ignore este mensaje.';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>' .
        '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f5f5f7;margin:0;padding:32px 16px;">' .
        '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;padding:36px 32px;box-shadow:0 2px 12px rgba(0,0,0,.07);">' .
        '<h1 style="font-size:1.25rem;font-weight:700;color:#1d1d1f;margin:0 0 8px;">' . $esc($h1) . '</h1>' .
        '<p style="font-size:.9rem;color:#6e6e73;margin:0 0 20px;">' . $esc($p1) . '</p>' .
        '<p style="font-size:.95rem;font-weight:600;color:#1d1d1f;margin:0 0 24px;">' . $esc($toEmail) . '</p>' .
        '<p style="font-size:.85rem;color:#6e6e73;margin:0 0 12px;">' . $esc($p2) . '</p>' .
        '<div style="background:#f5f5f7;border-radius:12px;padding:20px;text-align:center;letter-spacing:.35em;font-size:2rem;font-weight:700;color:#1d1d1f;margin-bottom:24px;">' . $esc($code) . '</div>' .
        '<p style="font-size:.8rem;color:#6e6e73;margin:0;">' . $p3 . '</p>' .
        '</div></body></html>';
}

// -- Dev / fallback log writer ---------------------------------------------

/**
 * Mask an email address for safe logging (e.g. "user@example.com" → "u***@example.com").
 */
function _maskEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2 || $parts[0] === '') return '***';
    $masked = substr($parts[0], 0, 1) . str_repeat('*', max(1, strlen($parts[0]) - 1));
    return $masked . '@' . $parts[1];
}

/**
 * Log a verification-code email event.
 * PROD: never log the code or body. DEV: full detail.
 */
function writeMailLog(string $to, string $subject, string $body, string $code): bool
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return false;

    if (AppConfig::isProd()) {
        // PROD: log only that a verification email was attempted — never the code.
        $line = sprintf(
            "[%s] VERIFY event=verification_code_sent to=%s status=fallback_log\n",
            date('Y-m-d H:i:s'),
            _maskEmail($to)
        );
    } else {
        // DEV ONLY: full detail for developer convenience.
        $line = sprintf(
            "[%s] [DEV ONLY] TO=%s | SUBJECT=%s | CODE=%s\n---\n%s\n===\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $code,
            $body
        );
    }
    return (bool) file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}

function writeErrorLog(string $message): void
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Truncate SMTP error messages to avoid leaking connection details in prod
    $safeMsg = AppConfig::isProd() ? substr($message, 0, 120) : $message;
    $line = sprintf("[%s] SMTP ERROR: %s\n", date('Y-m-d H:i:s'), $safeMsg);
    @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------------
// SUPPLIER INVITATION EMAIL
// ---------------------------------------------------------------------------

/**
 * Send a supplier enrollment invitation to the given email address.
 *
 * @param  string $toEmail    Recipient address
 * @param  string $enrollLink Full enrollment URL (contains plain token)
 * @param  string $lang       'es' | 'en'
 * @return array{sent: bool, logged: bool, log_path: string|null}
 */
function sendInvitationEmail(string $toEmail, string $enrollLink, string $lang = 'es'): array
{
    $subject  = $lang === 'en'
        ? 'You have been invited to register as a supplier'
        : 'Ha sido invitado a registrarse como proveedor';

    $bodyText = buildInvitationBodyText($toEmail, $enrollLink, $lang);
    $bodyHtml = buildInvitationBodyHtml($toEmail, $enrollLink, $lang);

    // Dev-only: credentials not configured yet
    $credPlaceholder = (
        MAIL_USER === 'TU_CORREO@gmail.com' ||
        MAIL_PASS === 'xxxx xxxx xxxx xxxx'
    );

    if ($credPlaceholder) {
        $logged = writeInviteLog($toEmail, $subject, $bodyText, $enrollLink);
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPSecure = MAIL_ENCRYPT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = str_replace(' ', '', MAIL_PASS);

        if (!defined('MAIL_VERIFY_SSL') || !MAIL_VERIFY_SSL) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;

        $mail->send();

        return ['sent' => true, 'logged' => false, 'log_path' => null];

    } catch (PHPMailerException $e) {
        $logged = writeInviteLog($toEmail, $subject, $bodyText, $enrollLink);
        writeErrorLog($e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }
}

function buildInvitationBodyText(string $toEmail, string $enrollLink, string $lang): string
{
    if ($lang === 'en') {
        return "Hello,\n\nYou have been invited to register as a supplier.\n\nClick the link below to create your account (valid for 72 hours):\n\n  {$enrollLink}\n\nIf you did not expect this invitation, you can safely ignore this message.\n\n- Notificaciones App";
    }
    return "Hola,\n\nHa sido invitado a registrarse como proveedor.\n\nHaga clic en el enlace a continuacion para crear su cuenta (valido por 72 horas):\n\n  {$enrollLink}\n\nSi no esperaba esta invitacion, puede ignorar este mensaje.\n\n- Notificaciones App";
}

function buildInvitationBodyHtml(string $toEmail, string $enrollLink, string $lang): string
{
    $esc = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $h1 = $lang === 'en' ? 'Supplier invitation' : 'Invitacion de proveedor';
    $p1 = $lang === 'en'
        ? 'You have been invited to register as a supplier. Click the button below to create your account.'
        : 'Ha sido invitado a registrarse como proveedor. Haga clic en el boton a continuacion para crear su cuenta.';
    $p2 = $lang === 'en' ? 'This link expires in 72 hours.' : 'Este enlace expira en 72 horas.';
    $p3 = $lang === 'en'
        ? 'If you did not expect this invitation, ignore this message.'
        : 'Si no esperaba esta invitacion, ignore este mensaje.';
    $btn = $lang === 'en' ? 'Create account' : 'Crear cuenta';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>' .
        '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f5f5f7;margin:0;padding:32px 16px;">' .
        '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;padding:36px 32px;box-shadow:0 2px 12px rgba(0,0,0,.07);">' .
        '<h1 style="font-size:1.25rem;font-weight:700;color:#1d1d1f;margin:0 0 16px;">' . $esc($h1) . '</h1>' .
        '<p style="font-size:.9rem;color:#6e6e73;margin:0 0 24px;">' . $esc($p1) . '</p>' .
        '<a href="' . $esc($enrollLink) . '" style="display:inline-block;background:#0071e3;color:#fff;text-decoration:none;font-size:.95rem;font-weight:600;padding:14px 28px;border-radius:12px;margin-bottom:24px;">' . $esc($btn) . '</a>' .
        '<p style="font-size:.8rem;color:#6e6e73;margin:0 0 8px;">' . $esc($p2) . '</p>' .
        '<p style="font-size:.8rem;color:#6e6e73;margin:0;">' . $esc($p3) . '</p>' .
        '</div></body></html>';
}

/**
 * Log an invitation email event.
 * PROD: never log the enrollment link (contains plain token) or body. DEV: full detail.
 */
function writeInviteLog(string $to, string $subject, string $body, string $link): bool
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return false;

    if (AppConfig::isProd()) {
        // PROD: do not log the enrollment link — it contains a plain token.
        $line = sprintf(
            "[%s] INVITE event=invitation_sent to=%s status=fallback_log\n",
            date('Y-m-d H:i:s'),
            _maskEmail($to)
        );
    } else {
        // DEV ONLY: full detail including link with token.
        $line = sprintf(
            "[%s] [DEV ONLY] INVITE TO=%s | SUBJECT=%s | LINK=%s\n---\n%s\n===\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $link,
            $body
        );
    }
    return (bool) file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------------
// PASSWORD RESET EMAIL  (admin/owner → user)
// ---------------------------------------------------------------------------

/**
 * Send a temporary password reset email to the user.
 *
 * SECURITY:
 *   - tempPassword is sent to the user's registered email only.
 *   - In production (real SMTP), this function returns no trace of the temp password.
 *   - In dev mode (placeholder credentials), the temp password is written to
 *     logs/mail.log AND returned in the 'dev_temp_password' array key.
 *     This key is ONLY present when credentials are placeholders.
 *     NEVER display 'dev_temp_password' in production UI.
 *
 * @param  string $toEmail      Recipient address
 * @param  string $tempPassword Plain-text temporary password (36-char alphanumeric)
 * @param  string $expiresAt    Human-readable expiry datetime string (display only)
 * @param  string $lang         'es' | 'en' | 'zh'
 * @return array{sent: bool, logged: bool, log_path: string|null, dev_temp_password?: string}
 */
function sendPasswordResetEmail(
    string $toEmail,
    string $tempPassword,
    string $expiresAt,
    string $lang = 'es'
): array {
    $subject  = $lang === 'en'
        ? 'Password reset — temporary password'
        : ($lang === 'zh'
            ? '密码重置 — 临时密码'
            : 'Restablecimiento de contrasena — clave temporal');

    $bodyText = _buildResetBodyText($toEmail, $tempPassword, $expiresAt, $lang);
    $bodyHtml = _buildResetBodyHtml($toEmail, $tempPassword, $expiresAt, $lang);

    $credPlaceholder = (
        MAIL_USER === 'TU_CORREO@gmail.com' ||
        MAIL_PASS === 'xxxx xxxx xxxx xxxx'
    );

    if ($credPlaceholder) {
        // Credentials are placeholder values → skip SMTP.
        // Log the event; include password only in DEV mode (never in prod).
        $logged = _writeResetLog($toEmail, $subject, $bodyText);
        $result = [
            'sent'     => false,
            'logged'   => $logged,
            'log_path' => MAIL_LOG_DIR . '/mail.log',
        ];
        // 'dev_temp_password' key is ONLY present in DEV — UI displays it only when AppConfig::isDev().
        if (AppConfig::isDev()) {
            $result['dev_temp_password'] = $tempPassword;
        }
        return $result;
    }

    // Production: send via PHPMailer (no temp password in return value).
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPSecure = MAIL_ENCRYPT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = str_replace(' ', '', MAIL_PASS);

        if (!defined('MAIL_VERIFY_SSL') || !MAIL_VERIFY_SSL) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;

        $mail->send();

        return ['sent' => true, 'logged' => false, 'log_path' => null];

    } catch (PHPMailerException $e) {
        $logged = _writeResetLog($toEmail, $subject, $bodyText);
        writeErrorLog($e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }
}

function _buildResetBodyText(
    string $toEmail,
    string $tempPassword,
    string $expiresAt,
    string $lang
): string {
    if ($lang === 'en') {
        return "Hello,\n\n"
            . "A temporary password has been generated for your account ({$toEmail}).\n\n"
            . "Temporary password:  {$tempPassword}\n\n"
            . "This password expires: {$expiresAt}\n\n"
            . "When you sign in, the system will immediately ask you to create a new permanent password.\n"
            . "You will not be able to access the dashboard until you complete this step.\n\n"
            . "If you did not request this reset, contact your administrator immediately.\n\n"
            . "- Notificaciones App";
    }
    if ($lang === 'zh') {
        return "您好，\n\n"
            . "已为您的账户（{$toEmail}）生成临时密码。\n\n"
            . "临时密码：{$tempPassword}\n\n"
            . "有效期至：{$expiresAt}\n\n"
            . "登录后，系统将立即要求您设置新的永久密码。\n"
            . "在完成此步骤之前，您将无法访问控制面板。\n\n"
            . "如果您没有请求此重置，请立即联系您的管理员。\n\n"
            . "- Notificaciones App";
    }
    // Spanish (default)
    return "Hola,\n\n"
        . "Se ha generado una contrasena temporal para su cuenta ({$toEmail}).\n\n"
        . "Contrasena temporal:  {$tempPassword}\n\n"
        . "Esta contrasena vence el: {$expiresAt}\n\n"
        . "Al ingresar, el sistema le pedira crear una nueva contrasena permanente.\n"
        . "No podra acceder al panel hasta completar este paso.\n\n"
        . "Si usted no solicito este restablecimiento, contacte a su administrador de inmediato.\n\n"
        . "- Notificaciones App";
}

function _buildResetBodyHtml(
    string $toEmail,
    string $tempPassword,
    string $expiresAt,
    string $lang
): string {
    $esc = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    [$h1, $p1, $p2, $p3, $p4, $p5] = match ($lang) {
        'en' => [
            'Password reset',
            'A temporary password has been generated for your account:',
            'Temporary password:',
            "Expires: {$expiresAt}",
            'When you sign in, you will be required to create a new permanent password immediately. You will not be able to access the system until this step is completed.',
            'If you did not request this reset, contact your administrator immediately.',
        ],
        'zh' => [
            '密码重置',
            '已为您的账户生成临时密码：',
            '临时密码：',
            "有效期至：{$expiresAt}",
            '登录后，系统将立即要求您设置新的永久密码。在完成此步骤之前，您将无法访问控制面板。',
            '如果您没有请求此重置，请立即联系您的管理员。',
        ],
        default => [
            'Restablecimiento de contrasena',
            'Se ha generado una contrasena temporal para su cuenta:',
            'Contrasena temporal:',
            "Vence el: {$expiresAt}",
            'Al ingresar, el sistema le pedira crear una nueva contrasena permanente de inmediato. No podra acceder al panel hasta completar este paso.',
            'Si no solicito este restablecimiento, contacte a su administrador de inmediato.',
        ],
    };

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f5f5f7;margin:0;padding:32px 16px;">'
        . '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;padding:36px 32px;box-shadow:0 2px 12px rgba(0,0,0,.07);">'
        . '<h1 style="font-size:1.25rem;font-weight:700;color:#1d1d1f;margin:0 0 16px;">' . $esc($h1) . '</h1>'
        . '<p style="font-size:.9rem;color:#6e6e73;margin:0 0 8px;">' . $esc($p1) . '</p>'
        . '<p style="font-size:.85rem;font-weight:600;color:#1d1d1f;margin:0 0 12px;">' . $esc($toEmail) . '</p>'
        . '<p style="font-size:.85rem;color:#6e6e73;margin:0 0 8px;">' . $esc($p2) . '</p>'
        . '<div style="background:#f5f5f7;border-radius:12px;padding:16px 20px;font-family:monospace;font-size:1.1rem;font-weight:700;color:#1d1d1f;letter-spacing:.08em;word-break:break-all;margin-bottom:12px;">'
        . $esc($tempPassword)
        . '</div>'
        . '<p style="font-size:.8rem;color:#ff3b30;margin:0 0 16px;font-weight:600;">' . $esc($p3) . '</p>'
        . '<p style="font-size:.85rem;color:#6e6e73;margin:0 0 16px;">' . $esc($p4) . '</p>'
        . '<p style="font-size:.8rem;color:#6e6e73;margin:0;">' . $esc($p5) . '</p>'
        . '</div></body></html>';
}

/**
 * Log a password-reset email event.
 *
 * SECURITY — OWASP A09:
 *   PROD: NEVER log the email body — it contains the temporary password in plain text.
 *         Log only that the event occurred, with a masked recipient.
 *   DEV:  Log full body (including password) for developer convenience,
 *         clearly marked as "[DEV ONLY]".
 */
function _writeResetLog(string $to, string $subject, string $body): bool
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return false;

    if (AppConfig::isProd()) {
        // PROD: log only event metadata. Body contains the temp password — never write it.
        $line = sprintf(
            "[%s] PWD-RESET event=password_reset_notification_attempted to=%s status=fallback_log\n",
            date('Y-m-d H:i:s'),
            _maskEmail($to)
        );
    } else {
        // DEV ONLY: full body for developer convenience (includes temporary password).
        $line = sprintf(
            "[%s] [DEV ONLY] PWD-RESET TO=%s | SUBJECT=%s\n---\n%s\n===\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body
        );
    }
    return (bool) file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------------
// ASSIGNMENT QR SHARE EMAIL
// ---------------------------------------------------------------------------

/**
 * Send an assignment link email including a QR image.
 *
 * @return array{sent: bool, logged: bool, log_path: string|null}
 */
function sendAssignmentQrEmail(
    string $toEmail,
    string $quoteLink,
    string $qrImageUrl,
    string $customerName = '',
    string $companyName = '',
    string $lang = 'es'
): array {
    $subject  = $lang === 'en'
        ? 'Your quote link and QR code'
        : ($lang === 'zh' ? '您的报价链接与二维码' : 'Tu link de cotizacion y codigo QR');

    $bodyText = buildAssignmentQrBodyText($quoteLink, $qrImageUrl, $customerName, $companyName, $lang);
    $bodyHtml = buildAssignmentQrBodyHtml($quoteLink, $qrImageUrl, $customerName, $companyName, $lang);

    $credPlaceholder = (
        MAIL_USER === 'TU_CORREO@gmail.com' ||
        MAIL_PASS === 'xxxx xxxx xxxx xxxx'
    );

    if ($credPlaceholder) {
        $logged = writeQuoteShareLog($toEmail, $subject, $bodyText, $quoteLink, $qrImageUrl);
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPSecure = MAIL_ENCRYPT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = str_replace(' ', '', MAIL_PASS);

        if (!defined('MAIL_VERIFY_SSL') || !MAIL_VERIFY_SSL) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyText;

        $mail->send();

        return ['sent' => true, 'logged' => false, 'log_path' => null];
    } catch (PHPMailerException $e) {
        $logged = writeQuoteShareLog($toEmail, $subject, $bodyText, $quoteLink, $qrImageUrl);
        writeErrorLog($e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'log_path' => MAIL_LOG_DIR . '/mail.log'];
    }
}

function buildAssignmentQrBodyText(
    string $quoteLink,
    string $qrImageUrl,
    string $customerName,
    string $companyName,
    string $lang
): string {
    if ($lang === 'en') {
        return "Hello,\n\nHere is your quote link:\n{$quoteLink}\n\nQR code URL:\n{$qrImageUrl}\n\nCustomer: {$customerName}\nCompany: {$companyName}\n\nThank you.";
    }
    if ($lang === 'zh') {
        return "您好，\n\n这是您的报价链接：\n{$quoteLink}\n\n二维码链接：\n{$qrImageUrl}\n\n客户：{$customerName}\n公司：{$companyName}\n\n谢谢。";
    }
    return "Hola,\n\nAqui tienes tu link de cotizacion:\n{$quoteLink}\n\nURL del codigo QR:\n{$qrImageUrl}\n\nCliente: {$customerName}\nEmpresa: {$companyName}\n\nGracias.";
}

function buildAssignmentQrBodyHtml(
    string $quoteLink,
    string $qrImageUrl,
    string $customerName,
    string $companyName,
    string $lang
): string {
    $esc = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    if ($lang === 'en') {
        $title = 'Your quote link';
        $subtitle = 'Use the button or scan the QR code to open your quote.';
        $btn = 'Open quote';
    } elseif ($lang === 'zh') {
        $title = '您的报价链接';
        $subtitle = '您可以点击按钮或扫描二维码打开报价。';
        $btn = '打开报价';
    } else {
        $title = 'Tu link de cotizacion';
        $subtitle = 'Puedes usar el boton o escanear el codigo QR para abrir tu cotizacion.';
        $btn = 'Abrir cotizacion';
    }

    $meta = '';
    if ($customerName !== '') {
        $meta .= '<p style="margin:0 0 6px;font-size:.82rem;color:#4a6480;"><strong>Cliente:</strong> ' . $esc($customerName) . '</p>';
    }
    if ($companyName !== '') {
        $meta .= '<p style="margin:0 0 14px;font-size:.82rem;color:#4a6480;"><strong>Empresa:</strong> ' . $esc($companyName) . '</p>';
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>' .
        '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f1f6fc;margin:0;padding:30px 14px;">' .
        '<div style="max-width:500px;margin:0 auto;background:#fff;border-radius:16px;padding:30px 28px;border:1px solid #dbe7f5;box-shadow:0 6px 22px rgba(10,38,70,.08);">' .
        '<h1 style="margin:0 0 8px;color:#123b69;font-size:1.2rem;">' . $esc($title) . '</h1>' .
        '<p style="margin:0 0 16px;color:#4a6480;font-size:.9rem;">' . $esc($subtitle) . '</p>' .
        $meta .
        '<a href="' . $esc($quoteLink) . '" style="display:inline-block;background:#0071e3;color:#fff;text-decoration:none;font-size:.92rem;font-weight:600;padding:12px 24px;border-radius:12px;margin-bottom:16px;">' . $esc($btn) . '</a>' .
        '<div style="background:#f8fbff;border:1px solid #dde8f6;border-radius:12px;padding:14px;text-align:center;">' .
        '<img src="' . $esc($qrImageUrl) . '" alt="QR" style="width:210px;max-width:100%;height:auto;border-radius:8px;border:1px solid #dbe7f5;background:#fff;">' .
        '</div>' .
        '<p style="margin:14px 0 0;color:#6d8198;font-size:.78rem;word-break:break-all;">' . $esc($quoteLink) . '</p>' .
        '</div></body></html>';
}

/**
 * Log a quote-share email event.
 * PROD: log only event metadata (no quote link or QR URL). DEV: full detail.
 */
function writeQuoteShareLog(string $to, string $subject, string $body, string $link, string $qr): bool
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return false;

    if (AppConfig::isProd()) {
        // PROD: link may contain a quote token — do not log it.
        $line = sprintf(
            "[%s] SHARE event=quote_share_sent to=%s status=fallback_log\n",
            date('Y-m-d H:i:s'),
            _maskEmail($to)
        );
    } else {
        // DEV ONLY: full detail including link and QR URL.
        $line = sprintf(
            "[%s] [DEV ONLY] SHARE TO=%s | SUBJECT=%s | LINK=%s | QR=%s\n---\n%s\n===\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $link,
            $qr,
            $body
        );
    }
    return (bool) file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}
