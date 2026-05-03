<?php
/**
 * /login/user/dashboard.php — Deprecated route.
 *
 * End-customer access now happens only via public assignment quote links (token-only).
 */

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: /login/index.php?reason=unsupported_role');
exit;

