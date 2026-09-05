import { existsSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { expect, type Locator, type Page, type TestInfo } from '@playwright/test';

import { esperarLivewire, type Harness, test as suite } from '../../fixtures';
import { IMAGENES, RAIZ_MANUAL } from './rutas';

/**
 * El `test` del manual: un recorrido que se cuenta y se fotografía.
 *
 * La suite E2E y el manual quieren cosas opuestas. Un **test** va al grano: usa
 * el harness `/__e2e__/*` para saltarse los once pasos previos y probar el
 * doce, y eso es una virtud. Un **manual** tiene que enseñar todos los pasos,
 * incluso los aburridos, en orden y con un texto que explique qué se ve.
 *
 * Por eso los recorridos viven aparte (`tests/e2e/manual/*.guia.ts`) en vez de
 * instrumentar los specs — pero reutilizan la misma infraestructura: el mismo
 * `test` de `tests/e2e/fixtures` con su guardia de errores, el mismo harness y
 * el mismo `esperarLivewire()`. **Si la pantalla cambia, el recorrido se rompe
 * y el manual avisa**, que es la única forma de que un manual con capturas no
 * envejezca mintiendo.
 *
 * Se escribe así:
 *
 *     recorrido(
 *         { slug: '01-usuarios', titulo: '…', paraQuien: '…', introduccion: '…' },
 *         async ({ page, guia }) => {
 *             await guia.capitulo('Entrar al sistema');
 *             await guia.paso('Abre la aplicación', 'Escribe la dirección…', () => page.goto('/login'));
 *             await guia.senalar('Entra', 'Pulsa «Entrar».', boton);
 *         },
 *     );
 *
 * Y al terminar deja `docs/manual/01-usuarios.md` con sus capturas en
 * `docs/manual/imagenes/`.
 *
 * ## Sobre R38
 *
 * Aquí tampoco hay `waitForTimeout()`. Lo que un manual necesita antes de
 * disparar la foto no es tiempo sino **un fotograma pintado**: `reposar()`
 * espera a Livewire, a la red y a las fuentes, y remata con un doble
 * `requestAnimationFrame`, que es la señal de que el navegador ya repintó.
 */

/** Cabecera del recorrido: lo que va arriba del Markdown. */
export type MetaGuia = {
    /** Nombre del archivo y prefijo de sus capturas: `01-usuarios`. */
    slug: string;
    titulo: string;
    /** Una línea que diga a quién le sirve este recorrido. */
    paraQuien: string;
    /** Párrafo de entrada: qué se va a ver y de qué se parte. */
    introduccion: string;
};

export type OpcionesPaso = {
    /** Captura la página entera, no sólo lo que se ve. */
    completa?: boolean;
    /** Captura únicamente este elemento (una tarjeta, una tabla). */
    recortar?: Locator;
    /** Aclaración que va bajo la imagen, en cursiva. */
    nota?: string;
    /** Paso sin imagen: sólo texto (un aviso, una aclaración). */
    sinCaptura?: boolean;
};

type Bloque =
    | { tipo: 'capitulo'; titulo: string; entradilla?: string }
    | { tipo: 'nota'; texto: string }
    | {
          tipo: 'paso';
          numero: number;
          titulo: string;
          texto: string;
          imagen: string | null;
          nota?: string;
      };

/**
 * Lo que se esconde de las capturas: cosas que cambian entre corridas o que
 * invitan a tocar lo que el manual da por fijado.
 *
 * Hoy sólo el conmutador de tema —el manual va siempre en claro y verlo invita
 * a cambiarlo—. Un selector que no case con nada es inofensivo: la lista puede
 * crecer sin comprobar si el elemento existe en esta pantalla.
 */
const DISTRACCIONES = [
    '[role="radiogroup"][aria-label="Tema"]',
    '[aria-label="Cambiar entre claro y oscuro"]',
].join(', ');

const CSS_DE_CAPTURA = `
    ${DISTRACCIONES} { visibility: hidden !important; }

    /* Nada en movimiento cuando se dispara la foto. */
    *, *::before, *::after {
        animation-duration: 0s !important;
        animation-delay: 0s !important;
        transition-duration: 0s !important;
        transition-delay: 0s !important;
    }

    /* El cursor de texto parpadea: en una captura sale o no sale, al azar. */
    * { caret-color: transparent !important; }

    html { scroll-behavior: auto !important; }
`;

export class Guia {
    private readonly bloques: Bloque[] = [];

    private numero = 0;

    private cerrada = false;

    constructor(
        private readonly meta: MetaGuia,
        private readonly page: Page,
        private readonly info: TestInfo,
    ) {}

    /**
     * Deja el navegador listo para fotografiar.
     *
     * La llama `recorrido()` **antes de la primera navegación**, y ahí es donde
     * tiene que correr: koreUi lee el tema de `localStorage` al arrancar la
     * página, así que ponerlo después llegaría tarde y las capturas saldrían
     * claras u oscuras según el sistema de quien las genere.
     */
    async preparar(): Promise<void> {
        await this.page.addInitScript(
            ({ css }: { css: string }) => {
                try {
                    localStorage.setItem('kore-theme', 'light');
                } catch {
                    // Página sin acceso a localStorage: no es un fallo.
                }

                const poner = (): void => {
                    // El script corre antes de que se parsee el HTML: en la
                    // primera pasada puede no haber ni `documentElement`. Se
                    // intenta, y si no lo hay lo hará el DOMContentLoaded.
                    const raiz = document.head ?? document.documentElement;

                    if (raiz === null || document.getElementById('__manual_css__') !== null) {
                        return;
                    }

                    const style = document.createElement('style');
                    style.id = '__manual_css__';
                    style.textContent = css;
                    raiz.appendChild(style);
                };

                poner();
                document.addEventListener('DOMContentLoaded', poner);
            },
            { css: CSS_DE_CAPTURA },
        );

        this.limpiarCapturasAnteriores();
    }

    /** Abre un apartado del manual. Los pasos siguientes cuelgan de él. */
    async capitulo(titulo: string, entradilla?: string): Promise<void> {
        this.bloques.push({ tipo: 'capitulo', titulo, entradilla });
    }

    /**
     * Un párrafo sin imagen, para lo que hay que contar y no se ve en pantalla.
     *
     * **Esto lo lee quien usa el sistema, no quien lo construye.** Nada de
     * deudas técnicas, nombres de clases ni explicaciones de por qué la
     * pantalla es como es; eso va a `tests/e2e/HALLAZGOS.md`, que es donde
     * sirve de algo.
     */
    async nota(texto: string): Promise<void> {
        this.bloques.push({ tipo: 'nota', texto });
    }

    /**
     * Un paso: se hace lo que dice y se fotografía **el resultado**.
     *
     * Sin acción es sólo una foto de lo que hay, que sirve para detenerse a
     * explicar una pantalla.
     */
    async paso(
        titulo: string,
        texto: string,
        accion?: () => Promise<void>,
        opciones: OpcionesPaso = {},
    ): Promise<void> {
        if (accion) {
            await accion();
        }

        await this.reposar();
        await this.anotar(titulo, texto, opciones);
    }

    /**
     * Un paso que **señala dónde hay que pulsar**.
     *
     * Al revés que `paso()`: primero dibuja el halo sobre el elemento y dispara
     * la foto, y después ejecuta la acción. Así la imagen enseña el botón que
     * el texto manda pulsar, que es lo que el lector necesita ver — el
     * resultado ya saldrá en el paso siguiente.
     *
     * Sin acción propia, pulsa el elemento señalado.
     *
     * **Cuidado con el orden**: el objetivo tiene que estar en pantalla *antes*
     * de la acción. Si lo que hace la acción es cambiar de pantalla, el
     * objetivo todavía no existe cuando se busca — ese cambio va en un `paso()`
     * anterior.
     */
    async senalar(
        titulo: string,
        texto: string,
        objetivo: Locator,
        accion?: () => Promise<void>,
        opciones: OpcionesPaso = {},
    ): Promise<void> {
        await expect(
            objetivo,
            `No encuentro en pantalla lo que el paso «${titulo}» quiere señalar.`,
        ).toBeVisible();

        await this.reposar();
        await this.dibujarHalo(objetivo, opciones.completa === true);

        try {
            await this.anotar(titulo, texto, opciones);
        } finally {
            await this.borrarHalo();
        }

        if (accion) {
            await accion();
        } else {
            await objetivo.click();
        }

        await esperarLivewire(this.page);
    }

    /**
     * Escribe `docs/manual/{slug}.md`.
     *
     * La llama `recorrido()` al terminar, así que un recorrido no tiene que
     * acordarse. Llamarla a mano también vale —es idempotente— para el caso de
     * querer cerrar el documento antes de un último bloque de comprobaciones.
     */
    async cerrar(): Promise<void> {
        if (this.cerrada) {
            return;
        }

        this.cerrada = true;

        const carpeta = join(process.cwd(), RAIZ_MANUAL);
        mkdirSync(carpeta, { recursive: true });

        const archivo = join(carpeta, `${this.meta.slug}.md`);
        writeFileSync(archivo, this.markdown(), 'utf8');

        this.info.annotations.push({
            type: 'manual',
            description: `${RAIZ_MANUAL}/${this.meta.slug}.md`,
        });
    }

    /* ── Cocina ────────────────────────────────────────────────────────── */

    private get carpetaImagenes(): string {
        return join(process.cwd(), RAIZ_MANUAL, IMAGENES);
    }

    /**
     * Borra las capturas de ESTE recorrido, no las de todos.
     *
     * Las imágenes viven planas en `docs/manual/imagenes/` con el slug del
     * recorrido por delante (`01-usuarios-03.png`), así que quien regenere un
     * recorrido suelto no se lleva por delante las de los demás.
     */
    private limpiarCapturasAnteriores(): void {
        mkdirSync(this.carpetaImagenes, { recursive: true });

        if (!existsSync(this.carpetaImagenes)) {
            return;
        }

        for (const archivo of readdirSync(this.carpetaImagenes)) {
            if (archivo.startsWith(`${this.meta.slug}-`) && archivo.endsWith('.png')) {
                rmSync(join(this.carpetaImagenes, archivo), { force: true });
            }
        }
    }

    /**
     * Espera a que la pantalla se quede quieta.
     *
     * Cuatro cosas se mueven después de una acción y las cuatro salen borrosas
     * o a medias en una captura: la petición de Livewire, la red de fondo, las
     * fuentes web —que hasta que cargan pintan el texto con otra tipografía— y
     * el último repintado del navegador.
     *
     * Ninguna espera mira el reloj (R38): la última es un doble
     * `requestAnimationFrame`, que resuelve en el fotograma siguiente al
     * repintado y no en un plazo apostado.
     */
    private async reposar(): Promise<void> {
        await esperarLivewire(this.page);

        await this.page.waitForLoadState('networkidle').catch(() => {
            // Una página con algo abierto en segundo plano nunca llega a estar
            // ociosa: no es motivo para parar.
        });

        // `document.fonts.ready` resuelve al propio FontFaceSet, que no se
        // puede serializar de vuelta: se devuelve un booleano.
        await this.page.evaluate(() => document.fonts.ready.then(() => true)).catch(() => undefined);

        await this.esperarRepintado();
    }

    /** Un fotograma pintado, que es lo que hace falta antes de la foto. */
    private async esperarRepintado(): Promise<void> {
        await this.page
            .evaluate(
                () =>
                    new Promise<void>((resolve) => {
                        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
                    }),
            )
            .catch(() => undefined);
    }

    private async anotar(titulo: string, texto: string, opciones: OpcionesPaso): Promise<void> {
        this.numero += 1;

        const imagen = opciones.sinCaptura === true ? null : await this.capturar(opciones);

        this.bloques.push({
            tipo: 'paso',
            numero: this.numero,
            titulo,
            texto,
            imagen,
            nota: opciones.nota,
        });
    }

    /** `docs/manual/imagenes/01-usuarios-03.png`, y devuelve su ruta relativa. */
    private async capturar(opciones: OpcionesPaso): Promise<string> {
        const nombre = `${this.meta.slug}-${String(this.numero).padStart(2, '0')}.png`;
        const ruta = join(this.carpetaImagenes, nombre);

        if (opciones.recortar) {
            await opciones.recortar.screenshot({ path: ruta });
        } else {
            await this.page.screenshot({ path: ruta, fullPage: opciones.completa === true });
        }

        return `${IMAGENES}/${nombre}`;
    }

    /**
     * Rodea el elemento con un halo y atenúa el resto.
     *
     * El posicionamiento depende del tipo de captura, y no da igual: las
     * coordenadas de `boundingBox()` son relativas a lo que se ve, así que para
     * una foto del viewport basta `fixed`; para una de página completa, que se
     * compone con la página desplazada, hay que pasarlas a coordenadas del
     * documento.
     */
    private async dibujarHalo(objetivo: Locator, paginaCompleta: boolean): Promise<void> {
        await objetivo.scrollIntoViewIfNeeded();
        await this.esperarRepintado();

        const caja = await objetivo.boundingBox();

        if (caja === null) {
            return;
        }

        await this.page.evaluate(
            ({
                caja,
                absoluto,
            }: {
                caja: { x: number; y: number; width: number; height: number };
                absoluto: boolean;
            }) => {
                const halo = document.createElement('div');
                halo.id = '__manual_halo__';

                Object.assign(halo.style, {
                    position: absoluto ? 'absolute' : 'fixed',
                    left: `${caja.x + (absoluto ? window.scrollX : 0) - 6}px`,
                    top: `${caja.y + (absoluto ? window.scrollY : 0) - 6}px`,
                    width: `${caja.width + 12}px`,
                    height: `${caja.height + 12}px`,
                    border: '3px solid #f97316',
                    borderRadius: '12px',
                    boxShadow: '0 0 0 4px rgba(249,115,22,.25), 0 0 0 9999px rgba(15,23,42,.20)',
                    pointerEvents: 'none',
                    zIndex: '2147483647',
                });

                document.body.appendChild(halo);
            },
            { caja, absoluto: paginaCompleta },
        );
    }

    private async borrarHalo(): Promise<void> {
        await this.page.evaluate(() => document.getElementById('__manual_halo__')?.remove());
    }

    private markdown(): string {
        const lineas: string[] = [
            `# ${this.meta.titulo}`,
            '',
            `> ${this.meta.paraQuien}`,
            '',
            this.meta.introduccion,
            '',
        ];

        for (const bloque of this.bloques) {
            if (bloque.tipo === 'capitulo') {
                lineas.push('---', '', `## ${bloque.titulo}`, '');

                if (bloque.entradilla !== undefined) {
                    lineas.push(bloque.entradilla, '');
                }

                continue;
            }

            if (bloque.tipo === 'nota') {
                lineas.push(bloque.texto, '');

                continue;
            }

            lineas.push(`### ${bloque.numero}. ${bloque.titulo}`, '', bloque.texto, '');

            if (bloque.imagen !== null) {
                lineas.push(`![Paso ${bloque.numero}](${bloque.imagen})`, '');
            }

            if (bloque.nota !== undefined) {
                // La aclaración va como cita y no como cursiva: un texto que
                // empieza por una negrita («**Administrador** puede…») dejaría
                // `***Administrador**…`, que ni se lee ni hay parser que lo
                // desambigüe de un párrafo entero en negrita.
                lineas.push(citar(bloque.nota), '');
            }
        }

        lineas.push(
            '---',
            '',
            `<sub>Generado el ${fechaDeHoy()} desde \`tests/e2e/manual/${this.meta.slug}.guia.ts\`, ` +
                'ejecutado contra la aplicación real. Para regenerarlo: `npm run manual`. ' +
                'Todos los datos son ficticios.</sub>',
            '',
        );

        return lineas.join('\n');
    }
}

/** Contexto que recibe el cuerpo de un recorrido. */
export type ContextoGuia = {
    page: Page;
    harness: Harness;
    guia: Guia;
};

/**
 * Declara un recorrido del manual.
 *
 * Es **un solo test**, largo y en orden, en vez de una tanda de tests
 * independientes: el manual es una narración y cada paso da por hecho lo que
 * dejó el anterior.
 */
export function recorrido(meta: MetaGuia, cuerpo: (ctx: ContextoGuia) => Promise<void>): void {
    suite(meta.titulo, async ({ page, harness }, info) => {
        const guia = new Guia(meta, page, info);

        await guia.preparar();
        await cuerpo({ page, harness, guia });
        await guia.cerrar();
    });
}

/** Un texto como cita de Markdown, línea a línea. */
function citar(texto: string): string {
    return texto
        .split('\n')
        .map((linea) => (linea.trim() === '' ? '>' : `> ${linea}`))
        .join('\n');
}

/** `4 de septiembre de 2026` */
function fechaDeHoy(): string {
    return new Intl.DateTimeFormat('es-ES', { dateStyle: 'long', timeZone: 'UTC' }).format(
        new Date(),
    );
}

export { expect } from '@playwright/test';
