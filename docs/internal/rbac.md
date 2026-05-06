# RBAC (Role-Based Access Control)

## Roles

### Owner
- Acceso global
- Crear Business Units
- Crear Admin / Support / Supplier
- Cambiar roles
- Ver todos los datos

### Admin
- Acceso a BUs asignadas
- Gestionar usuarios dentro de scope
- NO puede cambiar roles
- NO puede crear Business Units

### Support
- Igual que admin pero:
- Solo BU activa
- No crea roles

### Supplier
- Gestiona sus productos
- Sube contratos
- No ve otros BUs

### User-Link
- No login
- Acceso por token
- Solo ve productos asignados

---

## Reglas Clave

- Owner > Admin (herencia)
- Admin NO ve BUs fuera de su scope
- Admin NO sabe que existen otras BUs
- Support usa BU activa
- Supplier scope limitado
- User-link completamente aislado

---

## Business Units

- Owner crea BUs
- Admin solo usa las asignadas
- Support selecciona BU activa

---

## Seguridad

- Backend valida TODO
- UI no define permisos