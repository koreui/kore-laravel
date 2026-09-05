#!/usr/bin/env node
/**
 * manual-pdf.mjs — Arma el manual entero en un PDF, con Gotenberg.
 *
 * Lee los `docs/manual/*.md` que dejaron los recorridos, los concatena en un
 * **HTML autocontenido** —portada, índice y una sección por guía, con las
 * capturas embebidas en base64— y se lo manda a Gotenberg, que es el mismo
 * servicio que usa el módulo Pdf del boilerplate (`GOTENBERG_URL`, 127.0.0.1:3000
 * por defecto). Ver `docs/modules/pdf.md`.
 *
 * **Sin dependencias nuevas.** `fetch`, `FormData` y `Blob` son nativos desde
 * Node 20, y el Markdown que hay que interpretar lo escribe
 * `tests/e2e/manual/fixtures/guia.ts`: el repertorio es conocido y corto
 * (encabezados, párrafos, negritas, cursivas, código, citas, imágenes y
 * separadores), así que un intérprete de ese subconjunto cabe aquí abajo y no
 * hace falta traerse un paquete de Markdown para seis marcas.
 *
 * **Las imágenes van en base64 y no como archivos adjuntos** aunque Gotenberg
 * acepte las dos formas: así el HTML que se le manda es exactamente el que se
 * puede abrir en un navegador para depurar la maqueta, sin nada que resolver
 * fuera.
 *
 *   node scripts/manual-pdf.mjs
 *   GOTENBERG_URL=http://127.0.0.1:3001 node scripts/manual-pdf.mjs
 */
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { extname, join, resolve } from 'node:path';

const RAIZ = resolve(process.cwd(), 'docs/manual');
const GOTENBERG = process.env.GOTENBERG_URL ?? 'http://127.0.0.1:3000';
const SALIDA = join(RAIZ, 'manual.pdf');

/* ── Leer lo que dejaron los recorridos ─────────────────────────────────── */

if (!existsSync(RAIZ)) {
    console.error(
        `✋ No encuentro ${RAIZ}.\n` + '   Genera antes el manual: npm run manual',
    );
    process.exit(1);
}

const archivos = readdirSync(RAIZ)
    .filter((archivo) => archivo.endsWith('.md') && archivo !== 'README.md')
    .sort();

if (archivos.length === 0) {
    console.error(
        `✋ No hay ninguna guía en ${RAIZ} (sólo cuenta lo que no sea README.md).\n` +
            '   Genera antes el manual: npm run manual',
    );
    process.exit(1);
}

/* ── Markdown → HTML (el subconjunto que escribe guia.ts) ───────────────── */

const escapar = (texto) =>
    texto.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

/**
 * Las marcas de línea: negrita, cursiva y código.
 *
 * El orden importa: el código se saca primero para que un `*` dentro de un
 * `` `…` `` no se lea como cursiva.
 */
function enLinea(texto) {
    return escapar(texto)
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
}

/** Una imagen del Markdown, ya embebida como `data:` URI. */
function imagen(ruta, alt) {
    const archivo = join(RAIZ, ruta);

    if (!existsSync(archivo)) {
        console.warn(`  · falta la captura ${ruta}: la salto.`);

        return '';
    }

    const tipo = extname(archivo) === '.png' ? 'image/png' : 'image/jpeg';
    const datos = readFileSync(archivo).toString('base64');

    return `<figure><img src="data:${tipo};base64,${datos}" alt="${escapar(alt)}"></figure>`;
}

/**
 * Convierte una guía entera.
 *
 * Se trabaja por **bloques** —trozos separados por una línea en blanco—, que es
 * como los escribe `Guia.markdown()`. El `# ` inicial y el `> ` de «para quién»
 * se descartan aquí: los pinta la cabecera de la sección con lo que ya se leyó
 * arriba.
 */
function aHtml(markdown) {
    const bloques = markdown.split(/\n{2,}/);
    const partes = [];
    let primerTitulo = true;
    let primeraCita = true;

    for (const bruto of bloques) {
        const bloque = bruto.trim();

        if (bloque === '' || bloque === '---') {
            continue;
        }

        if (bloque.startsWith('# ')) {
            // El título de la guía sólo se descarta la primera vez.
            if (primerTitulo) {
                primerTitulo = false;
                continue;
            }

            partes.push(`<h2>${enLinea(bloque.slice(2))}</h2>`);
            continue;
        }

        if (bloque.startsWith('>')) {
            // La primera cita del documento es el «para quién», que ya se leyó
            // aparte y se pinta en la cabecera de la sección. Las demás son las
            // aclaraciones que cuelgan de un paso.
            if (primeraCita) {
                primeraCita = false;
                continue;
            }

            partes.push(`<p class="nota">${enLinea(sinCita(bloque))}</p>`);
            continue;
        }

        if (bloque.startsWith('### ')) {
            partes.push(`<h4 class="paso">${enLinea(bloque.slice(4))}</h4>`);
            continue;
        }

        if (bloque.startsWith('## ')) {
            partes.push(`<h3>${enLinea(bloque.slice(3))}</h3>`);
            continue;
        }

        const captura = bloque.match(/^!\[([^\]]*)\]\(([^)]+)\)$/);

        if (captura !== null) {
            partes.push(imagen(captura[2], captura[1]));
            continue;
        }

        if (bloque.startsWith('<sub>')) {
            partes.push(`<p class="pie">${enLinea(despojar(bloque))}</p>`);
            continue;
        }

        partes.push(`<p>${enLinea(bloque).replace(/\n/g, ' ')}</p>`);
    }

    return partes.join('\n');
}

/** Quita los `> ` de una cita y la deja en una línea. */
function sinCita(bloque) {
    return bloque
        .split('\n')
        .map((linea) => linea.replace(/^>\s?/, ''))
        .join(' ')
        .trim();
}

/** Quita las etiquetas del pie (`<sub>…</sub>`), que aquí se pintan con clase. */
function despojar(bloque) {
    return bloque.replace(/<\/?sub>/g, '').replace(/\n/g, ' ').trim();
}

/* ── El HTML ────────────────────────────────────────────────────────────── */

const guias = archivos.map((archivo) => {
    const texto = readFileSync(join(RAIZ, archivo), 'utf8');

    return {
        slug: archivo.replace(/\.md$/, ''),
        titulo: texto.match(/^#\s+(.+)$/m)?.[1]?.trim() ?? archivo,
        paraQuien: texto.match(/^>\s+(.+)$/m)?.[1]?.trim() ?? '',
        cuerpo: aHtml(texto),
    };
});

const indice = guias
    .map(
        (guia, i) =>
            `<li><span class="n">${String(i + 1).padStart(2, '0')}</span>` +
            `<span class="t">${escapar(guia.titulo)}</span>` +
            `<span class="q">${escapar(guia.paraQuien)}</span></li>`,
    )
    .join('\n');

const secciones = guias
    .map(
        (guia) =>
            `<section class="guia" id="${guia.slug}">\n` +
            `<h1>${escapar(guia.titulo)}</h1>\n` +
            `<p class="para-quien">${escapar(guia.paraQuien)}</p>\n` +
            `${guia.cuerpo}\n</section>`,
    )
    .join('\n');

const html = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Manual de usuario</title>
<style>
    @page { size: Letter; margin: 18mm 16mm 20mm; }

    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 10.5pt;
        line-height: 1.55;
        color: #27272a;
    }
    code {
        font-family: "SFMono-Regular", Menlo, Consolas, monospace;
        font-size: .92em;
        background: #f4f4f5;
        padding: .1em .3em;
        border-radius: 2px;
    }

    /* ── Portada ─────────────────────────────────────────────────────── */
    .portada { height: 232mm; display: flex; flex-direction: column; justify-content: center; page-break-after: always; }
    .portada .marca { font-size: 11pt; letter-spacing: .34em; text-transform: uppercase; color: #4338ca; font-weight: 600; }
    .portada h1 { font-size: 34pt; line-height: 1.1; margin: 6mm 0 4mm; font-weight: 700; }
    .portada p { font-size: 12pt; color: #52525b; max-width: 120mm; margin: 0; }
    .portada .aviso { margin-top: 14mm; font-size: 9pt; color: #a1a1aa; max-width: 120mm; }

    /* ── Índice ──────────────────────────────────────────────────────── */
    .indice { page-break-after: always; }
    .indice h2 { font-size: 16pt; margin: 0 0 6mm; }
    .indice ol { list-style: none; margin: 0; padding: 0; }
    .indice li { display: flex; gap: 5mm; align-items: baseline; padding: 3mm 0; border-bottom: .3pt solid #e4e4e7; }
    .indice .n { color: #4338ca; font-weight: 700; width: 10mm; }
    .indice .t { font-weight: 600; flex: 0 0 78mm; }
    .indice .q { color: #71717a; font-size: 9pt; flex: 1; }

    /* ── Guías ───────────────────────────────────────────────────────── */
    .guia { page-break-before: always; }
    .guia h1 { font-size: 21pt; line-height: 1.2; margin: 0 0 2mm; padding-bottom: 3mm; border-bottom: 1.5pt solid #4338ca; }
    .para-quien { color: #4338ca; font-size: 9.5pt; font-weight: 600; margin: 0 0 5mm; }

    h3 { font-size: 14pt; margin: 9mm 0 3mm; padding-top: 4mm; border-top: .5pt solid #e4e4e7; page-break-after: avoid; }
    h4.paso { font-size: 11pt; margin: 6mm 0 2mm; page-break-after: avoid; }

    p { margin: 0 0 2.5mm; }

    /*
       Las capturas vienen a 2x, asi que en pixeles CSS son enormes y hay que
       reducirlas siempre. Con max-width y max-height —y width auto— se reducen
       manteniendo la proporcion y ninguna sale mas alta que la caja de texto:
       una captura de pagina entera mide varios miles de pixeles y con width
       100% se desbordaria de la hoja.
       (Sin acentos: esto viaja dentro de una plantilla de JavaScript.)
    */
    figure { margin: 0 0 6mm; page-break-inside: avoid; }
    figure img {
        display: block;
        margin: 0 auto;
        width: auto;
        max-width: 100%;
        max-height: 190mm;
        border: .4pt solid #e4e4e7;
        border-radius: 1.5mm;
    }

    .nota {
        margin: 0 0 5mm;
        padding-left: 4mm;
        border-left: 1.5pt solid #e4e4e7;
        color: #71717a;
        font-size: 9.5pt;
        font-style: italic;
    }
    .pie { margin-top: 8mm; color: #a1a1aa; font-size: 8.5pt; }
</style>
</head>
<body>

<div class="portada">
    <div class="marca">Manual</div>
    <h1>Manual de usuario</h1>
    <p>Cada guía es un recorrido completo por una parte de la aplicación, explicado paso a paso y con
       una captura de pantalla real por paso.</p>
    <div class="aviso">
        Las capturas no se toman a mano: salen de recorridos ejecutados contra la aplicación, con el
        navegador pulsando donde pulsaría una persona. Todos los nombres y correos son ficticios; ni
        un solo dato proviene de una persona real.
    </div>
</div>

<div class="indice">
    <h2>Contenido</h2>
    <ol>
${indice}
    </ol>
</div>

${secciones}

</body>
</html>`;

/* ── Envío a Gotenberg ──────────────────────────────────────────────────── */

const pie = `<!DOCTYPE html>
<html><head><style>
  body { margin: 0; font-family: -apple-system, Arial, sans-serif; font-size: 7pt; color: #a1a1aa; }
  .pie { width: 100%; padding: 0 16mm; display: flex; justify-content: space-between; }
</style></head>
<body><div class="pie"><span>Manual de usuario</span><span class="pageNumber"></span></div></body>
</html>`;

const cuerpo = new FormData();
cuerpo.append('files', new Blob([html], { type: 'text/html' }), 'index.html');
cuerpo.append('files', new Blob([pie], { type: 'text/html' }), 'footer.html');
cuerpo.append('marginTop', '0.71');
cuerpo.append('marginBottom', '0.79');
cuerpo.append('marginLeft', '0.63');
cuerpo.append('marginRight', '0.63');
cuerpo.append('printBackground', 'true');
cuerpo.append('preferCssPageSize', 'true');

console.log(
    `▶ ${guias.length} guía(s) · ${(html.length / 1024).toFixed(0)} KB de HTML → ${GOTENBERG}`,
);

const arranque = Date.now();
let respuesta;

try {
    respuesta = await fetch(`${GOTENBERG}/forms/chromium/convert/html`, {
        method: 'POST',
        body: cuerpo,
    });
} catch (error) {
    console.error(
        `✋ No pude hablar con Gotenberg en ${GOTENBERG}: ${error.message}\n` +
            '   Levántalo y vuelve a intentarlo:\n' +
            '     docker run --rm -p 127.0.0.1:3000:3000 gotenberg/gotenberg:8\n' +
            '   O apúntame a otro: GOTENBERG_URL=http://otro:3000 npm run manual:pdf',
    );
    process.exit(1);
}

if (!respuesta.ok) {
    console.error(
        `✋ Gotenberg devolvió ${respuesta.status}: ${(await respuesta.text()).slice(0, 600)}`,
    );
    process.exit(1);
}

mkdirSync(RAIZ, { recursive: true });

const pdf = Buffer.from(await respuesta.arrayBuffer());
writeFileSync(SALIDA, pdf);

console.log(
    `✔ ${SALIDA} · ${(pdf.length / 1024 / 1024).toFixed(1)} MB · ` +
        `${((Date.now() - arranque) / 1000).toFixed(1)} s`,
);
