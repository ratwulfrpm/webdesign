# Internal UI Branding System
> **INTERNAL DOCUMENT — Not for production runtime loading.**  
> This file is a living reference for developers. Do not `require` or `include` it from PHP code.  
> Last updated: May 2026

---

## 1. Principios Visuales / Visual Principles

Inspiración: **Apple.com** — limpio, minimalista, mucho espacio respirable.

| Principio | Descripción |
|---|---|
| Limpieza | Sin ruido visual, sin bordes innecesarios |
| Espacio | Paddings amplios en cards y formularios |
| Tipografía | System font stack (SF Pro feel) — `-apple-system, BlinkMacSystemFont, "Segoe UI"` |
| Sombras | Sutiles (`box-shadow: 0 4px 40px rgba(0,0,0,0.08)`) |
| Bordes | Suaves (`border-radius: 20px` en cards, `12px` en inputs/botones) |
| Colores | Alto contraste sin saturación agresiva — paleta por rol |
| Responsive | Campos apilados en móvil, columnas en desktop |

---

## 2. Paleta por Rol / Role Color Palette

| Rol | Acento principal | Hover | Suave | Uso |
|---|---|---|---|---|
| `owner` | `#0071e3` (azul Apple) | `#0077ed` | `#f0f7ff` | Panel de propietario |
| `admin` | `#1f1f23` (gris profundo) | `#2e2f35` | `#f2f3f5` | Panel de administración |
| `support` | `#2e7fbf` (azul teal) | `#266fa8` | `#edf5fb` | Panel de soporte |
| `supplier` | `#c14a52` (rojo suave) | `#ad3f47` | `#fff2f3` | Panel de proveedor |
| `user/link` | `#2f8f5b` (verde suave) | `#2a7d50` | `#eef9f3` | Vista pública de cotización |

**Regla:** Todos los roles usan las mismas variables CSS (`--color-accent`, `--color-accent-hover`, etc.) sobrescritas por `body.role-{role}`.

### Excepción Admin/Owner — Color
> No es una excepción real: ambos son roles de gestión interna. `admin` usa gris oscuro para diferenciarse visualmente del `owner` (azul). El rol `support` fue definido explícitamente como variante teal (azul-grisáceo) para distinguirse de ambos sin confusión con `owner`.

---

## 3. Jerarquía de Botones / Button Hierarchy

### Nivel 1 — Acción primaria
- Clase: `.btn-primary`
- Altura: `50px` (desktop), `52px` (móvil ≤480px)
- Fondo: `var(--color-accent)` por rol
- Usar para: Guardar, Generar link, Confirmar
- **Consistencia:** Todos los roles usan la misma altura. No cambiar por rol.

### Nivel 2 — Acción secundaria
- Clase: `.btn-secondary`
- Altura: auto (`padding: 10px 20px`)
- Fondo: transparente, borde `1.5px` `var(--color-border)`
- Usar para: Cancelar, Regresar, Copiar link

### Nivel 3 — Acción en tabla
- Clase: `.btn-tbl` + modificador (`.btn-danger`, `.btn-success`, `.btn-accent`, `.btn-secondary`)
- Altura: auto (`padding: 5px 12px`)
- Usar para: Activar, Desactivar, Revocar, Eliminar, Ver detalle

### Nivel 4 — Acción pequeña
- Clase: `.btn-sm`
- Usar para: logout, switch org, acciones en top-bar

---

## 4. Jerarquía de Formularios / Form Hierarchy

### Nivel 1 — Card contenedor
```
.profile-form-card   max-width: 760px (narrow layout)
body.wide-layout .profile-form-card   max-width: none (full ancho)
padding: 36px 40px 32px
```

### Nivel 2 — Sección de formulario
```
.form-section          margin-bottom: 32px
.form-section-title    borde inferior 2px, font-weight: 700
```

### Nivel 3 — Fila de campos
```
.form-row              grid 2 columnas
.form-row-3            grid 3 columnas (2fr 1fr 1fr)
```

### Nivel 4 — Campo individual
```
.input-wrap            posición relativa
.input-wrap label / .form-label    etiqueta superior
.input-wrap input / .form-input    altura: 48px
.input-wrap select                 altura: 48px
.form-textarea                     min-height: 80px, resize: vertical
```

### Nivel 5 — Acciones
```
.form-actions          flex, padding-top: 24px, border-top
.btn-primary           min-width: 180px, height: 48px
```

---

## 5. Tamaños de Campos por Tipo de Dato / Field Sizing by Data Type

| Tipo de dato | Clase | max-width | min-width | Ejemplos |
|---|---|---|---|---|
| Corto | `.field-short` | 120px | 80px | edad, ZIP, porcentaje, cantidad, duración |
| Medio | `.field-medium` | 320px | 160px | teléfono, precio, código de producto, email, username |
| Largo | `.field-long` | 520px | 220px | nombre completo, nombre de producto, empresa, rep. legal |
| Extra largo | `.field-xl` | 100% | 260px | dirección, descripción, ficha técnica, condiciones, notas |

**Regla:** Los campos siempre colapsan a 100% en pantallas ≤640px independientemente de su clase de ancho.

### Casos especiales
- **Teléfono:** usar `.phone-pair` (selector de código + campo número). `phone-code-wrap: 210px`, `phone-number-wrap: flex:1`
- **Porcentaje / dinero:** par de input + label de unidad (ej: `%`, `$`) en fila horizontal
- **Descripción / Ficha técnica:** `.form-textarea` con `min-height: 120px` mínimo recomendado
- **País / Dropdown largo:** `width: 100%` o `.field-long` según contexto

---

## 6. Reglas Responsive / Responsive Rules

| Breakpoint | Comportamiento |
|---|---|
| > 1024px | Full ancho, `max-width: none` en `wide-layout` |
| 768–1024px | `padding: 0 20px`, ligero ajuste de fonts |
| 640–768px | Formularios a 1 columna, tabs más pequeños |
| 480–640px | top-bar apilado, tabs flex-wrap |
| ≤ 480px | top-bar en columna, botones full-width, inputs font-size `max(16px,1rem)` (evita iOS zoom) |
| ≤ 360px | Tabs mínimos, tablas ultra-compactas |

**Regla móvil:** Todo input debe tener `font-size ≥ 16px` para prevenir zoom automático en iOS.

---

## 7. Reglas de Tablas / Table Rules

```css
.data-table          width: 100%, border-collapse: collapse
.table-wrap          overflow-x: auto (scroll horizontal solo cuando necesario)
th                   uppercase, 0.775rem, font-weight 600, color: muted
td                   padding: 11px 14px, border-top: 1px
tbody tr:hover       background: #fafafa
```

- **Columnas mínimas necesarias.** No agregar columnas decorativas.
- **`min-width`** en tablas con muchas columnas para evitar aplastamiento (ej: `min-width: 820px` en la tabla de cotizaciones).
- **Columna Actions:** en móvil ≤640px, colapsa a `flex-direction: column` con `.actions-cell`.

---

## 8. Reglas de Dropdowns / Dropdown Rules

```css
.input-wrap select   height: 48px, background-image: SVG chevron, padding-right: 14px
.input-select        idem, para selects fuera de .input-wrap
.input-sm            height: 34px (uso en tablas)
.role-select         height: 32px (uso en filas de tabla de usuarios)
```

- Usar SVG chevron interno (data URI), **no** usar el chevron nativo del browser para consistencia cross-platform.
- Foco: `border-color: var(--color-border-focus)`, `box-shadow: 0 0 0 3px rgba(accent, 0.15)`.

---

## 9. Reglas de Scroll / Scroll Rules

- **Scroll horizontal:** Solo en `.table-wrap` / `.asgn-table-wrap`. Nunca en el layout principal.
- **Scroll vertical:** Natural del browser. No usar `overflow-y: hidden` en contenedores principales.
- **Wide layout:** `body.wide-layout` se expande a todo el ancho sin scroll horizontal.

---

## 10. Estados Active / Hover / Focus / Rules

| Estado | Estilo |
|---|---|
| `.tab-item:hover` | `background: var(--color-accent-soft)`, borde de acento |
| `.tab-item--active` | `background: var(--color-accent)`, texto blanco, `font-weight: 700` |
| `input:focus`, `select:focus` | `border-color: var(--color-border-focus)`, `box-shadow: 0 0 0 3px rgba(accent, 0.15)` |
| `input:hover` | `border-color: #b0b0b8` |
| `btn-primary:active` | `transform: scale(0.985)` |
| `:focus-visible` | `outline: 2px solid var(--color-accent)` (accesibilidad AA) |

---

## 11. Alturas de Menú y Anti-Jump / Menu Heights & Anti-Jump

- **Tabs:** altura fija mediante `padding: 10px 22px`. Sin cambios por rol.
- **Top bar:** `height` implícita por contenido. Siempre en una línea (flex, `align-items: center`).
- **Área de contenido:** `.page-content { min-height: 60vh }` — evita que la pantalla salte al cambiar de tab.
- **Principio:** Si el contenido de un tab es corto, el área sigue siendo alta. No colapsarla.

---

## 12. Reglas para Nuevas Funcionalidades / Rules for New Features

1. **Siempre usar** las variables CSS `--color-accent`, `--color-accent-rgb`, etc. — nunca hardcodear colores.
2. **Nunca** cambiar el height de `.tab-item` sin actualizar todos los roles.
3. **Campo nuevo:**
   - Asignarlo a una categoría de sizing (short/medium/long/xl).
   - Asignar `max-width` usando las clases de sizing.
   - Colapsar a `100%` en ≤640px.
4. **Tabla nueva:**
   - Usar `.table-wrap` + `.data-table`.
   - Definir `min-width` si tiene > 5 columnas.
5. **Botón nuevo:**
   - Identificar nivel (1–4) y usar la clase correspondiente.
   - No crear botones con estilos inline fuera de este sistema.
6. **Regla Admin→Owner:**
   - Si se agrega una funcionalidad para admin, evaluar y replicar para owner.
   - Documentar excepciones explícitas con: `Excepción Admin/Owner: [motivo]`.

---

## 13. Naming de Labels / Label Naming

| Módulo funcional | UI Label (ES) | UI Label (EN) | UI Label (ZH) | Módulo interno |
|---|---|---|---|---|
| Cotizaciones | Cotizaciones | Quotes | 报价 | `assignments` |
| Usuarios | Usuarios | Users | 用户 | `users` |
| Productos de Proveedores | Productos | Products | 产品 | `products` |
| Unidades de Negocio | Unidades de negocio | Business Units | 业务单元 | `business_units` |
| Invitaciones | Invitaciones | Invitations | 邀请 | `invitations` |
| Proveedor | Proveedor | Supplier | 供应商 | `supplier` |
| Soporte | Soporte | Support | 支持 | `support` |
| Propietario | Propietario | Owner | 所有者 | `owner` |
| Perfil de Cotización Pública | (sin tab, acceso por token) | (token-only) | (仅令牌) | `quote.php` |

**Regla:** El nombre funcional se usa en UI, documentación y mensajes. El nombre interno se usa en rutas, DB, código PHP, y logs.

---

## 14. Mantenimiento de este Documento

- Editar cuando se agreguen nuevos módulos, roles o componentes de UI.
- Nunca eliminar secciones — agregar nota de deprecación si algo cambia.
- Mantener sincronizado con `css/style.css` (variables, clases, breakpoints).
- **No incluir** en commits de producción como asset cargado en runtime.
