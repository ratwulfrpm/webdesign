<?php
/**
 * /apple-login/supplier/profile.php — Perfil del proveedor
 *
 * Sections: Información General, Información Legal,
 *           Dirección Oficina Principal, Dirección Fábrica, Contactos.
 *
 * POST actions:
 *  save_profile   — persiste todos los campos del perfil
 *  add_contact    — agrega un contacto a supplier_contacts
 *  delete_contact — elimina un contacto (con id verificado)
 *
 * Behavior:
 *  - Primer ingreso (first_login=1): btn Regresar cierra sesión
 *  - Ingreso posterior (first_login=0): btn Regresar vuelve a summary
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

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tabs.php';

requireAuth();
initLang();
requireRole(['supplier']);

$pdo          = getDB();
$lang         = currentLang();
$countryCol   = $lang === 'en' ? 'name_en' : 'name_es';
$isFirstLogin = (int) ($_SESSION['first_login'] ?? 1) === 1;

// ── Load countries catalog ────────────────────────────────────
$countriesStmt = $pdo->query(
    "SELECT id, phone_code, $countryCol AS name FROM countries ORDER BY name"
);
$countries = $countriesStmt ? $countriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Load current profile ──────────────────────────────────────
$profileStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$profileStmt->execute([(int) $_SESSION['user_id']]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Load contacts ─────────────────────────────────────────────
function loadContacts(PDO $pdo, int $uid): array
{
    $s = $pdo->prepare(
        'SELECT * FROM supplier_contacts
          WHERE supplier_id = ?
          ORDER BY is_primary DESC, id ASC'
    );
    $s->execute([$uid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
$contacts = loadContacts($pdo, (int) $_SESSION['user_id']);

// ── State variables ───────────────────────────────────────────
$errors    = [];
$flash     = '';
$flashType = 'success';

// ── POST dispatcher ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $action = trim($_POST['action'] ?? 'save_profile');

    // ─── Action: save_profile ────────────────────────────────
    if ($action === 'save_profile') {

        $s = fn(string $key, int $max = 255): string =>
            mb_substr(trim($_POST[$key] ?? ''), 0, $max);

        $f = [
            'full_name'               => $s('full_name', 200),
            'company_name'            => $s('company_name', 200),
            'tax_id'                  => $s('tax_id', 50),
            'legal_rep_name'          => $s('legal_rep_name', 200),
            'legal_rep_id'            => $s('legal_rep_id', 50),
            'company_phone_code'      => $s('company_phone_code', 10),
            'company_phone_number'    => $s('company_phone_number', 30),
            'legal_rep_phone_code'    => $s('legal_rep_phone_code', 10),
            'legal_rep_phone_number'  => $s('legal_rep_phone_number', 30),
            'addr_street'             => $s('addr_street', 300),
            'addr_city'               => $s('addr_city', 100),
            'addr_state'              => $s('addr_state', 100),
            'addr_zip'                => $s('addr_zip', 20),
            'addr_country_id'         => (int) ($_POST['addr_country_id'] ?? 0),
            'factory_street'          => $s('factory_street', 300),
            'factory_city'            => $s('factory_city', 100),
            'factory_state'           => $s('factory_state', 100),
            'factory_zip'             => $s('factory_zip', 20),
            'factory_country_id'      => (int) ($_POST['factory_country_id'] ?? 0),
        ];

        // Required fields
        $required = [
            'full_name'            => t('full_name_label'),
            'company_name'         => t('company_name_label'),
            'tax_id'               => t('tax_id_label'),
            'legal_rep_name'       => t('legal_rep_name_label'),
            'legal_rep_id'         => t('legal_rep_id_label'),
            'company_phone_number' => t('company_phone_label'),
            'addr_street'          => t('addr_street_label'),
            'addr_city'            => t('addr_city_label'),
        ];

        foreach ($required as $key => $label) {
            if ($f[$key] === '') {
                $errors[$key] = $label;
            }
        }
        if ($f['addr_country_id'] === 0) {
            $errors['addr_country_id'] = t('addr_country_label');
        }

        if (empty($errors)) {
            $companyPhone = $f['company_phone_code']
                ? $f['company_phone_code'] . ' ' . $f['company_phone_number']
                : $f['company_phone_number'];

            $pdo->prepare(
                'UPDATE users SET
                    full_name               = ?,
                    company_name            = ?,
                    tax_id                  = ?,
                    legal_rep_name          = ?,
                    legal_rep_id            = ?,
                    company_phone_code      = ?,
                    company_phone_number    = ?,
                    legal_rep_phone_code    = ?,
                    legal_rep_phone_number  = ?,
                    phone                   = ?,
                    addr_street             = ?,
                    addr_city               = ?,
                    addr_state              = ?,
                    addr_zip                = ?,
                    addr_country_id         = ?,
                    factory_street          = ?,
                    factory_city            = ?,
                    factory_state           = ?,
                    factory_zip             = ?,
                    factory_country_id      = ?,
                    first_login             = 0
                 WHERE id = ?'
            )->execute([
                $f['full_name'],
                $f['company_name'],
                $f['tax_id']                 ?: null,
                $f['legal_rep_name'],
                $f['legal_rep_id'],
                $f['company_phone_code']     ?: null,
                $f['company_phone_number']   ?: null,
                $f['legal_rep_phone_code']   ?: null,
                $f['legal_rep_phone_number'] ?: null,
                $companyPhone                ?: null,
                $f['addr_street'],
                $f['addr_city'],
                $f['addr_state']             ?: null,
                $f['addr_zip']               ?: null,
                $f['addr_country_id']        ?: null,
                $f['factory_street']         ?: null,
                $f['factory_city']           ?: null,
                $f['factory_state']          ?: null,
                $f['factory_zip']            ?: null,
                $f['factory_country_id']     ?: null,
                (int) $_SESSION['user_id'],
            ]);

            $_SESSION['first_login'] = 0;
            header('Location: /apple-login/supplier/summary.php?saved=1');
            exit;
        }

        // Re-populate on error
        $profile = array_merge($profile, $f);

    // ─── Action: add_contact ─────────────────────────────────
    } elseif ($action === 'add_contact') {

        $cName    = mb_substr(trim($_POST['c_name']        ?? ''), 0, 200);
        $cRole    = mb_substr(trim($_POST['c_role']        ?? ''), 0, 100);
        $cEmail   = mb_substr(trim($_POST['c_email']       ?? ''), 0, 254);
        $cCode    = mb_substr(trim($_POST['c_phone_code']  ?? ''), 0, 8);
        $cPhone   = mb_substr(trim($_POST['c_phone_number']?? ''), 0, 30);
        $cPrimary = isset($_POST['c_is_primary']) ? 1 : 0;

        if ($cName === '') {
            $errors['c_name'] = t('contact_error_name');
        } else {
            if ($cPrimary) {
                $pdo->prepare(
                    'UPDATE supplier_contacts SET is_primary = 0 WHERE supplier_id = ?'
                )->execute([(int) $_SESSION['user_id']]);
            }
            $pdo->prepare(
                'INSERT INTO supplier_contacts
                    (supplier_id, name, role, email, phone_code, phone_number, is_primary)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $_SESSION['user_id'],
                $cName,
                $cRole   ?: null,
                $cEmail  ?: null,
                $cCode   ?: null,
                $cPhone  ?: null,
                $cPrimary,
            ]);

            $contacts  = loadContacts($pdo, (int) $_SESSION['user_id']);
            $flash     = t('contact_added');
        }

    // ─── Action: delete_contact ──────────────────────────────
    } elseif ($action === 'delete_contact') {

        $contactId = (int) ($_POST['contact_id'] ?? 0);
        if ($contactId > 0) {
            $pdo->prepare(
                'DELETE FROM supplier_contacts WHERE id = ? AND supplier_id = ?'
            )->execute([$contactId, (int) $_SESSION['user_id']]);
            $contacts  = loadContacts($pdo, (int) $_SESSION['user_id']);
            $flash     = t('contact_deleted');
        }
    }
}

// ── View helpers ──────────────────────────────────────────────
$esc      = fn($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$val      = fn(string $k): string => htmlspecialchars((string) ($profile[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$clsInput = fn(string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$errMsg   = function (string $key) use ($errors): string {
    if (!isset($errors[$key])) return '';
    $label = htmlspecialchars($errors[$key], ENT_QUOTES, 'UTF-8');
    return '<span class="field-error">' . $label . ' — requerido.</span>';
};

$username  = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$initial   = strtoupper(substr((string) ($_SESSION['username'] ?? '?'), 0, 1));
$csrfField = csrfField();
$csrfToken = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?= t('profile_page_title') ?></title>
    <link rel="stylesheet" href="/apple-login/css/style.css">
</head>
<body>

    <!-- Language selector -->
    <div class="lang-selector">
        <a href="?set_lang=es" class="lang-btn<?= $lang === 'es' ? ' active' : '' ?>">ES</a>
        <span class="lang-sep">|</span>
        <a href="?set_lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>">EN</a>
    </div>

    <!-- Top nav -->
    <div class="top-bar">
        <div class="top-bar-brand">
            <div class="welcome-avatar small"><?= $initial ?></div>
            <span class="top-bar-title"><?= $username ?></span>
        </div>
        <form method="POST" action="/apple-login/logout.php" class="top-bar-logout">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button type="submit" class="btn-secondary btn-sm"><?= t('sign_out') ?></button>
        </form>
    </div>

    <?= renderTabs('profile') ?>

    <div class="page-content">

        <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= $esc($flashType) ?>" role="status"
             style="max-width:760px;margin:0 auto 16px;">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7.25" stroke="#34c759" stroke-width="1.5"/>
                <polyline points="4.5,8 7,10.5 11.5,5.5" stroke="#34c759" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?= $esc($flash) ?></span>
        </div>
        <?php endif; ?>

        <!-- ════════════════════ PROFILE FORM ════════════════════ -->
        <div class="card profile-form-card">

            <h1 class="card-title"><?= t('profile_title') ?></h1>
            <p class="card-subtitle" style="margin-bottom:28px;"><?= t('profile_subtitle') ?></p>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error" role="alert" style="margin-bottom:24px;">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="7.25" stroke="#ff3b30" stroke-width="1.5"/>
                    <line x1="8" y1="4.75" x2="8" y2="8.75" stroke="#ff3b30"
                          stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="8" cy="11" r=".75" fill="#ff3b30"/>
                </svg>
                <span><?= t('profile_error_fields') ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="/apple-login/supplier/profile.php" novalidate>
                <?= $csrfField ?>
                <input type="hidden" name="action" value="save_profile">

                <!-- ══ Sección 1: Información General ════════════ -->
                <div class="form-section">
                    <h2 class="form-section-title">
                        <span class="section-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"
                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <?= t('section_general') ?>
                    </h2>
                    <div class="form-row">
                        <div class="input-wrap">
                            <label for="full_name"><?= t('full_name_label') ?> *</label>
                            <input type="text" id="full_name" name="full_name"
                                   value="<?= $val('full_name') ?>"
                                   placeholder="<?= t('full_name_placeholder') ?>"
                                   class="<?= $clsInput('full_name') ?>"
                                   maxlength="200" required autofocus>
                            <?= $errMsg('full_name') ?>
                        </div>
                        <div class="input-wrap">
                            <label for="company_name"><?= t('company_name_label') ?> *</label>
                            <input type="text" id="company_name" name="company_name"
                                   value="<?= $val('company_name') ?>"
                                   placeholder="<?= t('company_name_ph') ?>"
                                   class="<?= $clsInput('company_name') ?>"
                                   maxlength="200" required>
                            <span class="input-help"><?= t('company_name_help') ?></span>
                            <?= $errMsg('company_name') ?>
                        </div>
                    </div>
                </div>

                <!-- ══ Sección 2: Información Legal ══════════════ -->
                <div class="form-section">
                    <h2 class="form-section-title">
                        <span class="section-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <rect x="2" y="1.5" width="12" height="13" rx="2"
                                      stroke="currentColor" stroke-width="1.5"/>
                                <line x1="5" y1="5.5" x2="11" y2="5.5" stroke="currentColor"
                                      stroke-width="1.5" stroke-linecap="round"/>
                                <line x1="5" y1="8.5" x2="11" y2="8.5" stroke="currentColor"
                                      stroke-width="1.5" stroke-linecap="round"/>
                                <line x1="5" y1="11.5" x2="8"  y2="11.5" stroke="currentColor"
                                      stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <?= t('section_legal') ?>
                    </h2>
                    <div class="form-group" style="gap:14px;">

                        <div class="input-wrap">
                            <label for="tax_id"><?= t('tax_id_label') ?> *</label>
                            <input type="text" id="tax_id" name="tax_id"
                                   value="<?= $val('tax_id') ?>"
                                   placeholder="<?= t('tax_id_placeholder') ?>"
                                   class="<?= $clsInput('tax_id') ?>"
                                   maxlength="50" required>
                            <?= $errMsg('tax_id') ?>
                        </div>

                        <div class="form-row">
                            <div class="input-wrap">
                                <label for="legal_rep_name"><?= t('legal_rep_name_label') ?> *</label>
                                <input type="text" id="legal_rep_name" name="legal_rep_name"
                                       value="<?= $val('legal_rep_name') ?>"
                                       placeholder="<?= t('legal_rep_name_placeholder') ?>"
                                       class="<?= $clsInput('legal_rep_name') ?>"
                                       maxlength="200" required>
                                <?= $errMsg('legal_rep_name') ?>
                            </div>
                            <div class="input-wrap">
                                <label for="legal_rep_id"><?= t('legal_rep_id_label') ?> *</label>
                                <input type="text" id="legal_rep_id" name="legal_rep_id"
                                       value="<?= $val('legal_rep_id') ?>"
                                       placeholder="<?= t('legal_rep_id_placeholder') ?>"
                                       class="<?= $clsInput('legal_rep_id') ?>"
                                       maxlength="50" required>
                                <?= $errMsg('legal_rep_id') ?>
                            </div>
                        </div>

                        <!-- Teléfono compañía -->
                        <div>
                            <label><?= t('company_phone_label') ?> *</label>
                            <div class="phone-pair" style="margin-top:6px;">
                                <div class="input-wrap phone-code-wrap">
                                    <select name="company_phone_code"
                                            aria-label="<?= t('phone_code_placeholder') ?>">
                                        <option value=""><?= t('phone_code_placeholder') ?></option>
                                        <?php foreach ($countries as $c): ?>
                                        <option value="<?= $esc($c['phone_code']) ?>"
                                            <?= ($val('company_phone_code') === $c['phone_code']) ? 'selected' : '' ?>>
                                            <?= $esc($c['phone_code']) ?> (<?= $esc($c['name']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="input-wrap phone-number-wrap">
                                    <input type="tel" name="company_phone_number"
                                           value="<?= $val('company_phone_number') ?>"
                                           placeholder="<?= t('phone_number_placeholder') ?>"
                                           class="<?= $clsInput('company_phone_number') ?>"
                                           maxlength="30">
                                </div>
                            </div>
                            <?= $errMsg('company_phone_number') ?>
                        </div>

                        <!-- Teléfono representante (opcional) -->
                        <div>
                            <label><?= t('legal_rep_phone_label') ?></label>
                            <div class="phone-pair" style="margin-top:6px;">
                                <div class="input-wrap phone-code-wrap">
                                    <select name="legal_rep_phone_code"
                                            aria-label="<?= t('phone_code_placeholder') ?>">
                                        <option value=""><?= t('phone_code_placeholder') ?></option>
                                        <?php foreach ($countries as $c): ?>
                                        <option value="<?= $esc($c['phone_code']) ?>"
                                            <?= ($val('legal_rep_phone_code') === $c['phone_code']) ? 'selected' : '' ?>>
                                            <?= $esc($c['phone_code']) ?> (<?= $esc($c['name']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="input-wrap phone-number-wrap">
                                    <input type="tel" name="legal_rep_phone_number"
                                           value="<?= $val('legal_rep_phone_number') ?>"
                                           placeholder="<?= t('phone_number_placeholder') ?>"
                                           maxlength="30">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══ Sección 3: Dirección Oficina Principal ═════ -->
                <div class="form-section">
                    <h2 class="form-section-title">
                        <span class="section-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M8 1C5.24 1 3 3.24 3 6c0 4 5 9 5 9s5-5 5-9c0-2.76-2.24-5-5-5z"
                                      stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="8" cy="6" r="1.5" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </span>
                        <?= t('section_addr_company') ?>
                    </h2>
                    <div class="form-group" style="gap:14px;">

                        <div class="input-wrap">
                            <label for="addr_street"><?= t('addr_street_label') ?> *</label>
                            <input type="text" id="addr_street" name="addr_street"
                                   value="<?= $val('addr_street') ?>"
                                   placeholder="<?= t('addr_street_placeholder') ?>"
                                   class="<?= $clsInput('addr_street') ?>"
                                   maxlength="300" required>
                            <?= $errMsg('addr_street') ?>
                        </div>

                        <div class="form-row">
                            <div class="input-wrap">
                                <label for="addr_city"><?= t('addr_city_label') ?> *</label>
                                <input type="text" id="addr_city" name="addr_city"
                                       value="<?= $val('addr_city') ?>"
                                       placeholder="<?= t('addr_city_placeholder') ?>"
                                       class="<?= $clsInput('addr_city') ?>"
                                       maxlength="100" required>
                                <?= $errMsg('addr_city') ?>
                            </div>
                            <div class="input-wrap">
                                <label for="addr_state"><?= t('addr_state_label') ?></label>
                                <input type="text" id="addr_state" name="addr_state"
                                       value="<?= $val('addr_state') ?>"
                                       placeholder="<?= t('addr_state_placeholder') ?>"
                                       maxlength="100">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-wrap">
                                <label for="addr_zip"><?= t('addr_zip_label') ?></label>
                                <input type="text" id="addr_zip" name="addr_zip"
                                       value="<?= $val('addr_zip') ?>"
                                       placeholder="<?= t('addr_zip_placeholder') ?>"
                                       maxlength="20">
                            </div>
                            <div class="input-wrap">
                                <label for="addr_country_id"><?= t('addr_country_label') ?> *</label>
                                <select id="addr_country_id" name="addr_country_id"
                                        class="<?= $clsInput('addr_country_id') ?>" required>
                                    <option value=""><?= t('addr_country_default') ?></option>
                                    <?php foreach ($countries as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"
                                        <?= ((int) ($profile['addr_country_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                                        <?= $esc($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?= $errMsg('addr_country_id') ?>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══ Sección 4: Dirección de Fábrica ═══════════ -->
                <div class="form-section">
                    <h2 class="form-section-title">
                        <span class="section-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <rect x="1" y="7" width="14" height="8" rx="1"
                                      stroke="currentColor" stroke-width="1.5"/>
                                <path d="M1 7l4-5h6l4 5"
                                      stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                <line x1="6" y1="11" x2="10" y2="11"
                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <?= t('section_addr_factory') ?>
                    </h2>
                    <div class="form-group" style="gap:14px;">

                        <div class="input-wrap">
                            <label for="factory_street"><?= t('factory_street_label') ?></label>
                            <input type="text" id="factory_street" name="factory_street"
                                   value="<?= $val('factory_street') ?>"
                                   placeholder="<?= t('factory_street_placeholder') ?>"
                                   maxlength="300">
                        </div>

                        <div class="form-row">
                            <div class="input-wrap">
                                <label for="factory_city"><?= t('factory_city_label') ?></label>
                                <input type="text" id="factory_city" name="factory_city"
                                       value="<?= $val('factory_city') ?>"
                                       placeholder="<?= t('factory_city_placeholder') ?>"
                                       maxlength="100">
                            </div>
                            <div class="input-wrap">
                                <label for="factory_state"><?= t('factory_state_label') ?></label>
                                <input type="text" id="factory_state" name="factory_state"
                                       value="<?= $val('factory_state') ?>"
                                       placeholder="<?= t('factory_state_placeholder') ?>"
                                       maxlength="100">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="input-wrap">
                                <label for="factory_zip"><?= t('factory_zip_label') ?></label>
                                <input type="text" id="factory_zip" name="factory_zip"
                                       value="<?= $val('factory_zip') ?>"
                                       placeholder="<?= t('factory_zip_placeholder') ?>"
                                       maxlength="20">
                            </div>
                            <div class="input-wrap">
                                <label for="factory_country_id"><?= t('factory_country_label') ?></label>
                                <select id="factory_country_id" name="factory_country_id">
                                    <option value=""><?= t('factory_country_default') ?></option>
                                    <?php foreach ($countries as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"
                                        <?= ((int) ($profile['factory_country_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
                                        <?= $esc($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ══ Form actions ═══════════════════════════════ -->
                <div class="form-actions">
                    <?php if ($isFirstLogin): ?>
                    <form method="POST" action="/apple-login/logout.php" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <button type="submit" class="btn-secondary"><?= t('btn_back') ?></button>
                    </form>
                    <?php else: ?>
                    <a href="/apple-login/supplier/summary.php" class="btn-secondary">
                        <?= t('btn_back') ?>
                    </a>
                    <?php endif; ?>

                    <button type="submit" class="btn-primary"><?= t('btn_save') ?></button>
                </div>

            </form>
        </div><!-- /profile-form-card -->

        <!-- ════════════════════ CONTACTS SECTION ════════════════ -->
        <div class="card contacts-card">
            <h2 class="card-title"><?= t('section_contacts') ?></h2>
            <p class="card-subtitle"><?= t('contacts_subtitle') ?></p>

            <?php if (empty($contacts)): ?>
            <p class="text-muted" style="font-size:.875rem; margin-bottom:20px;">
                <?= t('no_contacts') ?>
            </p>
            <?php else: ?>
            <div class="table-wrap" style="margin-bottom:20px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= t('col_contact_name') ?></th>
                            <th><?= t('col_contact_role') ?></th>
                            <th><?= t('col_contact_email') ?></th>
                            <th><?= t('col_contact_phone') ?></th>
                            <th><?= t('col_contact_primary') ?></th>
                            <th><?= t('col_contact_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contacts as $ct): ?>
                        <tr>
                            <td><?= $esc($ct['name']) ?></td>
                            <td><?= $esc($ct['role'] ?? '') ?: '<em class="text-muted">—</em>' ?></td>
                            <td><?= $esc($ct['email'] ?? '') ?: '<em class="text-muted">—</em>' ?></td>
                            <td>
                                <?php
                                $ph = trim(($ct['phone_code'] ?? '') . ' ' . ($ct['phone_number'] ?? ''));
                                echo $ph !== '' ? $esc($ph) : '<em class="text-muted">—</em>';
                                ?>
                            </td>
                            <td>
                                <?= (int) $ct['is_primary']
                                    ? '<span class="badge badge-active">' . t('contact_yes') . '</span>'
                                    : '<span class="text-muted">' . t('contact_no') . '</span>' ?>
                            </td>
                            <td class="actions-cell">
                                <form method="POST" action="/apple-login/supplier/profile.php"
                                      onsubmit="return confirm('<?= t('btn_delete') ?>?');"
                                      style="display:inline;">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="action"     value="delete_contact">
                                    <input type="hidden" name="contact_id" value="<?= (int) $ct['id'] ?>">
                                    <button type="submit" class="btn-tbl btn-danger">
                                        <?= t('btn_delete') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Add contact form -->
            <div class="add-contact-panel">
                <p class="panel-title"><?= t('btn_add_contact') ?></p>

                <?php if (isset($errors['c_name'])): ?>
                <div class="alert alert-error" style="margin-bottom:14px;" role="alert">
                    <span><?= htmlspecialchars($errors['c_name'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="/apple-login/supplier/profile.php" novalidate>
                    <?= $csrfField ?>
                    <input type="hidden" name="action" value="add_contact">

                    <div class="form-row">
                        <div class="input-wrap">
                            <label for="c_name"><?= t('contact_name_label') ?></label>
                            <input type="text" id="c_name" name="c_name"
                                   value="<?= $esc($_POST['c_name'] ?? '') ?>"
                                   placeholder="<?= t('contact_name_placeholder') ?>"
                                   class="<?= isset($errors['c_name']) ? 'is-invalid' : '' ?>"
                                   maxlength="200">
                        </div>
                        <div class="input-wrap">
                            <label for="c_role"><?= t('contact_role_label') ?></label>
                            <input type="text" id="c_role" name="c_role"
                                   value="<?= $esc($_POST['c_role'] ?? '') ?>"
                                   placeholder="<?= t('contact_role_placeholder') ?>"
                                   maxlength="100">
                        </div>
                    </div>

                    <div class="form-row" style="margin-top:12px;">
                        <div class="input-wrap">
                            <label for="c_email"><?= t('contact_email_label') ?></label>
                            <input type="email" id="c_email" name="c_email"
                                   value="<?= $esc($_POST['c_email'] ?? '') ?>"
                                   placeholder="<?= t('contact_email_placeholder') ?>"
                                   maxlength="254">
                        </div>
                        <div>
                            <label><?= t('contact_phone_label') ?></label>
                            <div class="phone-pair" style="margin-top:6px;">
                                <div class="input-wrap phone-code-wrap">
                                    <select name="c_phone_code"
                                            aria-label="<?= t('phone_code_placeholder') ?>">
                                        <option value=""><?= t('phone_code_placeholder') ?></option>
                                        <?php foreach ($countries as $c): ?>
                                        <option value="<?= $esc($c['phone_code']) ?>"
                                            <?= (($_POST['c_phone_code'] ?? '') === $c['phone_code']) ? 'selected' : '' ?>>
                                            <?= $esc($c['phone_code']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="input-wrap phone-number-wrap">
                                    <input type="tel" name="c_phone_number"
                                           value="<?= $esc($_POST['c_phone_number'] ?? '') ?>"
                                           placeholder="<?= t('phone_number_placeholder') ?>"
                                           maxlength="30">
                                </div>
                            </div>
                        </div>
                    </div>

                    <label class="checkbox-wrap" style="margin-top:14px;">
                        <input type="checkbox" name="c_is_primary" value="1"
                               <?= isset($_POST['c_is_primary']) ? 'checked' : '' ?>>
                        <?= t('contact_primary_label') ?>
                    </label>

                    <div style="margin-top:16px;">
                        <button type="submit" class="btn-primary"
                                style="width:auto;min-width:180px;height:44px;font-size:.9rem;">
                            <?= t('btn_add_contact') ?>
                        </button>
                    </div>
                </form>
            </div>

        </div><!-- /contacts-card -->

    </div><!-- /page-content -->

    <footer class="global-footer">
        &copy; <?= date('Y') ?> Local App &mdash; Development environment only
    </footer>

    <!-- Idle-timeout mirror -->
    <script>
    (function () {
        const TIMEOUT_MS = <?= IDLE_TIMEOUT * 1000 ?>;
        const WARN_MS    = TIMEOUT_MS - 300000;
        let last = Date.now(), warned = false;
        ['mousemove','keydown','click','scroll','touchstart'].forEach(ev =>
            document.addEventListener(ev, () => { last = Date.now(); warned = false; }, { passive: true })
        );
        setInterval(() => {
            const idle = Date.now() - last;
            if (idle >= TIMEOUT_MS) {
                window.location.href = '/apple-login/index.php?reason=timeout';
            } else if (idle >= WARN_MS && !warned) {
                warned = true;
                if (confirm('Su sesión está por expirar. ¿Desea continuar?')) {
                    last = Date.now(); warned = false;
                }
            }
        }, 10000);
    })();
    </script>

</body>
</html>

