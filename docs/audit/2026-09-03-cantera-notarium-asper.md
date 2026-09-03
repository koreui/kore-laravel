# Cantera: qué de Notarium y asper-server merece subir al boilerplate

**TL;DR**: se han inventariado los dos proyectos reales del autor —Notarium
(notaría, Laravel «clásico por capas», 553 clases) y asper-server (estudios
socioeconómicos, hijo directo del boilerplate bifurcado el 2026-05-20, 15
módulos)— buscando lo que **ambos** reinventaron y lo que uno resolvió mejor.
Convergen en cuatro huecos del boilerplate: **API con contrato**, **archivos
adjuntos**, **PDF** y **notificaciones in-app**; y asper aporta además una capa
de **E2E** (harness, fixtures y manual generado) que ningún boilerplate trae.
La propuesta son cuatro releases menores (v2.1 → v2.4) con dependencias nuevas
marcadas para aprobación.

Los informes de detalle (rutas archivo a archivo) están en el transcript de la
sesión; aquí va la síntesis y la decisión que hay que tomar.

## 1. Ficha de los dos proyectos

| | Notarium | asper-server |
|---|---|---|
| Qué es | Gestión notarial (expedientes, cotizaciones, escrituras, PLD, DeclaraNOT, licencias) + API v1 para app móvil | Estudios socioeconómicos y laborales (candidato → visita → dictamen → factura) + API v1 para app React Native |
| Relación con kore | Independiente; misma koreUi 2.0 | **Hijo directo**: 15 primeros commits idénticos, fork el 2026-05-20; se quedó en la «fase 3» (sin PHPat, sin `kore:arch:check`, CI desactivado) |
| Arquitectura | Controller → Blade → Livewire → `App\Services\*` (86 servicios, uno de 1.147 líneas); sin Actions ni módulos | `Core/Modules` como kore, 55 Actions, 15 módulos, **R3 rota en 12 de 15** |
| Tamaño | 553 clases, 112 migraciones, 173 archivos de test (Pest 4), sin CI | 1.566 PHP, 53 migraciones, ~907 tests, 20 specs E2E + 11 recorridos de manual |
| Paquetes que kore no tiene | medialibrary, laravel-pdf + Gotenberg, phpword, scramble, flysystem-s3, spatie/image, numero-a-letras | scramble, medialibrary, laravel-pdf + gotenberg-php, maatwebsite/excel |

Lo que **ninguno** tiene y kore sí (para no engañarse: el boilerplate va por
delante en disciplina): PHPat, disallowed-calls, `kore:arch:check`, hooks,
arch tests, `security.php`, backup, passkeys, i18n por módulo, catálogo de
reglas, MCP, CI verde, Docker con healthchecks reales. asper perdió todo eso al
bifurcarse antes de la v1.0: es el argumento más fuerte a favor de
`docs/ops/upgrading-from-boilerplate.md`.

## 2. Convergencias: lo que los dos reinventaron

Si dos proyectos independientes escriben lo mismo, la regla de tres se cumple
con el boilerplate como tercer sitio (`docs/patterns/README.md`).

| Hueco | Notarium | asper | Qué subiría |
|---|---|---|---|
| **API con contrato** | `ApiExceptionRenderer` (`{error:{code,message,details}}`), `BaseApiRequest/Resource`, `EnumResource`, cursor pagination, `ForceJsonResponse`, `ApiCacheableResponse` (ETag/304), limiters `api`/`api-auth`/`api-uploads`, Scramble tras `Gate viewApiDocs` | `/api/v1` con 51 endpoints, envelope `data`/`meta`, login con `throttle:6,1` y refresh, `shouldRenderJsonWhen`, `ApiDocsServiceProvider` apagable por toggle en `register()` | El esqueleto: renderer de errores, bases, paginación cursor, `Http/Resources/Api/V1`, limiters nombrados, Scramble con toggle `API_DOCS` y gate. Hoy `API_ENABLED` enciende **una** ruta (`/api/user`). |
| **Dispositivos móviles** | `MobileDevice` + token con abilities = permisos + revocación al cambiar permisos + `mobile:cleanup-*` con `--dry-run` | Refresh de token por dispositivo, push tokens en Notifications | Módulo opcional `Devices` sobre Sanctum |
| **Archivos adjuntos** | `MediaStorageService`: local instantáneo → compresión en cola → sync a S3/R2 con verificación → URL prefirmada o `signedRoute`; versiones por slot; archivado en vez de borrado; 3 jobs, 3 comandos, tests y doc | medialibrary + `MediaUrl::temporarySignedRoute` con el `v=` **dentro** de la firma | Módulo `Files` con toggle, contrato `FileStore` en Core, Actions `FileStore/Archive/PreviewUrl`, disco S3 opcional, compresión opcional |
| **PDF** | spatie/laravel-pdf + Gotenberg, temas (`EstiloPdf`) con preview, DOCX→PDF vía LibreOffice de Gotenberg, imágenes→PDF | `Core/Support/PdfImagen` (cicatriz: Gotenberg en otro contenedor no ve `127.0.0.1`, la imagen sale rota en silencio → data-URI), `PdfBrandData`, servicio `gotenberg` en compose | Módulo `Pdf` opcional: driver Gotenberg en compose, `PdfImagen`, marca/portada como DTOs de Core, un tema base |
| **Excel** | — | `Exports/` + import por overlay **y** por artisan con DTO de resultado | Patrón documentado + carpeta `Exports/` en R3; paquete sólo si se pide |
| **Notificaciones in-app** | 4 notificaciones sueltas | Módulo `Notifications` (42 archivos): bandeja persistente, preferencias por canal, push tokens, poda, `NotificationPayload` único web/móvil | Módulo opcional, sin canal push real (loguea, como en asper) |

## 3. Lo que uno solo resolvió mejor

**De asper (E2E y proceso):**
- **Harness E2E con tres candados** (`app/Modules/E2E`, `HarnessGuard`): rutas `/__e2e__/*` para sembrar, entrar como rol, leer correo, limpiar throttle; sólo si flag + entorno en lista blanca + **el nombre de la base contiene `e2e`/`test`**. Mismo candado duplicado en bash antes de `migrate:fresh`.
- **`ErrorGuard`**: todo test falla si la pantalla lanzó un error JS o un 5xx aunque la aserción pase. **`livewire.ts`**: espera real de peticiones Livewire en vuelo (el sustituto operativo de R38). **`access-map.ts`**: una tabla de rutas × roles alimenta el spec de RBAC y el smoke (la forma automática de R36).
- **Manual de usuario generado desde los E2E** (`playwright.manual.config.ts`, 11 guías con capturas, PDF opcional). Si la pantalla cambia, el manual se rompe y avisa.
- `HALLAZGOS.md` (bugs con id citado en test y fix) y `docs/project/{decisions,status}.md` (ADR ligero + estado por módulo).
- Contratos-gate con **null object** (`AlwaysCompleteStaffFileGate`): un módulo puede no estar y la app responde.
- Códigos de invitación + `AccountStatus` + `EnsureAccountIsActive` (alta controlada); `DevAccountSwitcher` (impersonar roles en local).

**De Notarium (piezas pequeñas y afiladas):**
- **Settings singleton en BD con fallback a `config/`** (`NotariaConfiguracion::instancia()`): datos de la organización editables sin redeploy.
- **Series de folio** con `lockForUpdate()` + snapshot inmutable del emisor (`ReciboService::emitir()`): consecutivos sin huecos.
- **Feature flags por instalación** (`Support\Features` + middleware `feature:`): «tu licencia no incluye esto» ≠ «no tienes permiso» ≠ Pennant.
- Firma HMAC servidor↔servidor + outbox de eventos con reintentos (`VerificaFirmaLicencia`, `EnviarEventosLicenciaJob`): webhooks salientes fiables.
- `->sentryMonitor()` en todo el scheduler; redirección amable del 419; `GeneraUuid` (uuid público, PK entera); comandos con `--dry-run`; búsqueda global filtrada por `*.view`; agente `notarium-migration` (la trampa del `->change()` que pierde atributos: hoy no es regla del catálogo).
- **Autosave por sección** con estado `idle|saving|saved|error`; **entity picker** en drawer con payload normalizado; tres duplicaciones que piden traits en `Core/Concerns`: `HandlesDeleteConfirmation`, `RedirectsWithToast`, `HandlesSlotUploads`.
- Catálogo SEPOMEX (CP → colonia/municipio/estado) y `MontoEnLetras`: genéricos **para México**; van como módulo opcional `Mx`, no en el núcleo.

## 4. Lo que la cantera enseña sobre las reglas

- **R3 hay que abrirla.** Un fork escrito por el propio autor con la regla delante creó `Enums/` en 9 módulos, `Http/Resources/` en 5, `Exports/` en 2, `Services/` en 2, `Exceptions/` y `Channels/`+`Notifications/` en 1. `Enums/`, `Http/Resources/` y `Exports/` entran a la lista; `Services/` sigue siendo la que hay que justificar (el motor de formularios de 60 clases de asper es el caso legítimo; `ExpedienteService` de 1.147 líneas de Notarium es el que R3 quería evitar).
- **R5 necesita decir si un `{Domain}\Events\*` es importable desde otro módulo.** asper lo hace (Notifications escucha 11 eventos de Payments/Personnel/Studies) y PHPat tal como está en kore lo marcaría en rojo. Propuesta: sí, los eventos son la frontera pública de un módulo, y PHPat lo permite por selector.
- **Config de módulo en `config/`**: asper tiene seis (`billing.php`, `notifications.php`…). R11/R12 no lo contemplan; hay que decir dónde viven los parámetros de un módulo (propuesta: `config/{modulo}.php`, publicado por el provider, leído sólo por ese módulo).
- **Regla nueva candidata**: «al modificar una columna repite todos sus atributos» (cicatriz real de Notarium, con agente propio).
- **Anti-escalada R26 y R4 valen**: Notarium hace `syncPermissions` dentro del Form y sin comprobar que el actor posea los permisos que concede. El boilerplate ya lo evita; conviene que `docs/guides/crud.md` lo cuente como cicatriz externa.

## 5. Propuesta por releases

Cada release cierra un tema, trae reglas nuevas con enforcement, y sus
paquetes nuevos se aprueban antes.

### v2.1.0 — «La suite se defiende sola» (sin paquetes nuevos · esfuerzo M)
- Módulo `E2E` con `HarnessGuard` (tres candados), `MailLog`, rutas `/__e2e__/*` mínimas, toggle `kore-app.e2e`, canal `e2e_mail`, `scripts/e2e.sh` + `e2e-seed.sh`.
- Fixtures: `error-guard.ts` (obligatorio en todo test), `livewire.ts`, `access-map.ts`; migrar la suite actual a ellos.
- `Core/Concerns` para Livewire: `HandlesDeleteConfirmation`, `RedirectsWithToast`; aplicarlos en Users.
- Bonus S: `->sentryMonitor()` en el scheduler, 419 amable con test, `GeneraUuid`, plantilla de comando con `--dry-run`, `DevAccountSwitcher` sólo en `local`.
- Reglas: R51 (harness sólo con flag + entorno + base de pruebas), R52 (toda pantalla nueva entra en `access-map.ts`), R53 (`->change()` repite atributos; con check textual en `kore:arch:check`). R3 ampliada con `Enums/`, `Http/Resources/`, `Exports/`; R5 aclara los eventos como frontera.

### v2.2.0 — «API con contrato» (paquete nuevo: `dedoc/scramble` · esfuerzo M)
- `Core/Http/Api`: `ApiExceptionRenderer`, `BaseApiRequest`, `BaseApiResource`, `EnumResource`, `HandlesCursorPagination`, `ForceJsonResponse`, `ApiCacheableResponse`; `shouldRenderJsonWhen` para `api/*`.
- Auth API v1: login/logout/refresh con `throttle:api-auth`, `Http/Resources/Api/V1` en Auth y Users (el CRUD de Users expuesto como referencia).
- Scramble en `/docs/api` con toggle `API_DOCS` (apagado en `register()`) y gate `viewApiDocs`; test de que el spec se genera.
- Módulo opcional `Devices` (`MobileDevice`, abilities = permisos, revocación al cambiar permisos, `devices:cleanup --dry-run`).
- Reglas: R54 (toda respuesta de API pasa por el renderer/envelope; PHPat: controllers de `Api/` sólo devuelven Resources).

### v2.3.0 — «Archivos y documentos» (paquetes nuevos: `spatie/laravel-medialibrary`, `league/flysystem-aws-s3-v3`; opcionales `spatie/laravel-pdf` + `gotenberg/gotenberg-php`, `spatie/image` · esfuerzo L)
- Módulo `Files` con toggle: `MediaStorageService` de Notarium como Actions, jobs de compresión/sync opcionales, URL firmada con `v=` dentro (asper), `MediaLocalController` para lo aún no sincronizado, `HandlesSlotUploads`, componente koreUi de subida por slot con preview.
- Módulo `Pdf` opcional: Gotenberg en `docker-compose.prod.yml` (perfil), `PdfImagen`/`PdfLogo`, `PdfBrandData`, un tema base con preview; DOCX→PDF documentado.
- `Exports/` con `maatwebsite/excel` como guía (`docs/guides/exports.md`) sin instalarlo en el núcleo.

### v2.4.0 — «Plataforma» (sin paquetes nuevos · esfuerzo M/L)
- `Core/Settings` (singleton en BD con fallback a config, formulario en Users/Admin), `Core/Numbering` (series con `lockForUpdate` + snapshot), `Features` por instalación con middleware `feature:` (capa distinta de `kore-app` y de Pennant, documentada en `toggles.md`).
- Módulo `Notifications` opcional (bandeja in-app, preferencias, poda; push sólo loguea).
- Invitaciones + `AccountStatus` como toggle `AUTH_INVITATIONS`.
- Webhooks salientes: firma HMAC + outbox con reintentos.
- Manual generado desde los E2E (`npm run manual`) con una guía de ejemplo sobre Users; PDF opcional vía Gotenberg.
- Módulo opcional `Mx` (SEPOMEX + `MontoEnLetras`) como paquete aparte o carpeta `optional/`.

## 6. Lo que se descarta a propósito

Negocio puro de cada proyecto (expedientes, PLD, DeclaraNOT, ISR, licencias;
estudios, candidatos, visitas, cronograma, cobros de campo), el motor de
formularios schema-first de asper (60 clases: valioso, pero es un producto en
sí), las islas Vue de asper (el boilerplate es Livewire + Alpine), `Pest.php`
de Notarium (borra tablas de la base de desarrollo), el `UserForm` de Notarium
(escribe desde el Form y sin anti-escalada), `breadcrumbs.blade.php` (muerto),
y las páginas `Dev/*` de Notarium tal cual (sin guard de entorno).

## 7. Decisión pendiente

1. ¿Se aprueba el orden v2.1 → v2.4, o se prioriza otro tema (por ejemplo API antes que E2E)?
2. Paquetes a autorizar: `dedoc/scramble` (v2.2); `spatie/laravel-medialibrary` + `league/flysystem-aws-s3-v3` (v2.3); opcionales `spatie/laravel-pdf` + `gotenberg/gotenberg-php` + `spatie/image`.
3. Ampliación de R3 (`Enums/`, `Http/Resources/`, `Exports/`) y la aclaración de R5 sobre eventos: son decisiones de catálogo y las firma una persona (R44).
4. Camino inverso: asper se quedó en la fase 3; una vez estabilizado v2.1, aplicar `docs/ops/upgrading-from-boilerplate.md` sobre asper sería la primera prueba real de esa guía.
