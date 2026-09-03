import type { ConsoleMessage, Page, Request, Response } from '@playwright/test';

/**
 * Vigilante de errores del navegador y del servidor.
 *
 * El problema que resuelve: **un test puede pasar sobre una pantalla rota**.
 * La aserción comprueba que el toast aparece; nadie mira que, mientras tanto,
 * un componente Alpine lanzó una excepción, que `/livewire/update` devolvió
 * 500 en una petición secundaria o que la consola se llenó de errores. El test
 * queda verde y el bug llega a producción.
 *
 * El guardia se engancha a los cuatro canales que delatan eso:
 *
 * | Canal | Evento de Playwright | Tipo |
 * | --- | --- | --- |
 * | Excepción JS no capturada | `pageerror` | `excepcion` |
 * | `console.error(...)` | `console` (type `error`) | `consola` |
 * | Respuesta HTTP >= 400 | `response` | `http` |
 * | Petición que no llegó a salir | `requestfailed` | `request-fallido` |
 *
 * Y los reparte en dos cubos:
 *
 * - **Graves** (`graves()`): excepción de JS, 5xx del servidor o error de
 *   consola. Es lo que hace fallar el test al terminar, aunque sus aserciones
 *   hayan pasado.
 * - **Avisos** (`avisos()`): 4xx —que muchas veces son intencionados: la
 *   matriz de acceso vive de los 403— y peticiones abortadas. Se anotan en el
 *   reporte y no tumban nada.
 *
 * Lo monta `fixtures/index.ts` en **todos** los tests, los pidan o no. Para
 * apagarlo en un test que provoca el error a propósito, ver `tolerarErrores`.
 */

export type TipoError = 'excepcion' | 'consola' | 'http' | 'request-fallido';

export type ErrorRecogido = {
    readonly tipo: TipoError;
    readonly detalle: string;
    /** Dónde estaba la página (o qué URL falló) cuando se recogió. */
    readonly url: string;
};

/**
 * Ruido conocido: cosas que aparecen en la consola de un Chromium sano y no
 * dicen nada de la salud de la aplicación. **Cada entrada lleva su porqué**;
 * una lista de patrones sin explicación acaba tragándose bugs de verdad.
 */
const RUIDO_CONOCIDO: ReadonlyArray<{ patron: RegExp; porque: string }> = [
    {
        // El boilerplate no publica `public/favicon.ico`, así que el navegador
        // lo pide en cada navegación y se lleva un 404. Es del navegador, no
        // de la aplicación.
        patron: /favicon\.ico/i,
        porque: 'el navegador pide el favicon solo y el boilerplate no publica uno',
    },
    {
        // Una navegación cancela lo que hubiera en vuelo. Con Livewire pasa
        // constantemente: se pulsa «Guardar», el componente redirige y el
        // `fetch` de `/livewire/update` que venía detrás muere a medias.
        patron: /net::ERR_ABORTED/i,
        porque: 'una navegación cancela las peticiones en vuelo (típico de Livewire al redirigir)',
    },
    {
        // La otra cara de lo mismo, vista desde el JS: el `fetch` abortado
        // rechaza su promesa y Livewire lo escupe por consola.
        patron: /(Failed to fetch|The user aborted a request|AbortError|signal is aborted)/i,
        porque: 'el fetch de Livewire cancelado por la navegación rechaza su promesa',
    },
    {
        patron: /chrome-extension:/i,
        porque: 'extensiones del navegador, ajenas a la aplicación',
    },
    {
        // Sólo aparecería si alguien corriera la suite contra `npm run dev`;
        // la suite compila, pero el patrón evita una sorpresa tonta.
        patron: /\[vite\] (connecting|connected)/i,
        porque: 'chatter del cliente de Vite en modo dev',
    },
];

function esRuido(detalle: string): boolean {
    return RUIDO_CONOCIDO.some(({ patron }) => patron.test(detalle));
}

export class ErrorGuard {
    private readonly errores: ErrorRecogido[] = [];

    /** Patrones que este test declara tolerables (ver `tolerarErrores`). */
    private readonly tolerados: RegExp[] = [];

    constructor(page: Page, tolerados: readonly string[] = []) {
        for (const patron of tolerados) {
            this.tolerar(patron);
        }

        this.vigilar(page);
    }

    /**
     * Engancha el guardia a una página más.
     *
     * Las fixtures `asSuperadmin`, `asEditor`… abren su propio contexto y su
     * propia página; sin esto, lo que pasara ahí no lo vería nadie.
     */
    vigilar(page: Page): void {
        page.on('pageerror', (error: Error) => {
            this.push({
                tipo: 'excepcion',
                detalle: `${error.name}: ${error.message}`,
                url: page.url(),
            });
        });

        page.on('console', (msg: ConsoleMessage) => {
            if (msg.type() !== 'error') {
                return;
            }

            // La URL del recurso va en `location()`, no en el texto: sin
            // pegarla, un 404 de favicon llega como «Failed to load resource»
            // a secas y no hay patrón que lo distinga de uno de verdad.
            const donde = msg.location().url;

            this.push({
                tipo: 'consola',
                detalle: donde === '' ? msg.text() : `${msg.text()} — ${donde}`,
                url: page.url(),
            });
        });

        page.on('response', (res: Response) => {
            if (res.status() < 400) {
                return;
            }

            this.push({
                tipo: 'http',
                detalle: `${res.status()} ${res.request().method()} ${res.url()}`,
                url: res.url(),
            });
        });

        page.on('requestfailed', (req: Request) => {
            this.push({
                tipo: 'request-fallido',
                detalle: `${req.method()} ${req.url()} — ${req.failure()?.errorText ?? 'sin detalle'}`,
                url: req.url(),
            });
        });
    }

    /**
     * Declara tolerable todo lo que case con `patron` (subcadena o fuente de
     * expresión regular).
     *
     * Es el opt-out **dentro** de un test; el declarativo para todo un
     * describe es `test.use({ tolerarErrores: ['…'] })`. Cita siempre el
     * identificador del hallazgo que lo justifica:
     *
     *     errores.tolerar('KORE-E2E-001');
     */
    tolerar(patron: string | RegExp): void {
        this.tolerados.push(typeof patron === 'string' ? new RegExp(patron, 'i') : patron);
    }

    private push(error: ErrorRecogido): void {
        if (esRuido(error.detalle)) {
            return;
        }

        this.errores.push(error);
    }

    private esTolerado(error: ErrorRecogido): boolean {
        return this.tolerados.some((patron) => patron.test(error.detalle));
    }

    /** Todo lo recogido, ruido conocido aparte. */
    todos(): ErrorRecogido[] {
        return [...this.errores];
    }

    /**
     * Lo que no se puede dejar pasar: excepciones de JavaScript, respuestas
     * 5xx y errores de consola. Los tolerados quedan fuera.
     */
    graves(): ErrorRecogido[] {
        return this.errores.filter(
            (e) =>
                !this.esTolerado(e) &&
                (e.tipo === 'excepcion' ||
                    e.tipo === 'consola' ||
                    (e.tipo === 'http' && /^5\d\d /.test(e.detalle))),
        );
    }

    /**
     * Lo demás: 4xx (que en la matriz de acceso son el resultado esperado) y
     * peticiones abortadas. Se anotan en el reporte y no tumban el test.
     */
    avisos(): ErrorRecogido[] {
        const graves = new Set(this.graves());

        return this.errores.filter((e) => !graves.has(e));
    }

    /** Lista legible para el mensaje de fallo o para una anotación. */
    static resumir(errores: readonly ErrorRecogido[]): string {
        return errores.map((e) => `  · [${e.tipo}] ${e.detalle}\n    en ${e.url}`).join('\n');
    }

    /**
     * Olvida lo recogido hasta ahora, conservando los patrones tolerados.
     *
     * Para el caso de un montaje que ensucia a propósito antes de que empiece
     * lo que el test quiere medir.
     */
    limpiar(): void {
        this.errores.length = 0;
    }
}
