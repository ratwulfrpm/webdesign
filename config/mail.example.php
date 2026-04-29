<?php
// config/mail.example.php — committed template (no real credentials)
// Copy to config/mail.php and fill in your values before running locally.
// config/mail.php is gitignored and must NEVER be committed.

define('MAIL_USER',      'TU_CORREO@gmail.com');   // Your Gmail address
define('MAIL_PASS',      'xxxx xxxx xxxx xxxx');   // Google App Password (16 chars)
define('MAIL_FROM_NAME', 'Notificaciones App');
define('MAIL_REPLY_TO',  MAIL_USER);
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);
define('MAIL_ENCRYPT',   'tls');
