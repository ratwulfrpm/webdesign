---
description: Project memory and AI preferences — read this on every request
applyTo: '**'
---

# Project: apple-login (Supplier Portal)

## Repository
- GitHub: https://github.com/ratwulfrpm/webdesign.git
- Branch: `main`
- Local path: `C:\MAMP\htdocs\apple-login`
- **Rule: Always push to GitHub after every meaningful change. Never skip this.**

## Environment
- PHP 8.3.1 (Apache via MAMP — web execution)
- MySQL 5.7.24 (MAMP)
- MAMP CLI PHP does NOT have pdo_mysql — run migrations via browser only
- localhost base URL: `http://localhost/apple-login/`

## Platform Target
- **Web** (primary): PHP/HTML running on Apache. Responsive CSS (mobile-friendly breakpoints at 680px and 480px).
- **Mobile app** (planned): No native app exists yet. When the time comes, the architecture will need a REST/JSON API layer added to this PHP backend. The current session-based auth is not compatible with native mobile apps — a token-based (JWT or similar) layer will be required.

## Current Version
- Commit: `540ae2e` — feat: full supplier profile (legal info, addresses, contacts, countries catalog)

## Database: `apple_login`
### Table: `users` (key columns)
| Column | Type | Purpose |
|---|---|---|
| id, username, email, password_hash | — | Auth |
| is_active, role, failed_attempts, locked_until | — | Security |
| first_login, preferred_language | — | UX flow |
| full_name, company_name | VARCHAR(200) | General info |
| legal_rep_name, tax_id, legal_rep_id | — | Legal info |
| company_phone_code/number, legal_rep_phone_code/number | VARCHAR | Split phone fields |
| phone | VARCHAR(30) | Legacy unified phone |
| addr_street/city/state/zip/country_id | — | Office address |
| factory_street/city/state/zip/country_id | — | Factory address |
| created_at, updated_at | DATETIME | Audit |

### Table: `countries`
- 35 countries seeded, columns: id, code (ISO 3166-1), phone_code, name_es, name_en
- Referenced by users.addr_country_id and users.factory_country_id

### Table: `supplier_contacts`
- Columns: id, supplier_id (FK->users, CASCADE), name, role, email, phone_code, phone_number, is_primary, created_at

### Table: `password_requests`
- Columns: id, company_name, email, username, notes, status (pending/resolved), requested_at, resolved_at

## Authentication Constants (includes/auth.php)
- MAX_ATTEMPTS = 3
- LOCKOUT_SECS = 3600 (1 hour)
- IDLE_TIMEOUT = 1800 (30 minutes)
- Bcrypt cost = 12

## Users
| Username | Password | Role | first_login |
|---|---|---|---|
| admin | admin | admin | 0 |
| demo | Demo123! | supplier | 1 (pending profile) |

## File Map
```
apple-login/
  index.php               — Login page (ES/EN, lockout messages, role routing)
  dashboard.php           — Thin router (redirects by role/first_login)
  logout.php              — Session destroy + redirect
  forgot_password.php     — Password request form (company, email, user, notes)
  config/db.php           — PDO singleton (getDB())
  includes/
    auth.php              — attemptLogin(), createSession(), requireAuth(), destroySession()
    csrf.php              — csrfToken(), csrfField(), csrfValidate()
    lang.php              — initLang(), t(), currentLang()
  lang/
    es.php                — All Spanish strings
    en.php                — All English strings
    .htaccess             — Deny direct access
  css/style.css           — Full UI stylesheet (Apple-inspired)
  admin/index.php         — Admin panel (user mgmt, activate/deactivate/unlock, password requests)
  supplier/
    profile.php           — Full supplier profile (4 sections + contacts CRUD)
    summary.php           — Supplier dashboard (shows all profile data)
  setup/
    create_db.sql         — Full schema (fresh install)
    migrate_db.sql        — Migration v1 (is_active, role, etc.)
    run_migration.php     — Migration v1 runner (browser-only)
    run_migration2.php    — Migration v2 runner (new columns, countries, contacts)
    generate_hash.php     — Dev utility to generate bcrypt hashes
```

## Routing Logic
- Login → `admin` role → `/admin/index.php`
- Login → `supplier` + `first_login=1` → `/supplier/profile.php`
- Login → `supplier` + `first_login=0` → `/supplier/summary.php`
- Profile "Regresar" + `first_login=1` → logout
- Profile "Regresar" + `first_login=0` → `/supplier/summary.php`

## Security Patterns
- CSRF: session-based single-use token, rotated after each validated POST
- Session: httponly, samesite=Lax, session_regenerate_id(true) on login
- Auth guard: `requireAuth()` at top of every protected page
- DB revalidation: `requireAuth()` re-queries `is_active` on every request
- XSS: all output via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
- PDO: `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false` (MySQL 5.7 does NOT allow duplicate named params like `:id` used twice — use `:email` and `:username` separately)

## Known MySQL 5.7 Quirks (do not repeat these mistakes)
1. `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` does NOT work — check INFORMATION_SCHEMA first
2. PDO with `EMULATE_PREPARES = false` rejects duplicate named parameters in the same query
3. MAMP CLI PHP lacks `pdo_mysql` — always run migrations via browser (Apache PHP)

## i18n
- Supported: `es` (default), `en`
- Switch: `?set_lang=xx` (PRG pattern)
- Persistence: `$_SESSION['lang']` + `users.preferred_language` column

## Communication Preferences
- Always respond in English
- Code comments can be in English or Spanish as appropriate
- User-facing text in the app: Spanish (Costa Rica market)
- Never use emojis in responses, comments, or commit messages
- Keep responses concise and direct
- Always syntax-check PHP files after editing
- Test locally before any deployment
- Ask confirmation before deploying to Azure

## Azure Deployment Rules
- Test all fixes locally first — confirm working before committing
- Ask user confirmation before deploying
- On every deployment: update app version as minor (x.x.x pattern)
- Show deployment summary: Deployment ID, Status, files changed, notes, version

## Lessons Learned (do not repeat)
- **2026-Mar**: Duplicate named PDO param `:id` used twice in WHERE clause → `SQLSTATE[HY093]`. Fixed by using `:email` and `:username` as separate params.
- **2026-Mar**: Migration script ran via CLI where pdo_mysql is absent → always use browser for migrations on MAMP.
- **2026-Mar**: `ADD COLUMN IF NOT EXISTS` not supported in MySQL 5.7 → check INFORMATION_SCHEMA before each ALTER.