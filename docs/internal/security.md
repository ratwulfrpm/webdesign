# Security

## OWASP Controls

### 1. SQL Injection
- Uso de prepared statements
- Nunca concatenar input directo en queries

### 2. XSS
- Escapar output en vistas (`htmlspecialchars`)
- Sanitizar inputs

### 3. CSRF
- Tokens en formularios críticos
- Validación server-side

### 4. IDOR
- Validar acceso por RBAC + TenantScope
- Nunca confiar en IDs del frontend

---

## Authentication & Passwords

### Password Policy
- Min 12 caracteres
- Mayúscula, minúscula, número
- Hash seguro (bcrypt/argon)

### Reset Password
- Genera contraseña temporal (36 chars alfanum)
- Expira en 24h
- `must_change_password = true`

### Forced Password Change
- Bloquea dashboard hasta cambiar password
- Regenera sesión al completar

---

## Session & Cookies

- `HttpOnly = true`
- `SameSite = Lax/Strict`
- `Secure = true` en HTTPS
- `session_regenerate_id(true)` en login y cambio de password
- `Cache-Control: no-store` en vistas sensibles

### Isolation Rules
- Admin/Owner NO usan business unit activo
- Support requiere BU activo
- User-link no usa sesión
- Links públicos NO heredan sesión admin

---

## Logging & Secrets

- Nunca loggear:
  - passwords
  - tokens
  - session IDs

### APP_ENV
- `dev`: logging ampliado (controlado)
- `prod`: sin datos sensibles

---

## File Security

- `storage-file.php` valida acceso
- Usa `realpath()` para evitar path traversal
- Solo servir archivos permitidos

---

## API Security

- Validación RBAC en cada endpoint
- Validación de payload
- Respuestas estándar

---

## Payment Security (Future)

- Nunca procesar tarjetas directamente
- Validar webhooks
- Validar montos server-side