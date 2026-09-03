# Módulo Docs

**TL;DR**: con `DOCS_ENABLED=true`, `/docs` sirve los Markdown de `docs/`
renderizados con la UI de la aplicación: enlaces entre documentos reescritos,
tablas de GFM, índice lateral y un enlace al original en GitHub. Con el toggle
apagado —el default, y lo que debe estar en producción— las rutas no existen y
`/docs` es un 404.

## Por qué existe

La landing enlazaba a `https://github.com/koreui/kore-laravel/tree/main/docs`
porque `/docs` daba 404. Leer las reglas mientras se programa obligaba a salir
del proyecto, y en un clon privado el enlace ni siquiera resuelve. El visor lo
arregla sin duplicar nada: la fuente sigue siendo `docs/*.md`, que es lo que se
revisa en el PR y lo que R40 vigila.

No sustituye a GitHub: cada página lleva su «Ver en GitHub», y con el toggle
apagado los enlaces vuelven a apuntar allí solos.

## El toggle

| Variable | Default | Qué activa | Quién lo lee |
|----------|---------|------------|--------------|
| `DOCS_ENABLED` | `false` | Rutas y traducciones del módulo Docs (`/docs`) | `DocsModuleServiceProvider` |

- `.env.example` lo trae en `true`: en local es la forma cómoda de leer el
  catálogo de reglas sin cambiar de ventana.
- **En producción va en `false`**, y por eso el default de `config/kore-app.php`
  también lo es: un `.env` que se olvide de la clave no publica nada. Ver
  [`../ops/deployment.md`](../ops/deployment.md).
- `.env.e2e` lo enciende: la suite de Playwright cubre el visor.
- `phpunit.xml` lo fuerza a `false`, para que el resultado de la suite Pest no
  dependa de lo que cada desarrollador tenga en su `.env`. Los tests que
  necesitan el visor lo encienden con `withEnvironment()` (`tests/Pest.php`).

## Rutas

| Método | URI | Nombre | Qué sirve |
|--------|-----|--------|-----------|
| GET | `/docs` | `docs.index` | `docs/README.md`, el índice maestro |
| GET | `/docs/{path}` | `docs.show` | `docs/{path}.md` |

`{path}` está restringido a `[A-Za-z0-9_\-/]+`: **sin puntos**, así que no
existe un `{path}` que contenga `..` ni una extensión.

Las rutas van en el grupo `web` y **sin `auth`**: los mismos documentos están
publicados en GitHub, y pedir sesión daría una falsa sensación de privacidad.
Quien decide si se sirven es el toggle.

## Estructura

```
app/Modules/Docs/
├── Data/DocumentData.php                 # título + html + encabezados ##
├── Http/Controllers/DocsController.php   # index() y show(), delgados
├── Providers/DocsModuleServiceProvider.php
├── Resources/
│   ├── lang/en.json
│   └── views/{index,show}.blade.php
├── Routes/web.php
├── Support/
│   ├── MarkdownRenderer.php              # Str::markdown() + título + cuerpo
│   └── DocLinkExtension.php              # enlaces y anclas
└── Tests/
    ├── Feature/{DocsToggleTest,DocsPagesTest}.php
    └── Unit/MarkdownRendererTest.php
```

Sin `Actions/`: no hay ningún caso de uso que escriba. Leer un archivo y
convertirlo a HTML es una consulta, y vive en `Support/` (R3).

## Cómo se resuelve una ruta a un archivo

`/docs/architecture/rules` → `base_path('docs/architecture/rules.md')`, en tres
pasos:

1. La expresión de la ruta ya deja fuera el punto: `/docs/../.env` y
   `/docs/..%2F.env` **no llegan al controlador**, no casan con ninguna ruta.
2. `DocsController::resolve()` hace `realpath()` del archivo y de
   `base_path('docs')`, y comprueba que el primero empiece por el segundo. Con
   `realpath` también se resuelven los enlaces simbólicos: un symlink dentro de
   `docs/` que apunte fuera tampoco cuela.
3. Si el archivo no existe, no es un archivo o cae fuera de `docs/`, `abort(404)`
   (permitido en `Http/*` por R20).

La comprobación 2 es redundante con la 1 **a propósito**: el día que alguien
relaje el `where()` de las rutas, `/docs/../.env` tiene que seguir siendo un 404.

`/docs` sirve `docs/README.md`. No hay una segunda lista de documentos que
mantener: el índice del visor **es** el índice maestro que R40 ya obliga a tener
al día.

## Cómo se reescriben los enlaces

Los `.md` de `docs/` están escritos para GitHub y se enlazan entre ellos con
rutas relativas. Servidos tal cual desde `/docs/...` apuntarían a ninguna parte.
`DocLinkExtension` los traduce:

| En el Markdown | Desde | En el visor |
|----------------|-------|-------------|
| `rules.md` | `docs/architecture/overview.md` | `/docs/architecture/rules` |
| `../architecture/rules.md` | `docs/modules/users.md` | `/docs/architecture/rules` |
| `rules.md#r11` | `docs/architecture/overview.md` | `/docs/architecture/rules#r11` |
| `../README.md` | cualquiera | `/docs` (no `/docs/README`) |
| `../../CHANGELOG.md` | `docs/architecture/rules.md` | `https://github.com/koreui/kore-laravel/blob/main/CHANGELOG.md` |
| `https://laravel.com` | cualquiera | intacto |
| `#una-ancla`, `/users`, `mailto:` | cualquiera | intactos |

Lo que se sale del repositorio (`../../../fuera.md`) se deja como está: no hay
reescritura que tenga sentido.

**Es una extensión de CommonMark, no una regex sobre el Markdown.** Un `](...)`
también aparece dentro de un span o un bloque de código —`docs/audit/…` cita
literalmente `` `[koreUi](../koreUi)` ``— y una regex sobre el texto lo
reescribiría, cambiando lo que el documento dice. La extensión trabaja sobre el
árbol ya parseado, donde un enlace es un `Link` y el código es código.

De paso pone el `id` de cada encabezado (CommonMark no los genera) con el mismo
esquema de slug que GitHub —acentos incluidos, `Autorización` → `autorización`—,
que es lo que hace que las anclas entre documentos (`deployment.md#logs`) sigan
funcionando, y recoge los `##` para el índice lateral. El slug se calcula una
sola vez y se usa para las dos cosas: si se calculara aparte, el índice lateral
apuntaría a anclas que no existen.

## El renderizado

`MarkdownRenderer` usa `Str::markdown()`, es decir el
`GithubFlavoredMarkdownConverter` que Laravel ya trae (league/commonmark):
tablas, listas de tareas y bloques de código sin instalar nada. Dos opciones
importan:

- `html_input => 'strip'` — el HTML crudo del Markdown se tira. Los docs son del
  repositorio y son de fiar, pero la plantilla los pinta con `{!! !!}` y ésta es
  la única frontera que hay.
- `allow_unsafe_links => false` — descarta `javascript:` y compañía.

El título es el primer `# ` del documento y se **quita** del cuerpo: la plantilla
lo pinta como `<h1>` de la página, y dejarlo también dentro de la prosa daría dos
`<h1>` en la misma pantalla.

## La UI

- Layout **`x-layouts.public`**, el de invitado. `/docs` no pide sesión, y
  `x-layouts.app` pinta la tarjeta del usuario autenticado, que aquí no existe.
- Breadcrumb `Docs › architecture › rules` con `<x-kore::breadcrumbs :items>`;
  sólo el primer nivel navega, porque `docs/architecture` es una carpeta y no
  una página.
- Botones `<x-kore::button>` para «Índice» y «Ver en GitHub».
- Índice lateral con los `##` del documento, a partir de tres.
- La prosa se estila con `.docs-prose` en `resources/css/app.css`: dos docenas de reglas
  para `h2/h3/h4`, `p`, listas, `pre`/`code`, tablas, citas y `hr`, todas sobre
  tokens de koreUi (`--kore-fg`, `--kore-border`, `--kore-muted`,
  `--kore-primary-text`), así que el tema oscuro sale gratis.
  **`@tailwindcss/typography` no está instalado y no se instala por esto.**
- No hay Livewire: son dos páginas de sólo lectura.

## Lo único que se registra con el toggle apagado

El `boot()` del provider registra el namespace de vistas `docs::` **siempre**, y
después hace el `return` temprano. La razón: Larastan tipa el primer argumento de
`view()` como `view-string` y lo valida contra el `ViewFactory` de la aplicación
que arranca durante el análisis; en CI no hay `.env`, el toggle vale su default
(`false`) y `composer analyse` se caería por un archivo que sí está en el
repositorio. Registrar la ruta de las vistas no habilita nada: sin rutas no hay
forma de llegar a ellas.

## Tests

| Archivo | Tests | Qué cubre |
|---------|-------|-----------|
| `Tests/Feature/DocsToggleTest.php` | 5 | con el toggle apagado no hay rutas ni traducciones y `/docs` es 404; con él encendido las dos rutas existen |
| `Tests/Feature/DocsPagesTest.php` | 13 | índice, documento con tabla y anclas, enlaces reescritos, enlace a GitHub, 404 del documento inexistente, cinco formas de intentar salirse de `docs/`, y la landing enlazando al visor |
| `Tests/Unit/MarkdownRendererTest.php` | 25 | la reescritura de enlaces caso por caso, el slug de los encabezados, el título, GFM, el código que no se toca y el `html_input => 'strip'` |

E2E en `tests/e2e/specs/docs/` (12 tests): `smoke.spec.ts`,
`navigation.spec.ts` y `authorization.spec.ts`, con el page object
`tests/e2e/pages/DocsPage.ts`. Ver [`../quality/e2e.md`](../quality/e2e.md).
