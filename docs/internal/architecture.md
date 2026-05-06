# Architecture

## Overview
Aplicación web en PHP con:
- RBAC (owner, admin, support, supplier, user-link)
- Multi-business units (tenant isolation)
- Router progresivo (web) + API REST (`/api/v1`)
- Storage externo (filesystem → futuro S3/R2)
- Sistema de cotizaciones con links tokenizados

## Layers
1. **Web (PHP views)**
   - `router.php` (front controller)
   - Vistas por rol (admin/owner/supplier/user-link)

2. **API (REST)**
   - `/api/v1/*`
   - Autenticación separada (tokens/sesión)
   - Formato estándar de respuesta

3. **Domain/Services**
   - `UserManagementService`
   - `TenantScope`
   - `RBAC`

4. **Data**
   - DB relacional
   - Tablas: users, business_units, products, quotes, invitations, payments

5. **Storage**
   - `/app_storage` (fuera del repo)
   - Servido por `storage-file.php`
   - Migrable a S3/R2

## Routing
- `.htaccess` redirige a `router.php`
- Excluye `/api/v1/*`
- Soporta rutas legacy `.php` y rutas limpias

## Multi-Tenant (Business Units)
- Owner: acceso global
- Admin: acceso a BUs asignadas
- Support: BU activa seleccionada
- Supplier: scope propio
- User-link: acceso por token

## Product Codes
- **Supplier Code**: visible solo admin/owner
- **Internal Code**: visible para cliente (user-link)
- Nunca exponer supplier code en frontend público

## Quotes (Cotizaciones)
- Generadas por admin/owner
- Link tokenizado con expiración
- Carrito dinámico
- Sin costo proveedor visible

## Payments (Future-ready)
- Diseño para múltiples providers:
  - Stripe
  - PayPal
  - BAC / Onvo / Tilopay
  - WeChat / Alipay