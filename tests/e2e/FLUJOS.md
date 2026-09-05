# Mapa de flujos de kore-laravel

Inventario de **todo lo que se puede hacer en el boilerplate**, quién lo hace y
qué spec lo cubre. Sirve para dos cosas:

1. **Saber qué falta por probar.** Cada flujo lleva su estado de cobertura y el
   spec que lo prueba.
2. **Guiar la suite.** Un flujo nuevo se apunta aquí *antes* de escribir su
   spec; una pantalla nueva se apunta en
   [`fixtures/access-map.ts`](./fixtures/access-map.ts), que la cubre en RBAC y
   en smoke sin escribir un test.

Lo que la suite **encuentra** va a [`HALLAZGOS.md`](./HALLAZGOS.md).

**Leyenda de cobertura**

| Marca | Significa |
|---|---|
| ✅ | Cubierto de punta a punta |
| 🟡 | Cubierto en parte (abre y lo esencial, no todas las ramas) |
| ⬜ | Sin cubrir todavía |
| 🔒 | No se puede probar aquí (depende de un servicio externo o de otro entorno) |

**Cifras de la suite**: 176 tests en 19 archivos (4 de ellos son el proyecto
`setup`, que abre una sesión por rol). 104 salen generados del mapa de acceso.

---

## A · Entrar y salir

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| A1 | Iniciar sesión con correo y contraseña | Todos | ✅ | `auth/login` |
| A2 | Rechazo con credenciales incorrectas | Todos | ✅ | `auth/login` |
| A3 | Cerrar sesión y perder el acceso | Todos | ✅ | `auth/login` |
| A4 | Un invitado en una pantalla protegida acaba en `/login` | — | ✅ | `access/rbac` (los 9 casos del mapa) |
| A5 | Registrarse | Invitado | ✅ | `auth/register` |
| A6 | Rechazos del registro: contraseña corta, confirmación distinta, email repetido | Invitado | ✅ | `auth/register` |
| A7 | La cuenta nueva aterriza en «verifica tu correo» | Invitado | ✅ | `auth/register` |
| A8 | Verificar el correo pulsando el enlace | Usuario nuevo | ⬜ | — |
| A9 | Pedir el enlace de recuperación de contraseña | Todos | ✅ | `auth/forgot-password` |
| A10 | Fijar la contraseña nueva desde el enlace del correo | Todos | ⬜ | — |
| A11 | Entrar con código por email (magic link), camino feliz | Todos | ✅ | `auth/magic-link` |
| A12 | Código incorrecto y anti-enumeración de correos | Todos | ✅ | `auth/magic-link` |
| A13 | `/magic-link` con sesión abierta responde 200 (no rebota) | Autenticado | ✅ | `access/rbac` · KORE-E2E-002 |
| A14 | Activar 2FA, confirmar con código y guardar los de recuperación | Todos | ⬜ | — |
| A15 | Entrar con 2FA activo (reto de segundo factor) | Todos | ⬜ | — |
| A16 | Confirmar la contraseña para una acción sensible | Todos | ✅ | `auth/passkeys`, `access/rbac` (`'confirm'`) |
| A17 | Entrar con un proveedor social | Todos | 🔒 | toggle apagado en `.env.e2e`; depende de Google/GitHub |

## B · Passkeys (WebAuthn)

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| B1 | Registrar una passkey desde `/user/passkeys` | Autenticado | ✅ | `auth/passkeys` |
| B2 | Entrar con la passkey, sin contraseña | Autenticado | ✅ | `auth/passkeys` |
| B3 | La credencial existe de verdad en el autenticador, no sólo en la UI | — | ✅ | `auth/passkeys` (`credentials()`) |
| B4 | Eliminar una passkey de la lista | Autenticado | ✅ | `auth/passkeys` |
| B5 | La pantalla lista vacío cuando no hay ninguna | Autenticado | ✅ | `auth/passkeys` |
| B6 | La pantalla exige confirmar la contraseña antes de abrirse | Autenticado | ✅ | `auth/passkeys`, `access/rbac` |
| B7 | El botón «Entrar con passkey» está en `/login` | Invitado | ✅ | `auth/passkeys` |
| B8 | Renombrar una passkey | Autenticado | ⬜ | — |

## C · Users (el CRUD de referencia del boilerplate)

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| C1 | Listar usuarios | `users.view` | ✅ | `users/index` |
| C2 | El listado oculta a los superadmin | — | ✅ | `users/index` |
| C3 | Buscar en la tabla (`wire:model.live` con debounce) | `users.view` | ✅ | `users/index` |
| C4 | Crear un usuario con su rol | `users.create` | ✅ | `users/create` |
| C5 | Validación del alta: campos vacíos, email repetido | `users.create` | ✅ | `users/create` |
| C6 | Editar el nombre de un usuario | `users.edit` | ✅ | `users/edit` |
| C7 | Editar sin tocar la contraseña la deja usable | `users.edit` | ✅ | `users/edit` |
| C8 | Borrar desde la fila, con diálogo de confirmación | `users.delete` | ✅ | `users/delete` · KORE-E2E-006 |
| C9 | Cancelar la confirmación no borra nada | `users.delete` | ✅ | `users/delete` |
| C10 | A quien no tiene permiso se le ocultan las acciones de fila | viewer · editor | ✅ | `users/delete` |
| C11 | Asignar permisos directos desde el formulario | `users.edit` | 🟡 | `users/create` (el checkbox existe; no se prueba el efecto) |
| C12 | Ordenar la tabla por columna | `users.view` | ⬜ | — |
| C13 | Paginación de la tabla | `users.view` | ⬜ | — |
| C14 | Exportar el listado | `users.view` | ⬜ | no existe todavía |

## D · Dashboard

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| D1 | Ver el dashboard con el saludo propio | Autenticado | ✅ | `users/dashboard` |
| D2 | Sin `users.view` no aparece el acceso a Usuarios (sidebar ni tarjeta) | member | ✅ | `users/dashboard` |
| D3 | Con `users.view` sí aparece, y lleva al listado | viewer | ✅ | `users/dashboard` |
| D4 | Cambiar el tema (claro/oscuro) | Todos | ⬜ | — |

## E · Documentación en la aplicación (`/docs`)

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| E1 | Abrir el índice (`docs/README.md`) | Cualquiera | ✅ | `docs/smoke` |
| E2 | Los enlaces del índice apuntan al visor, no a GitHub | — | ✅ | `docs/smoke` |
| E3 | Lo que está fuera de `docs/` se manda al repositorio | — | ✅ | `docs/smoke` |
| E4 | Navegar del índice a un documento y leerlo con sus tablas | Cualquiera | ✅ | `docs/navigation` |
| E5 | El breadcrumb refleja la ruta y vuelve al índice | Cualquiera | ✅ | `docs/navigation` |
| E6 | El índice lateral salta a la sección | Cualquiera | ✅ | `docs/navigation` |
| E7 | Los enlaces entre documentos se resuelven dentro del visor | Cualquiera | ✅ | `docs/navigation` |
| E8 | Cada documento ofrece su original en GitHub | Cualquiera | ✅ | `docs/navigation` |
| E9 | El visor es público | Invitado | ✅ | `docs/authorization`, `access/rbac` |
| E10 | No se puede salir de `docs/` con una ruta escapada | Atacante | ✅ | `docs/authorization` |
| E11 | Un documento inexistente es 404 | Cualquiera | ✅ | `docs/authorization` |
| E12 | Con `DOCS_ENABLED=false` las rutas no existen | — | 🔒 | necesita rearrancar la app; lo cubre `DocsToggleTest` en Pest |

## F · Landing pública

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| F1 | La landing carga con su heading y su título | Invitado | ✅ | `smoke/landing`, `access/smoke` |
| F2 | El CTA de registro lleva a `/register` | Invitado | ✅ | `smoke/landing` |
| F3 | El CTA de login lleva a `/login` | Invitado | ✅ | `smoke/landing` |
| F4 | La landing enlaza al visor de docs cuando el toggle está encendido | Invitado | ✅ | `docs/smoke` |
| F5 | `/up` responde 200 sin tocar sesión ni base | Monitor | ✅ | `smoke/landing` |

## G · Observabilidad

| # | Flujo | Quién | Cobertura | Spec |
|---|---|---|---|---|
| G1 | `/health` sólo lo abre el superadmin | superadmin | ✅ | `access/rbac`, `access/smoke` |
| G2 | `/pulse` sólo lo abre el superadmin | superadmin | ✅ | `access/rbac`, `access/smoke` |
| G3 | `/pulse` responde 403 al invitado en vez de mandarlo al login | — | ✅ | `access/rbac` · KORE-E2E-003 |
| G4 | `/health/json` con su token para monitores externos | Monitor | ⬜ | — |
| G5 | Los checks de salud reportan cada uno su estado | superadmin | ⬜ | lo cubre Pest |

## H · Control de acceso (transversal)

Todo esto sale de [`fixtures/access-map.ts`](./fixtures/access-map.ts) y no se
escribe a mano: **14 pantallas × 5 perfiles = 70 comprobaciones** de status en
`access/rbac`, más **34 comprobaciones ruta × perfil que abren de verdad** en `access/smoke`.

| # | Flujo | Cobertura | Spec |
|---|---|---|---|
| H1 | Cada pantalla devuelve a cada perfil el status que promete el seeder | ✅ | `access/rbac` |
| H2 | Las pantallas de `guest` rebotan al dashboard a quien ya entró | ✅ | `access/rbac` |
| H3 | Lo protegido manda al invitado a `/login` | ✅ | `access/rbac` |
| H4 | `password.confirm` intercepta antes de la pantalla | ✅ | `access/rbac` |
| H5 | Cada pantalla accesible carga, monta Livewire y muestra su heading | ✅ | `access/smoke` |
| H6 | Ninguna pantalla muestra claves de traducción sin resolver | ✅ | `access/smoke` · KORE-E2E-001 |
| H7 | Ninguna pantalla lanza excepciones de JS ni provoca 5xx | ✅ | el guardia, en **todos** los tests |
| H8 | `/users/{user}/edit`, que lleva parámetro | 🟡 | `users/edit` (se llega por la fila, no por el mapa) |

## I · El andamiaje de la suite

| # | Flujo | Cobertura | Spec |
|---|---|---|---|
| I1 | El harness apunta a un entorno y una base de pruebas | 🟡 | `harness/harness` — **se salta** hasta que `app/Modules/E2E` aterrice |
| I2 | Crear un usuario, entrar con él y borrarlo sin UI | 🟡 | `harness/harness` — ídem |
| I3 | Leer el último correo desde el harness | ⬜ | de momento se lee del log (`support/mail-log.ts`) |
| I4 | Limpiar el limitador de peticiones entre tests | ⬜ | de momento se esquiva con cuentas de email único |

---

## Cómo se extiende esto

- **Pantalla nueva** → una entrada en `fixtures/access-map.ts`. Queda cubierta
  por RBAC y por el smoke sin escribir un test, y aparece en la sección H.
- **Flujo nuevo** → apúntalo primero aquí, con su marca de cobertura, y luego
  escribe su spec en `specs/{modulo}/`.
- **Bug encontrado** → [`HALLAZGOS.md`](./HALLAZGOS.md), con su `KORE-E2E-###`
  citado desde el test.
