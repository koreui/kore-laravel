import { expect, test } from '../../fixtures';
import { PERFILES, rolDe, rutasVisiblesPara } from '../../fixtures/access-map';
import { ErrorGuard } from '../../fixtures/error-guard';
import { esperarLivewire } from '../../fixtures/livewire';

/**
 * Smoke: todas las pantallas, con todos los ojos.
 *
 * Por cada perfil abre cada pantalla que el mapa de acceso le concede (las que
 * valen `200`) y comprueba que carga **de verdad**:
 *
 * 1. El servidor responde 200 y la URL final es la pedida (nada de acabar en
 *    el login sin enterarse).
 * 2. El `heading` del mapa está visible: la señal de que montó la pantalla
 *    correcta y no una página de error con buena cara.
 * 3. Livewire terminó de hidratar sin dejar peticiones colgando.
 * 4. No hay claves de traducción sin resolver.
 *
 * Y lo que no se ve: el guardia de `fixtures/index.ts` hace fallar el test si
 * hubo una excepción de JavaScript, un 5xx o un error de consola. Los avisos
 * —4xx esperados, peticiones abortadas— se anotan en el reporte y no tumban
 * nada: una lista de deudas es más útil que cuarenta rojos que nadie mira.
 *
 * Ninguno de estos tests se escribe a mano: salen de `RUTAS` en
 * `fixtures/access-map.ts`.
 */
for (const perfil of PERFILES) {
    const rutas = rutasVisiblesPara(perfil);

    test.describe(`Smoke · ${perfil}`, () => {
        test.use({ role: rolDe(perfil) });

        for (const ruta of rutas) {
            test(`${ruta.path} · ${ruta.nombre}`, async ({ page, errores }, testInfo) => {
                const respuesta = await page.goto(ruta.path);

                expect(respuesta?.status(), `${ruta.path} para ${perfil}`).toBe(200);
                expect(new URL(page.url()).pathname, `${ruta.path} no debería redirigir`).toBe(
                    ruta.path,
                );

                if (ruta.heading !== undefined) {
                    // `first()`: alguna pantalla repite el título en el `<h1>`
                    // del layout y en el `<h3>` de la tarjeta.
                    await expect(
                        page.getByRole('heading', { name: ruta.heading }).first(),
                        `${ruta.path} debería mostrar «${ruta.heading}»`,
                    ).toBeVisible();
                }

                // Livewire monta después del HTML: sin esperarlo, un
                // componente que revienta al hidratarse pasaría inadvertido.
                await esperarLivewire(page);

                /*
                 * Claves de traducción sin resolver.
                 *
                 * Cuando falta el archivo de idioma, Laravel imprime la clave
                 * tal cual: `paquete::archivo.clave`. En prosa no hay texto
                 * normal con `::` en medio, así que es un detector barato que
                 * cubre toda la aplicación, no sólo la pantalla donde se
                 * descubriera. Complementa a `tests/Feature/TranslationsTest.php`,
                 * que sólo ve las claves que la suite de Pest llega a
                 * renderizar.
                 *
                 * **KORE-E2E-001 · Se mira la prosa, no el código.** El visor
                 * de documentación pinta PHP —`Gate::before`, `DB::table`,
                 * `Model::unguard`—, y sobre `/docs/architecture/rules` el
                 * detector cazaba 23 «claves» que eran llamadas estáticas. Se
                 * recortan `pre`, `code`, `script` y `style` de una COPIA del
                 * DOM (la página no se toca) antes de leer el texto.
                 */
                const prosa = await page.evaluate((): string => {
                    const copia = document.body.cloneNode(true) as HTMLElement;

                    for (const nodo of copia.querySelectorAll('pre, code, script, style')) {
                        nodo.remove();
                    }

                    return copia.textContent ?? '';
                });

                const crudas = prosa.match(/[a-z0-9-]+::[a-z0-9_.-]+/gi) ?? [];

                expect(crudas, `${ruta.path} muestra claves de traducción sin traducir`).toEqual(
                    [],
                );

                const avisos = errores.avisos();

                if (avisos.length > 0) {
                    testInfo.annotations.push({
                        type: 'aviso',
                        description: `${ruta.path}\n${ErrorGuard.resumir(avisos)}`,
                    });
                }

                // Los graves los hace fallar el guardia al terminar el test.
            });
        }
    });
}
