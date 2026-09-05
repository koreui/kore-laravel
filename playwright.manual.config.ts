import { defineConfig, devices } from '@playwright/test';

/**
 * Proyecto de Playwright del **manual de usuario** — aparte de la suite.
 *
 * Comparte todo lo que importa (la aplicación real, el harness `/__e2e__/*`,
 * las fixtures de `tests/e2e/fixtures`) y cambia lo que un manual necesita y
 * una suite no:
 *
 * - **Un worker y cero reintentos.** Un recorrido se lee en orden; dos a la
 *   vez desordenarían el relato y dejarían capturas de un estado que el texto
 *   todavía no ha contado. Y un reintento reescribiría las imágenes a medias.
 * - **Tema claro y `deviceScaleFactor: 2`**, para que las capturas se vean
 *   nítidas también impresas. El tema se fija por partida doble: aquí, para lo
 *   que el navegador decide por `prefers-color-scheme`, y en `Guia.preparar()`
 *   con el `localStorage` que lee koreUi al arrancar (`kore-theme`).
 * - **Timeout largo**: un recorrido son veinte o treinta pasos con su captura,
 *   no una aserción.
 * - **Sin captura ni vídeo automáticos**: aquí las capturas las decide el
 *   recorrido, una por paso y con nombre.
 *
 * Y levanta **su propio servidor**, en otro puerto (`E2E_MANUAL_PORT`, 8110
 * por defecto). Así el manual y la suite pueden convivir sin que uno reutilice
 * el servidor del otro ni le borre la base a media corrida. El nombre de la
 * aplicación sale de `.env.e2e` (`APP_NAME="kore-laravel"`), que ya es el
 * nombre del producto: no hace falta pisarlo — y no se podría, porque
 * `APP_NAME` no está en `ServeCommand::$passthroughVariables` y no llegaría al
 * servidor embebido.
 *
 *   npm run manual        → siembra, recorre, fotografía y escribe docs/manual/
 *   npm run manual:only   → sólo recorre (la base ya está sembrada)
 *
 * Ver `docs/quality/manual.md`.
 */
const port = process.env.E2E_MANUAL_PORT ?? '8110';
const baseURL = process.env.E2E_MANUAL_URL ?? `http://localhost:${port}`;

// Las fixtures compartidas con la suite resuelven cosas al importarse; fijar
// aquí la URL —y no sólo en `scripts/manual.sh`— hace que `npm run manual:only`
// funcione igual sin pasar por el script.
process.env.E2E_MANUAL_URL ??= baseURL;

export default defineConfig({
    testDir: './tests/e2e/manual',
    testMatch: '**/*.guia.ts',

    globalSetup: './tests/e2e/manual/setup.ts',
    globalTeardown: './tests/e2e/manual/teardown.ts',

    outputDir: './tests/e2e/results-manual',

    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,

    // Un recorrido completo con sus capturas: minutos, no segundos.
    timeout: 10 * 60_000,
    expect: { timeout: 15_000 },

    reporter: [['list']],

    use: {
        baseURL,
        actionTimeout: 20_000,
        navigationTimeout: 30_000,
        locale: 'es-ES',
        timezoneId: 'UTC',

        trace: 'retain-on-failure',
        screenshot: 'off',
        video: 'off',
    },

    projects: [
        {
            name: 'manual',
            use: {
                // El viewport y el `deviceScaleFactor` van DESPUÉS del preset:
                // `devices['Desktop Chrome']` trae los suyos y, declarados
                // arriba, se los llevaría por delante (capturas a 1280×720 y
                // a 1x).
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
                deviceScaleFactor: 2,
                colorScheme: 'light',
            },
        },
    ],

    webServer: {
        command: `php artisan serve --host=localhost --port=${port}`,
        // `/up` no lleva middleware `web`: responde sin tocar sesión ni base de
        // datos, que es lo que hace falta porque Playwright arranca el servidor
        // ANTES del globalSetup.
        url: `${baseURL}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
        stdout: 'ignore',
        stderr: 'pipe',
        env: {
            ...process.env,
            // Está en `ServeCommand::$passthroughVariables`, así que el
            // servidor embebido también carga `.env.e2e`.
            APP_ENV: 'e2e',
            PHP_CLI_SERVER_WORKERS: '4',
        },
    },
});
