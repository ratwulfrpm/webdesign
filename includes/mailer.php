<?php
/**
 * includes/mailer.php — Simple email helper
 *
 * Wraps PHP's mail() with proper RFC headers.
 * On local dev (MAMP) where mail() often fails, the code is written
 * to a log file so the flow can still be tested.
 *
 * Returns: array ['sent' => bool, 'logged' => bool, 'log_path' => string|null]
 */

define('MAIL_FROM',    'noreply@localapp.dev');
define('MAIL_FROM_NAME', 'Local App');
define('MAIL_LOG_DIR', __DIR__ . '/../logs');

/**
 * Send the email-verification code to a new email address.
 *
 * @param  string $toEmail     Recipient address (the new / pending email)
 * @param  string $code        6-digit code to include in the email
 * @param  string $lang        'es' | 'en'
 * @return array{sent: bool, logged: bool, log_path: string|null}
 */
function sendVerificationEmail(string $toEmail, string $code, string $lang = 'es'): array
{
    $subject = $lang === 'en'
        ? 'Email verification code — Local App'
        : 'Código de verificación de correo — Local App';

    $bodyText = buildVerificationBody($toEmail, $code, $lang);
    $bodyHtml = buildVerificationBodyHtml($toEmail, $code, $lang);

    $fromHeader = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers    = implode("\r\n", [
        $fromHeader,
        'Reply-To: ' . MAIL_FROM,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);

    // Attempt real send
    $sent = @mail($toEmail, $subject, $bodyHtml, $headers);

    // Always write log in dev (also serves as fallback when mail() fails)
    $logPath = null;
    $logged  = false;
    if (!$sent || defined('MAIL_FORCE_LOG')) {
        $logged  = writeMailLog($toEmail, $subject, $bodyText, $code);
        $logPath = MAIL_LOG_DIR . '/mail.log';
    }

    return ['sent' => $sent, 'logged' => $logged, 'log_path' => $logPath];
}

// ── Plain-text body ────────────────────────────────────────────────────────
function buildVerificationBody(string $toEmail, string $code, string $lang): string
{
    if ($lang === 'en') {
        return <<<TEXT
Hello,

You requested to update your account email to: {$toEmail}

Your verification code is:

  {$code}

Enter this code on the verification page within 2 hours.
If you did not request this change, you can ignore this message.

— Local App
TEXT;
    }

    return <<<TEXT
Hola,

Ha solicitado actualizar el correo de su cuenta a: {$toEmail}

Su código de verificación es:

  {$code}

Ingrese este código en la página de verificación dentro de las próximas 2 horas.
Si usted no solicitó este cambio, puede ignorar este mensaje.

— Local App
TEXT;
}

// ── HTML body ──────────────────────────────────────────────────────────────
function buildVerificationBodyHtml(string $toEmail, string $code, string $lang): string
{
    $esc = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $h1  = $lang === 'en' ? 'Email verification' : 'Verificación de correo electrónico';
    $p1  = $lang === 'en'
        ? 'You requested to update your account email to:'
        : 'Ha solicitado actualizar el correo de su cuenta a:';
    $p2  = $lang === 'en' ? 'Your verification code:' : 'Su código de verificación:';
    $p3  = $lang === 'en'
        ? 'Enter this code in the app within <strong>2 hours</strong>. If you did not request this change, ignore this message.'
        : 'Ingrese este código en la aplicación dentro de <strong>2 horas</strong>. Si no solicitó este cambio, ignore este mensaje.';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f5f7;margin:0;padding:32px 16px;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;padding:36px 32px;box-shadow:0 2px 12px rgba(0,0,0,.07);">
    <h1 style="font-size:1.25rem;font-weight:700;color:#1d1d1f;margin:0 0 8px;">{$h1}</h1>
    <p style="font-size:.9rem;color:#6e6e73;margin:0 0 20px;">{$p1}</p>
    <p style="font-size:.95rem;font-weight:600;color:#1d1d1f;margin:0 0 24px;">{$esc($toEmail)}</p>
    <p style="font-size:.85rem;color:#6e6e73;margin:0 0 12px;">{$p2}</p>
    <div style="background:#f5f5f7;border-radius:12px;padding:20px;text-align:center;letter-spacing:.35em;font-size:2rem;font-weight:700;color:#1d1d1f;margin-bottom:24px;">{$esc($code)}</div>
    <p style="font-size:.8rem;color:#6e6e73;margin:0;">{$p3}</p>
  </div>
</body>
</html>
HTML;
}

// ── Dev log writer ─────────────────────────────────────────────────────────
function writeMailLog(string $to, string $subject, string $body, string $code): bool
{
    $dir = MAIL_LOG_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) return false;

    $logFile = $dir . '/mail.log';
    $line    = sprintf(
        "[%s] TO=%s | SUBJECT=%s | CODE=%s\n---\n%s\n===\n",
        date('Y-m-d H:i:s'),
        $to,
        $subject,
        $code,
        $body
    );
    return (bool) file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
