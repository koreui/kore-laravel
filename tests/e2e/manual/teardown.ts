import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { RAIZ_MANUAL } from './fixtures/rutas';

/**
 * Rehace el índice del manual — corre una vez, al terminar los recorridos.
 *
 * Lee los `.md` que hay en `docs/manual/` y arma `README.md` con su título y su
 * línea de «para quién». Se hace **leyendo la carpeta**, y no la lista de
 * recorridos que acaban de correr, a propósito: quien regenere un recorrido
 * suelto para revisarlo no debe perder del índice los demás.
 *
 * `docs/manual/README.md` es el único archivo del manual que se versiona; las
 * guías y las capturas son artefactos. Ver `docs/quality/manual.md`.
 */
type Entrada = { archivo: string; titulo: string; paraQuien: string };

export default async function manualTeardown(): Promise<void> {
    const carpeta = join(process.cwd(), RAIZ_MANUAL);

    if (!existsSync(carpeta)) {
        return;
    }

    const entradas = readdirSync(carpeta)
        .filter((archivo) => archivo.endsWith('.md') && archivo !== 'README.md')
        .sort()
        .map((archivo): Entrada => {
            const texto = readFileSync(join(carpeta, archivo), 'utf8');

            return {
                archivo,
                titulo: texto.match(/^#\s+(.+)$/m)?.[1]?.trim() ?? archivo.replace(/\.md$/, ''),
                paraQuien: texto.match(/^>\s+(.+)$/m)?.[1]?.trim() ?? '',
            };
        });

    if (entradas.length === 0) {
        return;
    }

    writeFileSync(join(carpeta, 'README.md'), indice(entradas), 'utf8');
}

function indice(entradas: Entrada[]): string {
    const filas = entradas
        .map((entrada) => `| [${entrada.titulo}](./${entrada.archivo}) | ${entrada.paraQuien} |`)
        .join('\n');

    return `# Manual de usuario

Cada guía es un recorrido completo por una parte de la aplicación, explicado
paso a paso y con una captura de pantalla real por paso.

**Las capturas no se toman a mano.** Salen de recorridos ejecutados contra la
aplicación de verdad (\`tests/e2e/manual/*.guia.ts\`), con el navegador pulsando
donde pulsaría una persona. Si la pantalla cambia y el recorrido deja de
encontrar lo que buscaba, la generación falla y el manual avisa — que es la
única forma de que un manual con imágenes no envejezca mintiendo.

> **Este archivo es lo único del manual que se versiona.** Las guías y sus
> capturas son artefactos: se generan en local o en CI y están en \`.gitignore\`.
> Si has llegado aquí desde un clon recién hecho, los enlaces de abajo no
> apuntan a nada todavía: genera el manual y aparecen.

Para generarlo:

\`\`\`bash
npm run manual        # recorridos + capturas + Markdown
npm run manual:pdf    # el manual entero en un PDF (necesita Gotenberg)
\`\`\`

Cómo se escribe una guía y qué hace cada pieza: [\`../quality/manual.md\`](../quality/manual.md).

## Guías

| Guía | Para quién |
|---|---|
${filas}

---

<sub>Todos los nombres y correos que aparecen en las capturas son ficticios y
usan el dominio reservado \`.test\`. Ni un solo dato proviene de una persona
real.</sub>
`;
}
