# Business Rules

## Products

- Supplier sube productos
- 6 fotos posibles
- Campos:
  - nombre
  - descripción
  - supplier code
  - internal code

## Codes

- Supplier code: privado
- Internal code: visible al cliente

---

## Quotes (Cotizaciones)

- Admin/Owner crean cotización
- Seleccionan productos
- Definen:
  - FOB o CIF
  - % ganancia
  - transporte
  - impuestos

- Genera link único
- Expira (horas o días)

---

## User-Link

- No tiene cuenta
- Accede por token
- Ve:
  - productos
  - precios calculados
  - carrito dinámico

---

## Contracts (Supplier)

- Subida obligatoria
- Histórico inmutable
- Puede agregar nuevos
- No puede eliminar anteriores

---

## Invitations

- Token seguro
- Expira
- Single-use
- Usuario crea password

---

## User Management

Admin:
- desbloquear
- desactivar
- reset password
- invitar
- asignar BUs

Owner:
- todo lo anterior +
- cambiar roles
- crear admins

---

## Restrictions

- Supplier nunca será support
- User-link no tiene perfil