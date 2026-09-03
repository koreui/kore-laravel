import { expect, test } from '../../fixtures';
import { PERFILES, RUTAS, rolDe, type ResultadoAcceso } from '../../fixtures/access-map';
import { baseURL } from '../../support/env';

/**
 * Control de acceso: la matriz completa perfil × pantalla.
 *
 * Ningún test de este archivo se escribe a mano: salen de `RUTAS` en
 * `fixtures/access-map.ts`. Una pantalla nueva en el mapa aparece aquí sola,
 * con sus cinco perfiles.
 *
 * Es el spec más aburrido de leer y el más útil de tener: un `permission:`
 * que se olvida en una ruta nueva no se nota en ninguna pantalla — sólo aquí.
 *
 * ## Por qué `page.request` y no `page.goto`
 *
 * Lo que se comprueba es lo que **devuelve el servidor**, no lo que pinta el
 * navegador (de eso se ocupa `smoke.spec.ts`). Con `page.request.get()`:
 *
 * - Se reutilizan las cookies del contexto, así que el perfil es el correcto.
 * - Con `maxRedirects: 0` se ve la redirección **cruda**, con su `Location`.
 *   Un `goto` la seguiría y el 302 a `/login` acabaría siendo un 200 de la
 *   pantalla de login: la misma respuesta que un 200 legítimo.
 * - No se carga HTML ni Livewire, así que 70 comprobaciones cuestan lo que
 *   una pantalla.
 *
 * Se comprueba el **status**, nunca un texto de la página de error: es lo que
 * devuelve el middleware y no depende del idioma ni del diseño de la vista.
 */

/** Adónde manda cada redirección esperada. */
const DESTINO: Readonly<Record<'login' | 'dashboard' | 'confirm', string>> = {
    login: '/login',
    dashboard: '/dashboard',
    confirm: '/user/confirm-password',
};

function describirEsperado(esperado: ResultadoAcceso): string {
    return typeof esperado === 'number'
        ? `${esperado}`
        : `302 → ${DESTINO[esperado]}`;
}

for (const perfil of PERFILES) {
    test.describe(`Acceso · ${perfil}`, () => {
        test.use({ role: rolDe(perfil) });

        for (const ruta of RUTAS) {
            const esperado = ruta.roles[perfil];

            test(`${ruta.path} → ${describirEsperado(esperado)}`, async ({ page }) => {
                const respuesta = await page.request.get(ruta.path, {
                    maxRedirects: 0,
                    failOnStatusCode: false,
                });

                if (typeof esperado === 'number') {
                    expect(
                        respuesta.status(),
                        `${perfil} en ${ruta.path} (${ruta.nombre})`,
                    ).toBe(esperado);

                    return;
                }

                expect(
                    respuesta.status(),
                    `${perfil} en ${ruta.path} debería redirigir a ${DESTINO[esperado]}`,
                ).toBe(302);

                // Laravel manda la URL absoluta en `Location`; el `baseURL`
                // está por si algún día llegara relativa. Sólo importa el
                // camino: el host y el puerto son los del `.env.e2e`.
                const destino = new URL(respuesta.headers().location ?? '', baseURL).pathname;

                expect(destino, `${perfil} en ${ruta.path} (${ruta.nombre})`).toBe(
                    DESTINO[esperado],
                );
            });
        }
    });
}
