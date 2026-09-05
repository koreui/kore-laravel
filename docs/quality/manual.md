# Manual de usuario generado desde los E2E

**TL;DR**: `npm run manual` recorre la aplicación con Playwright, fotografía
cada paso y deja `docs/manual/` escrito: una guía en Markdown por recorrido, con
su captura por paso, más el índice. `npm run manual:pdf` lo junta todo en un PDF
con Gotenberg. Las guías y las capturas **no se versionan**; el único archivo
del manual que está en git es [`../manual/README.md`](../manual/README.md), como
punto de entrada y como ejemplo.

## Por qué el manual sale de los E2E

Un manual con capturas envejece mal. Se escribe una vez, la pantalla cambia dos
meses después y nadie vuelve a abrir el documento: a partir de ahí el manual
**miente**, y miente con la autoridad de una foto.

Generarlo desde un recorrido de Playwright invierte eso. El recorrido no es
prosa: es código que busca «Nuevo usuario» y pulsa ahí. Si el botón se llama
distinto, o deja de estar, la generación **falla** — el mismo mecanismo que hace
útil un test E2E. Un manual que no se puede regenerar es un manual que ya sabes
que está mal.

Y como los recorridos usan los **mismos page objects que la suite**
(`tests/e2e/pages/`), mantener la suite verde mantiene el manual al día sin
trabajo extra: se arregla el page object una vez y las dos cosas se enteran.

## Qué NO es

- **No es un test.** Un recorrido no comprueba reglas de negocio ni casos
  límite; enseña el camino feliz, y con texto dirigido a quien usa la
  aplicación. Las aserciones que lleva son las mínimas para no fotografiar una
  pantalla equivocada.
- **No corre en CI de cada PR.** El manual no está en `.github/workflows/e2e.yml`
  a propósito: son minutos, muchas imágenes y ningún veredicto que dar sobre el
  cambio. Se genera a mano cuando hace falta, o desde
  `.github/workflows/manual.yml`, que es `workflow_dispatch`.

## Las piezas

| Archivo | Qué hace |
| --- | --- |
| `playwright.manual.config.ts` | Proyecto aparte: `testDir` `tests/e2e/manual`, `testMatch` `**/*.guia.ts`, un worker, sin reintentos, tema claro, `deviceScaleFactor: 2`, timeout de 10 min y servidor propio en `E2E_MANUAL_PORT` (8110). |
| `tests/e2e/manual/setup.ts` | `globalSetup`: comprueba que el harness responde y que la base es de pruebas (si no, se planta), y crea `docs/manual/imagenes/`. |
| `tests/e2e/manual/fixtures/guia.ts` | `recorrido()` y la clase `Guia`: `capitulo()`, `paso()`, `senalar()`, `nota()` y `cerrar()`. Ejecuta, fotografía y acumula el Markdown. |
| `tests/e2e/manual/fixtures/rutas.ts` | Las constantes de salida y de puerto, sin importar nada (lo cargan `globalSetup`/`globalTeardown`, antes de que exista el runner). |
| `tests/e2e/manual/*.guia.ts` | Los recorridos. Hoy uno: `01-usuarios.guia.ts`. |
| `tests/e2e/manual/teardown.ts` | `globalTeardown`: rehace `docs/manual/README.md` leyendo las guías que hay en la carpeta. |
| `scripts/manual.sh` | El comando de un solo golpe: puerto, assets, base sembrada, recorridos y —si Gotenberg responde— PDF. |
| `scripts/manual-pdf.mjs` | Concatena los `.md` en un HTML autocontenido (capturas en base64) y se lo manda a Gotenberg. |

### El puerto propio

El manual levanta **su propio servidor** en el 8110, no el 8010 de la suite. Así
los dos pueden convivir sin que uno reutilice el servidor del otro (Playwright
reutiliza el que encuentre) ni le recree la base a media corrida. Se cambia con
`E2E_MANUAL_PORT`.

Lo levanta y lo baja Playwright (`webServer`), no `scripts/manual.sh`: si esto
se corta con Ctrl-C no queda un `artisan serve` huérfano.

## Escribir una guía

Un recorrido es **un solo test**, largo y en orden: el manual es una narración y
cada paso da por hecho lo que dejó el anterior.

```ts
import { expect } from '@playwright/test';

import { esperarLivewire } from '../fixtures';
import { UsersIndexPage } from '../pages/UsersIndexPage';
import { recorrido } from './fixtures/guia';

recorrido(
    {
        slug: '02-facturas',              // nombre del .md y prefijo de sus capturas
        titulo: 'Emitir una factura',
        paraQuien: 'Para administración: quien factura al cierre del mes.',
        introduccion: 'Qué se va a ver y de qué se parte…',
    },
    async ({ page, harness, guia }) => {
        const facturas = new FacturasIndexPage(page);

        await guia.capitulo('Crear la factura', 'Entradilla opcional del apartado.');

        // paso(): hace lo que dice y fotografía EL RESULTADO.
        await guia.paso('Abre «Facturas»', 'Está en el menú, en el grupo…', () => facturas.goto());

        // senalar(): dibuja un halo sobre el objetivo, fotografía, y DESPUÉS pulsa.
        await guia.senalar('Pulsa «Nueva»', 'Arriba a la derecha.', facturas.newButton);

        // nota(): un párrafo sin imagen, para lo que no se ve en pantalla.
        await guia.nota('El importe se congela al emitir: una subida de tarifa no toca lo emitido.');
    },
);
```

Reglas de la casa, las mismas que la suite:

- **R37 · sin `data-testid`.** Localizadores accesibles, y si algo no se puede
  localizar, es un problema de accesibilidad de la pantalla.
- **R38 · sin `waitForTimeout()`.** `Guia.reposar()` ya espera a Livewire, a la
  red, a las fuentes y a un fotograma pintado antes de cada foto.
- **R39 · datos propios** con `uniqueEmail()` / `uniqueName()`. Los recorridos
  dan de alta cosas de verdad; por eso `scripts/manual.sh` recrea la base en
  cada corrida.

Y dos que son sólo del manual:

- **El texto lo lee quien usa el sistema, no quien lo construye.** Nada de
  nombres de clases, deudas técnicas ni explicaciones de por qué la pantalla es
  como es; eso va a `tests/e2e/HALLAZGOS.md`.
- **Reutiliza los page objects de la suite.** Es lo que hace que arreglar una
  pantalla arregle el manual. Si una guía necesita un localizador que no existe,
  ponlo en el page object, no en la guía.

### Capítulos que dependen de un toggle

`01-usuarios.guia.ts` sólo cuenta la foto de perfil si `FILES_ENABLED` está
encendido en `.env.e2e`, leyendo `e2eEnv` de `tests/e2e/support/env.ts`. Un
derivado que apague el módulo obtiene una guía más corta, no una guía rota.

## Comandos

```bash
npm run manual                                   # todo: siembra, recorre, escribe y (si hay Gotenberg) PDF
npm run manual:only                              # sólo los recorridos (la base ya está sembrada)
npm run manual:pdf                               # sólo rehacer el PDF con lo ya generado
bash scripts/manual.sh --headed                  # viendo el navegador
bash scripts/manual.sh tests/e2e/manual/01-*.guia.ts   # un recorrido suelto
MANUAL_SKIP_SEED=1 bash scripts/manual.sh        # sin resembrar, para iterar rápido
E2E_MANUAL_PORT=8111 bash scripts/manual.sh      # otro puerto
```

## El PDF, con Gotenberg

`scripts/manual-pdf.mjs` no añade ninguna dependencia npm: `fetch`, `FormData` y
`Blob` son nativos desde Node 20, y el Markdown que interpreta lo escribe
`guia.ts` — el repertorio es corto y conocido (encabezados, párrafos, negritas,
cursivas, código, citas, imágenes y separadores), así que el intérprete cabe en
el propio script.

Las capturas van **embebidas en base64**, aunque Gotenberg también acepte
adjuntos: así el HTML que se le manda es exactamente el que se puede abrir en un
navegador para depurar la maqueta, sin nada que resolver fuera.

El servicio es el mismo que usa el módulo Pdf, y la variable también
(`GOTENBERG_URL`, `http://127.0.0.1:3000` por defecto). Ver
[`../modules/pdf.md`](../modules/pdf.md) § Gotenberg.

```bash
docker run --rm -p 127.0.0.1:3000:3000 gotenberg/gotenberg:8
npm run manual:pdf        # → docs/manual/manual.pdf
```

Sin Gotenberg escuchando, `scripts/manual.sh` **se salta el PDF y lo dice**: el
manual en Markdown ya está completo sin él. Llamar a `npm run manual:pdf` a pelo
sin Gotenberg sí falla con exit 1, que es lo que se espera de un comando que se
pidió a propósito.

## Qué se versiona y qué no

| Ruta | ¿En git? | Por qué |
| --- | --- | --- |
| `tests/e2e/manual/**` | Sí | Es código: los recorridos son la fuente del manual. |
| `docs/manual/README.md` | Sí | Punto de entrada y ejemplo de la salida. Lo regenera el `globalTeardown`. |
| `docs/manual/*.md` | No | Artefacto: se regenera en segundos y su diff sería una pared de texto en cada corrida. |
| `docs/manual/imagenes/` | No | Artefacto, y pesado: veinte capturas a 2x son varios megas. |
| `docs/manual/manual.pdf` | No | Artefacto. |

Consecuencias que conviene saber:

- **R40 vigila `docs/**/*.md` mirando el disco, no git.** Si generas el manual y
  corres `composer arch`, la guía recién escrita tiene que aparecer en
  [`../README.md`](../README.md) aunque esté en `.gitignore`. Por eso el índice
  maestro nombra `manual/01-usuarios.md`: **una guía nueva se añade también ahí**.
- **En el visor de `/docs`** (`DOCS_ENABLED`) `docs/manual/README.md` se ve bien
  y sus enlaces llevan a las guías. Las **imágenes** de una guía, en cambio, no
  se pintan: el visor reescribe los enlaces entre documentos, no las rutas de
  las imágenes, y `docs/manual/imagenes/` no se sirve por HTTP. El manual con
  capturas se lee en el repositorio o en el PDF.

## En CI

[`.github/workflows/manual.yml`](../../.github/workflows/manual.yml) es
`workflow_dispatch`: se lanza a mano desde la pestaña Actions, genera el manual
y sube `docs/manual` como artifact. No se cuelga de ningún push, y **no** intenta
el PDF: Gotenberg es un servicio aparte y el Markdown con sus capturas ya es lo
que hay que revisar.
