# Apple-Login

> 🇪🇸 [Español](#-documentación-en-español) · 🇺🇸 [English](#-english-documentation)

---

# 🇪🇸 Documentación en Español

Sistema de autenticación local con diseño inspirado en Apple, construido con PHP, MySQL y CSS puro. Pensado únicamente para entornos de desarrollo con MAMP.

---

## Tabla de contenidos

1. [Requisitos](#1-requisitos)
2. [Estructura del proyecto](#2-estructura-del-proyecto)
3. [Instalación y puesta en marcha](#3-instalación-y-puesta-en-marcha)
4. [Configuración de la base de datos](#4-configuración-de-la-base-de-datos)
5. [Credenciales de prueba](#5-credenciales-de-prueba)
6. [Flujo de la aplicación](#6-flujo-de-la-aplicación)
7. [Referencia de archivos](#7-referencia-de-archivos)
   - [index.php](#indexphp)
   - [dashboard.php](#dashboardphp)
   - [logout.php](#logoutphp)
   - [config/db.php](#configdbphp)
   - [includes/auth.php](#includesauthphp)
   - [includes/csrf.php](#includescsrfphp)
   - [css/style.css](#cssstylecss)
   - [setup/create_db.sql](#setupcreate_dbsql)
   - [setup/generate_hash.php](#setupgenerate_hashphp)
8. [Seguridad implementada](#8-seguridad-implementada)
9. [Diseño responsive](#9-diseño-responsive)
10. [Pasar a producción](#10-pasar-a-producción)

---

## 1. Requisitos

| Herramienta | Versión mínima |
|-------------|---------------|
| PHP         | 8.1           |
| MySQL       | 5.7 / MariaDB 10.4 |
| MAMP        | 6.x (Windows/macOS) |
| Navegador   | Cualquier moderno (Chrome 90+, Firefox 88+, Safari 14+) |

No se requieren librerías externas ni gestor de dependencias (sin Composer, sin npm).

---

## 2. Estructura del proyecto

```
apple-login/
├── index.php              # Página de login (pública)
├── dashboard.php          # Área protegida (requiere sesión)
├── logout.php             # Cierre de sesión (acepta solo POST)
├── README.md              # Este archivo
│
├── config/
│   └── db.php             # Constantes de conexión + singleton PDO
│
├── css/
│   └── style.css          # Estilos Apple-inspired + diseño responsive
│
├── includes/
│   ├── auth.php           # Funciones de autenticación y sesión
│   └── csrf.php           # Generación y validación de tokens CSRF
│
└── setup/
    ├── create_db.sql      # Script SQL para crear BD y usuario demo
    └── generate_hash.php  # Utilidad para generar hashes bcrypt
```

---

## 3. Instalación y puesta en marcha

### Paso 1 — Copiar el proyecto

Coloca la carpeta `apple-login/` dentro del directorio raíz de MAMP:

```
C:\MAMP\htdocs\apple-login\
```

### Paso 2 — Iniciar MAMP

Arranca los servidores de **Apache** y **MySQL** desde el panel de MAMP.  
Por defecto:
- Apache: `http://localhost` (puerto 80)
- MySQL: `localhost:3306`

### Paso 3 — Crear la base de datos

Abre **phpMyAdmin** (`http://localhost/phpmyadmin`) y ejecuta el script SQL:

```
Pestaña SQL → pegar contenido de setup/create_db.sql → Ejecutar
```

O bien desde la terminal de MAMP:

```bash
mysql -u root -proot < C:\MAMP\htdocs\apple-login\setup\create_db.sql
```

### Paso 4 — Abrir la aplicación

```
http://localhost/apple-login/
```

---

## 4. Configuración de la base de datos

Edita `config/db.php` si tus credenciales de MAMP difieren de las predeterminadas:

```php
define('DB_HOST',    'localhost');
define('DB_PORT',    3306);        // Cambia si modificaste el puerto en MAMP
define('DB_NAME',    'apple_login');
define('DB_USER',    'root');
define('DB_PASS',    'root');
define('DB_CHARSET', 'utf8mb4');
```

La conexión utiliza un **singleton PDO** con `ERRMODE_EXCEPTION` y prepared statements reales (`ATTR_EMULATE_PREPARES => false`).

---

## 5. Credenciales de prueba

| Campo    | Valor       |
|----------|-------------|
| Email    | `demo@local` |
| Usuario  | `demo`       |
| Contraseña | `Demo123!` |

El campo de login acepta tanto el **email** como el **nombre de usuario**.

Para generar un hash para una contraseña nueva, visita:

```
http://localhost/apple-login/setup/generate_hash.php
```

---

## 6. Flujo de la aplicación

```
[Usuario] ──GET──▶ index.php
                      │ ¿Sesión activa?
                      ├─ SÍ ──▶ dashboard.php
                      └─ NO ──▶ Muestra formulario de login
                                      │
                             [Envía POST]
                                      │
                              1. Valida CSRF token
                              2. Sanitiza inputs
                              3. attemptLogin()
                                 ├─ Consulta PDO (email OR username)
                                 ├─ password_verify()
                                 └─ password_needs_rehash() → rehash si aplica
                                      │
                               ┌──────┴──────┐
                             Éxito         Error
                               │             │
                          createSession()  Mensaje genérico
                               │           (sin revelar si el usuario existe)
                          dashboard.php
                               │
                        [Botón "Sign out"]
                               │
                          POST logout.php
                               │
                          csrfValidate()
                          destroySession()
                               │
                           index.php
```

---

## 7. Referencia de archivos

### index.php

Página pública de inicio de sesión.

- Redirige a `dashboard.php` si ya hay sesión activa.
- Valida el token CSRF antes de procesar el formulario.
- Muestra un **mensaje de error genérico** tanto si el usuario no existe como si la contraseña es incorrecta (evita enumeración de usuarios).
- Incluye botón para mostrar/ocultar contraseña (JS inline sin dependencias).
- Previene doble envío deshabilitando el botón tras el submit.

**Cabeceras de seguridad enviadas:**
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`

---

### dashboard.php

Área protegida. Requiere sesión válida (`requireAuth()`).

- Muestra el **avatar** con la inicial del usuario.
- Lista metadatos de sesión: estado, hora de login, ID de usuario.
- Incluye formulario de logout con token CSRF.
- Envía cabecera `Cache-Control: no-store` para evitar que el navegador cachee la página autenticada.

---

### logout.php

Cierra la sesión de forma segura.

- **Solo acepta peticiones POST** con token CSRF válido para prevenir logout CSRF (ataques GET).
- Llama a `destroySession()` que:
  - Vacía `$_SESSION`.
  - Expira la cookie de sesión.
  - Destruye la sesión en el servidor.
- Redirige a `index.php`.

---

### config/db.php

Centraliza la configuración de MySQL y expone la función `getDB()`.

| Función | Descripción |
|---------|-------------|
| `getDB(): PDO` | Devuelve la instancia PDO (patrón singleton). En caso de fallo, registra el error en el log del servidor y muestra un mensaje genérico. |

---

### includes/auth.php

Contiene toda la lógica de autenticación y manejo de sesión.

| Función | Descripción |
|---------|-------------|
| `attemptLogin(string $identifier, string $password): array\|false` | Busca al usuario por email o username. Verifica la contraseña con `password_verify()`. Aplica rehash automático si el coste de bcrypt cambió. Usa un hash dummy para mantener tiempo constante cuando el usuario no existe. |
| `createSession(array $user): void` | Regenera el ID de sesión (`session_regenerate_id(true)`) y almacena `user_id`, `username` y `logged_in`. |
| `isLoggedIn(): bool` | Comprueba que `$_SESSION['logged_in']` y `$_SESSION['user_id']` existan y no estén vacíos. |
| `requireAuth(): void` | Redirige a `index.php` si no hay sesión activa. Usar al inicio de páginas protegidas. |
| `destroySession(): void` | Vacía la sesión, expira la cookie y llama a `session_destroy()`. |

---

### includes/csrf.php

Tokens CSRF con rotación por solicitud.

| Función | Descripción |
|---------|-------------|
| `csrfToken(): string` | Genera y almacena un token de 64 caracteres hex en `$_SESSION['csrf_token']`. Reutiliza el token si ya existe. |
| `csrfField(): string` | Devuelve el HTML de un `<input type="hidden">` listo para insertar en formularios. |
| `csrfValidate(): void` | Compara el token enviado con el almacenado usando `hash_equals()` (resistente a timing attacks). Responde 403 y detiene la ejecución si no coincide. Rota el token tras cada validación exitosa. |

---

### css/style.css

Hoja de estilos completa sin dependencias externas.

**Variables CSS principales (`:root`):**

| Variable | Valor por defecto | Uso |
|----------|-------------------|-----|
| `--color-bg` | `#f5f5f7` | Fondo de la página |
| `--color-card` | `#ffffff` | Fondo de la tarjeta |
| `--color-accent` | `#0071e3` | Botón primario, enlaces |
| `--color-border-focus` | `#0071e3` | Borde al enfocar inputs |
| `--color-error-border` | `#ff3b30` | Alertas de error |
| `--radius-card` | `20px` | Border-radius de la tarjeta |

**Componentes:**

- `.brand` — Logo y nombre de la app
- `.card` — Contenedor principal (login / dashboard)
- `.input-wrap` — Etiqueta + input + botón toggle password
- `.btn-primary` — Botón de submit con hover/active
- `.btn-secondary` — Botón ghost (Sign out)
- `.alert-error / .alert-success` — Mensajes de feedback
- `.meta-list` — Lista de datos de sesión en dashboard
- `.global-footer` — Pie de página

**Breakpoints responsive:**

| Breakpoint | Cambios |
|-----------|---------|
| ≤ 680px | Padding reducido, contenido alineado arriba |
| ≤ 480px | Tarjeta a ancho completo, inputs al 100%, `font-size: max(16px, 1rem)` para evitar zoom en iOS |
| ≤ 360px | Padding mínimo para pantallas muy pequeñas |

---

### setup/create_db.sql

Script SQL idempotente (`IF NOT EXISTS`, `ON DUPLICATE KEY UPDATE`).

- Crea la base de datos `apple_login` en `utf8mb4`.
- Crea la tabla `users` con índices únicos en `username` y `email`.
- Inserta el usuario demo con hash bcrypt coste 12.

**Esquema de la tabla `users`:**

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Clave primaria |
| `username` | VARCHAR(60) UNIQUE | Nombre de usuario |
| `email` | VARCHAR(254) UNIQUE | Correo electrónico |
| `password_hash` | VARCHAR(255) | Hash bcrypt |
| `created_at` | DATETIME | Fecha de registro |
| `updated_at` | DATETIME | Última modificación |

---

### setup/generate_hash.php

Utilidad de línea de comandos o navegador para generar hashes bcrypt (coste 12). Solo debe usarse en desarrollo; no debe estar accesible en producción.

---

## 8. Seguridad implementada

| Mecanismo | Detalle |
|-----------|---------|
| **Hashing de contraseñas** | `password_hash()` con `PASSWORD_BCRYPT` y coste 12 |
| **Rehash automático** | `password_needs_rehash()` actualiza el hash si cambia el coste |
| **Timing constante** | Hash dummy al fallar lookup para evitar timing attacks |
| **CSRF** | Token de 64 hex chars, validado con `hash_equals()`, rotado tras cada uso |
| **Sesión segura** | `session_regenerate_id(true)` en login; cookie `httponly`, `samesite=Lax` |
| **Prepared statements** | Todos los queries usan PDO con `ATTR_EMULATE_PREPARES => false` |
| **XSS** | Todo output de usuario pasa por `htmlspecialchars()` con `ENT_QUOTES` |
| **Clickjacking** | `X-Frame-Options: DENY` en todas las páginas |
| **Sniffing** | `X-Content-Type-Options: nosniff` |
| **Cache de páginas auth** | `Cache-Control: no-store` en dashboard |
| **Logout CSRF** | `logout.php` solo acepta POST con token CSRF válido |

---

## 9. Diseño responsive

La interfaz usa **CSS puro** con un stack de fuentes del sistema (`-apple-system, BlinkMacSystemFont`, etc.) para lograr el aspecto de SF Pro sin descargar fuentes externas.

Se aplica tipografía fluida:

```css
html {
    font-size: clamp(14px, 1vw + 12px, 16px);
}
```

En móvil, los inputs usan `font-size: max(16px, 1rem)` para **prevenir el zoom automático de iOS** al enfocar un campo de texto.

---

## 10. Pasar a producción

> **Advertencia:** Este proyecto está pensado para desarrollo local. Antes de desplegarlo en un servidor real, realiza los siguientes cambios obligatorios:

1. **Credenciales de base de datos** — Reemplaza `root/root` por un usuario con privilegios mínimos y contraseña segura en `config/db.php`.
2. **HTTPS** — Cambia `'secure' => false` a `'secure' => true` en la configuración de cookie de sesión en todos los archivos PHP.
3. **`generate_hash.php`** — Eliminar o proteger con `.htaccess`; no debe ser accesible públicamente.
4. **`create_db.sql`** — Eliminar o mover fuera del `document root`.
5. **Cabeceras adicionales** — Añadir `Strict-Transport-Security`, `Content-Security-Policy` y `Permissions-Policy`.
6. **Logs de errores** — Asegurarse de que `display_errors = Off` en `php.ini` y que los errores solo se escriban en el log del servidor.
7. **Rate limiting** — Implementar limitación de intentos de login para prevenir ataques de fuerza bruta.

---

*Documentación generada el 24 de febrero de 2026.*

---

---

# 🇺🇸 English Documentation

Local authentication system with an Apple-inspired design, built with PHP, MySQL, and pure CSS. Intended for development environments with MAMP only.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Project Structure](#2-project-structure)
3. [Installation & Setup](#3-installation--setup)
4. [Database Configuration](#4-database-configuration)
5. [Demo Credentials](#5-demo-credentials)
6. [Application Flow](#6-application-flow)
7. [File Reference](#7-file-reference)
   - [index.php](#indexphp-1)
   - [dashboard.php](#dashboardphp-1)
   - [logout.php](#logoutphp-1)
   - [config/db.php](#configdbphp-1)
   - [includes/auth.php](#includesauthphp-1)
   - [includes/csrf.php](#includescsrfphp-1)
   - [css/style.css](#cssstylecss-1)
   - [setup/create_db.sql](#setupcreate_dbsql-1)
   - [setup/generate_hash.php](#setupgenerate_hashphp-1)
8. [Security Features](#8-security-features)
9. [Responsive Design](#9-responsive-design)
10. [Going to Production](#10-going-to-production)

---

## 1. Requirements

| Tool    | Minimum version |
|---------|----------------|
| PHP     | 8.1            |
| MySQL   | 5.7 / MariaDB 10.4 |
| MAMP    | 6.x (Windows/macOS) |
| Browser | Any modern browser (Chrome 90+, Firefox 88+, Safari 14+) |

No external libraries or dependency managers are required (no Composer, no npm).

---

## 2. Project Structure

```
apple-login/
├── index.php              # Login page (public)
├── dashboard.php          # Protected area (requires session)
├── logout.php             # Sign-out handler (POST only)
├── README.md              # This file
│
├── config/
│   └── db.php             # Connection constants + PDO singleton
│
├── css/
│   └── style.css          # Apple-inspired styles + responsive design
│
├── includes/
│   ├── auth.php           # Authentication and session functions
│   └── csrf.php           # CSRF token generation and validation
│
└── setup/
    ├── create_db.sql      # SQL script to create the DB and demo user
    └── generate_hash.php  # Utility to generate bcrypt hashes
```

---

## 3. Installation & Setup

### Step 1 — Copy the project

Place the `apple-login/` folder inside the MAMP web root:

```
C:\MAMP\htdocs\apple-login\
```

### Step 2 — Start MAMP

Launch the **Apache** and **MySQL** servers from the MAMP control panel.  
Defaults:
- Apache: `http://localhost` (port 80)
- MySQL: `localhost:3306`

### Step 3 — Create the database

Open **phpMyAdmin** (`http://localhost/phpmyadmin`) and run the SQL script:

```
SQL tab → paste contents of setup/create_db.sql → Execute
```

Or from the MAMP terminal:

```bash
mysql -u root -proot < C:\MAMP\htdocs\apple-login\setup\create_db.sql
```

### Step 4 — Open the application

```
http://localhost/apple-login/
```

---

## 4. Database Configuration

Edit `config/db.php` if your MAMP credentials differ from the defaults:

```php
define('DB_HOST',    'localhost');
define('DB_PORT',    3306);        // Change if you modified the port in MAMP
define('DB_NAME',    'apple_login');
define('DB_USER',    'root');
define('DB_PASS',    'root');
define('DB_CHARSET', 'utf8mb4');
```

The connection uses a **PDO singleton** with `ERRMODE_EXCEPTION` and real prepared statements (`ATTR_EMULATE_PREPARES => false`).

---

## 5. Demo Credentials

| Field    | Value        |
|----------|--------------|
| Email    | `demo@local` |
| Username | `demo`       |
| Password | `Demo123!`   |

The login field accepts either the **email** or the **username**.

To generate a hash for a new password, visit:

```
http://localhost/apple-login/setup/generate_hash.php
```

---

## 6. Application Flow

```
[User] ──GET──▶ index.php
                    │ Active session?
                    ├─ YES ──▶ dashboard.php
                    └─ NO  ──▶ Show login form
                                    │
                           [Submits POST]
                                    │
                            1. Validate CSRF token
                            2. Sanitize inputs
                            3. attemptLogin()
                               ├─ PDO query (email OR username)
                               ├─ password_verify()
                               └─ password_needs_rehash() → rehash if needed
                                    │
                             ┌──────┴──────┐
                           Success       Error
                             │             │
                        createSession()  Generic message
                             │           (does not reveal whether user exists)
                        dashboard.php
                             │
                      [Click "Sign out"]
                             │
                        POST logout.php
                             │
                        csrfValidate()
                        destroySession()
                             │
                         index.php
```

---

## 7. File Reference

### index.php

Public login page.

- Redirects to `dashboard.php` if a session is already active.
- Validates the CSRF token before processing the form.
- Displays a **generic error message** whether the user doesn't exist or the password is wrong (prevents user enumeration).
- Includes a show/hide password button (inline JS, no dependencies).
- Prevents double-submit by disabling the button after form submission.

**Security headers sent:**
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`

---

### dashboard.php

Protected area. Requires a valid session (`requireAuth()`).

- Displays the **avatar** with the user's initial.
- Lists session metadata: status, login time, user ID.
- Includes a logout form with a CSRF token.
- Sends `Cache-Control: no-store` to prevent the browser from caching the authenticated page.

---

### logout.php

Securely destroys the session.

- **Only accepts POST requests** with a valid CSRF token to prevent logout CSRF (GET-based attacks).
- Calls `destroySession()` which:
  - Clears `$_SESSION`.
  - Expires the session cookie.
  - Destroys the server-side session.
- Redirects to `index.php`.

---

### config/db.php

Centralizes MySQL configuration and exposes the `getDB()` function.

| Function | Description |
|----------|-------------|
| `getDB(): PDO` | Returns the PDO instance (singleton pattern). On failure, logs the error to the server log and displays a generic message. |

---

### includes/auth.php

Contains all authentication and session management logic.

| Function | Description |
|----------|-------------|
| `attemptLogin(string $identifier, string $password): array\|false` | Looks up the user by email or username. Verifies the password with `password_verify()`. Automatically rehashes if the bcrypt cost changed. Uses a dummy hash to maintain constant timing when the user does not exist. |
| `createSession(array $user): void` | Regenerates the session ID (`session_regenerate_id(true)`) and stores `user_id`, `username`, and `logged_in`. |
| `isLoggedIn(): bool` | Checks that `$_SESSION['logged_in']` and `$_SESSION['user_id']` exist and are not empty. |
| `requireAuth(): void` | Redirects to `index.php` if no active session exists. Use at the top of protected pages. |
| `destroySession(): void` | Clears the session, expires the cookie, and calls `session_destroy()`. |

---

### includes/csrf.php

CSRF tokens with per-request rotation.

| Function | Description |
|----------|-------------|
| `csrfToken(): string` | Generates and stores a 64-character hex token in `$_SESSION['csrf_token']`. Reuses the token if it already exists. |
| `csrfField(): string` | Returns the HTML for a `<input type="hidden">` field ready to embed in forms. |
| `csrfValidate(): void` | Compares the submitted token against the stored one using `hash_equals()` (timing-attack resistant). Returns 403 and halts execution if they don't match. Rotates the token after each successful validation. |

---

### css/style.css

Complete stylesheet with no external dependencies.

**Main CSS variables (`:root`):**

| Variable | Default value | Usage |
|----------|--------------|-------|
| `--color-bg` | `#f5f5f7` | Page background |
| `--color-card` | `#ffffff` | Card background |
| `--color-accent` | `#0071e3` | Primary button, links |
| `--color-border-focus` | `#0071e3` | Input focus border |
| `--color-error-border` | `#ff3b30` | Error alerts |
| `--radius-card` | `20px` | Card border-radius |

**Components:**

- `.brand` — App logo and name
- `.card` — Main container (login / dashboard)
- `.input-wrap` — Label + input + password toggle button
- `.btn-primary` — Submit button with hover/active states
- `.btn-secondary` — Ghost button (Sign out)
- `.alert-error / .alert-success` — Feedback messages
- `.meta-list` — Session data list in dashboard
- `.global-footer` — Page footer

**Responsive breakpoints:**

| Breakpoint | Changes |
|-----------|---------|
| ≤ 680px | Reduced padding, content aligned to top |
| ≤ 480px | Card stretches full width, `font-size: max(16px, 1rem)` to prevent iOS auto-zoom |
| ≤ 360px | Minimum padding for very small screens |

---

### setup/create_db.sql

Idempotent SQL script (`IF NOT EXISTS`, `ON DUPLICATE KEY UPDATE`).

- Creates the `apple_login` database in `utf8mb4`.
- Creates the `users` table with unique indexes on `username` and `email`.
- Inserts the demo user with a bcrypt hash at cost 12.

**`users` table schema:**

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `username` | VARCHAR(60) UNIQUE | Username |
| `email` | VARCHAR(254) UNIQUE | Email address |
| `password_hash` | VARCHAR(255) | bcrypt hash |
| `created_at` | DATETIME | Registration date |
| `updated_at` | DATETIME | Last modification |

---

### setup/generate_hash.php

Command-line or browser utility to generate bcrypt hashes (cost 12). For development use only; must not be publicly accessible in production.

---

## 8. Security Features

| Mechanism | Detail |
|-----------|--------|
| **Password hashing** | `password_hash()` with `PASSWORD_BCRYPT` at cost 12 |
| **Automatic rehash** | `password_needs_rehash()` updates the hash if the cost changes |
| **Constant timing** | Dummy hash on failed lookup to prevent timing attacks |
| **CSRF** | 64-char hex token, validated with `hash_equals()`, rotated after each use |
| **Secure session** | `session_regenerate_id(true)` on login; `httponly`, `samesite=Lax` cookie |
| **Prepared statements** | All queries use PDO with `ATTR_EMULATE_PREPARES => false` |
| **XSS** | All user output goes through `htmlspecialchars()` with `ENT_QUOTES` |
| **Clickjacking** | `X-Frame-Options: DENY` on all pages |
| **MIME sniffing** | `X-Content-Type-Options: nosniff` |
| **Auth page cache** | `Cache-Control: no-store` on dashboard |
| **Logout CSRF** | `logout.php` only accepts POST with a valid CSRF token |

---

## 9. Responsive Design

The interface uses **pure CSS** with a system font stack (`-apple-system, BlinkMacSystemFont`, etc.) to achieve an SF Pro look without downloading external fonts.

Fluid typography is applied:

```css
html {
    font-size: clamp(14px, 1vw + 12px, 16px);
}
```

On mobile, inputs use `font-size: max(16px, 1rem)` to **prevent iOS auto-zoom** when focusing a text field.

---

## 10. Going to Production

> **Warning:** This project is intended for local development. Before deploying to a real server, make the following mandatory changes:

1. **Database credentials** — Replace `root/root` with a least-privilege user and a strong password in `config/db.php`.
2. **HTTPS** — Change `'secure' => false` to `'secure' => true` in the session cookie configuration in all PHP files.
3. **`generate_hash.php`** — Delete or protect with `.htaccess`; must not be publicly accessible.
4. **`create_db.sql`** — Delete or move outside the document root.
5. **Additional headers** — Add `Strict-Transport-Security`, `Content-Security-Policy`, and `Permissions-Policy`.
6. **Error logging** — Ensure `display_errors = Off` in `php.ini` and that errors are written only to the server log.
7. **Rate limiting** — Implement login attempt throttling to prevent brute-force attacks.

---

*Documentation generated on February 24, 2026.*
