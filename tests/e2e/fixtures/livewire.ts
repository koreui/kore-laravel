import type { Page } from '@playwright/test';

/**
 * Esperar a Livewire sin mirar el reloj.
 *
 * Playwright reintenta sus aserciones, así que la mayoría de las veces basta
 * con `expect(...)` sobre el cambio observable. Pero hay tres casos donde no
 * hay nada que observar:
 *
 * 1. Comprobar que algo **desapareció** o que **no** pasó nada.
 * 2. Encadenar dos acciones cuando la segunda sólo existe después de que la
 *    primera repinte.
 * 3. Un `wire:model.live` cuyo re-render no altera un solo píxel (el `select`
 *    de rol del formulario de usuarios es exactamente eso).
 *
 * Para eso está el contador de este archivo: se instala **antes** de cargar la
 * página (`addInitScript`) y lleva la cuenta de las peticiones de Livewire en
 * vuelo usando el hook `request` del propio Livewire. `esperarLivewire()`
 * espera a que llegue a cero. Es lo que haría un `sleep(500)` pero sin apostar
 * — y sin romper R38.
 *
 * Este archivo sustituye a `support/livewire.ts`: los dos helpers que había
 * allí (`waitForLivewireReady` y `withLivewireRoundTrip`) siguen aquí con la
 * misma semántica, para no tocar a quien los llama.
 */

declare global {
    interface Window {
        /** Peticiones de Livewire que salieron y todavía no han vuelto. */
        __livewireEnVuelo?: number;
    }
}

type HookPayload = {
    succeed?: (callback: () => void) => void;
    fail?: (callback: () => void) => void;
};

type LivewireGlobal = {
    initialRenderIsFinished?: boolean;
    all?: () => unknown[];
    hook?: (name: string, callback: (payload: HookPayload) => void) => void;
};

/**
 * Instala el contador de peticiones en vuelo.
 *
 * Lo llama la fixture `page` de `fixtures/index.ts` en cada página nueva, así
 * que ningún spec tiene que acordarse. Tiene que correr **antes** del primer
 * `goto`: `addInitScript` se ejecuta en cada documento nuevo, antes de los
 * scripts de la página.
 */
export async function instrumentarLivewire(page: Page): Promise<void> {
    await page.addInitScript(() => {
        window.__livewireEnVuelo = 0;

        // `livewire:init` es el momento en que `window.Livewire` existe pero
        // todavía no ha arrancado: el único hueco donde un hook llega a
        // tiempo de ver la primera petición.
        document.addEventListener('livewire:init', () => {
            const livewire = (window as unknown as { Livewire?: LivewireGlobal }).Livewire;

            livewire?.hook?.('request', ({ succeed, fail }: HookPayload): void => {
                window.__livewireEnVuelo = (window.__livewireEnVuelo ?? 0) + 1;

                const terminar = (): void => {
                    window.__livewireEnVuelo = Math.max(0, (window.__livewireEnVuelo ?? 1) - 1);
                };

                // Los dos finales posibles. Se descuenta pase lo que pase, o
                // el contador se queda clavado y la espera no vuelve nunca.
                succeed?.(terminar);
                fail?.(terminar);
            });
        });
    });
}

/**
 * Espera a que no quede ninguna petición de Livewire en vuelo.
 *
 * Una página sin Livewire —o que todavía no arrancó— no tiene nada que
 * esperar, así que un timeout aquí **no** es un fallo del test: se traga y se
 * sigue. Lo que de verdad falla es la aserción que venga después.
 */
export async function esperarLivewire(page: Page, timeout = 15_000): Promise<void> {
    await page
        .waitForFunction(() => (window.__livewireEnVuelo ?? 0) === 0, undefined, { timeout })
        .catch(() => {
            /* sin Livewire (o sin arrancar todavía) no hay nada que esperar */
        });
}

/**
 * Espera a que Livewire haya hidratado la página.
 *
 * El HTML del servidor se ve mucho antes de que `@livewireScripts` se ejecute
 * y enganche los `wire:model` / `wire:submit`. En ese hueco, un `fill()` sí
 * escribe en el input pero el evento `input` no lo escucha nadie: la petición
 * nunca sale y el test se queda esperando un round-trip que no existe. Era el
 * flake de «page.waitForResponse: Timeout» al buscar en la tabla de usuarios.
 *
 * `Livewire.initialRenderIsFinished` es la bandera que el propio Livewire
 * levanta al terminar; se comprueba además que haya componentes montados.
 */
export async function waitForLivewireReady(page: Page): Promise<void> {
    await page.waitForFunction(() => {
        const livewire = (window as unknown as { Livewire?: LivewireGlobal }).Livewire;

        return (
            livewire?.initialRenderIsFinished === true &&
            typeof livewire.all === 'function' &&
            livewire.all().length > 0
        );
    });
}

/**
 * Ejecuta una acción y espera a que termine el round-trip de Livewire que
 * dispara.
 *
 * Dos esperas encadenadas, y las dos hacen falta:
 *
 * 1. La respuesta HTTP de `/livewire/update` —que es la que existía desde el
 *    principio y sigue siendo la señal más precisa de que **esa** acción
 *    volvió—.
 * 2. El contador en vuelo a cero, que además cubre las peticiones que la
 *    respuesta encadena (un `$refresh` que dispara el propio componente).
 *
 * Se usa sólo cuando la interacción NO deja un cambio observable en pantalla.
 * Cuando sí lo hay —un toast, una fila nueva, una URL— la aserción
 * correspondiente es siempre mejor: describe la intención, no la fontanería.
 *
 * Nunca `page.waitForTimeout()`: aquí se espera a una respuesta HTTP real.
 */
export async function conRoundTrip(page: Page, accion: () => Promise<void>): Promise<void> {
    await waitForLivewireReady(page);

    const respuesta = page.waitForResponse(
        (res) =>
            res.request().method() === 'POST' && new URL(res.url()).pathname.endsWith('/update'),
    );

    await accion();
    await respuesta;
    await esperarLivewire(page);
}

/**
 * Nombre anterior de {@see conRoundTrip}, con la misma semántica.
 *
 * Se conserva porque los page objects lo llaman así y renombrar una API que
 * ya está citada en media suite no aporta nada.
 */
export const withLivewireRoundTrip = conRoundTrip;
