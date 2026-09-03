import type { Page } from '@playwright/test';

/**
 * Espera a que Livewire haya hidratado la página.
 *
 * El HTML del servidor se ve mucho antes de que `@livewireScripts` se ejecute
 * y enganche los `wire:model` / `wire:submit`. En ese hueco, un `fill()` sí
 * escribe en el input pero el evento `input` no lo escucha nadie: la petición
 * nunca sale y el test se queda esperando un round-trip que no existe. Era el
 * flake de "page.waitForResponse: Timeout" al buscar en la tabla de usuarios.
 *
 * `Livewire.initialRenderIsFinished` es la bandera que el propio Livewire
 * levanta al terminar; se comprueba además que haya componentes montados.
 */
export async function waitForLivewireReady(page: Page): Promise<void> {
    await page.waitForFunction(() => {
        const livewire = (
            window as unknown as {
                Livewire?: { initialRenderIsFinished?: boolean; all?: () => unknown[] };
            }
        ).Livewire;

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
 * Se usa sólo cuando la interacción NO deja un cambio observable en pantalla
 * (el caso típico: un `wire:model.live` cuyo re-render no altera nada
 * visible). Cuando sí lo hay —un toast, una fila nueva, una URL— la aserción
 * correspondiente es siempre mejor: describe la intención, no la fontanería.
 *
 * Nunca `page.waitForTimeout()`: aquí se espera a una respuesta HTTP real.
 */
export async function withLivewireRoundTrip(
    page: Page,
    action: () => Promise<void>,
): Promise<void> {
    await waitForLivewireReady(page);

    const response = page.waitForResponse(
        (res) =>
            res.request().method() === 'POST' && new URL(res.url()).pathname.endsWith('/update'),
    );

    await action();
    await response;
}
