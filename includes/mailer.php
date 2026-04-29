<?php
/**
 * includes/mailer.php — PHPMailer + Gmail SMTP helper
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
function writeMailLog(string $to, string $subject, string $body, string $code): bool
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return false;

    $line = sprintf(
        "[%s] TO=%s | SUBJECT=%s | CODE=%s\n---\n%s\n===\n",
        date('Y-m-d H:i:s'),
        $to,
        $subject,
        $code,
        $body
    );
    return (bool) file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}

function writeErrorLog(string $message): void
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = sprintf("[%s] SMTP ERROR: %s\n", date('Y-m-d H:i:s'), $message);
    @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}