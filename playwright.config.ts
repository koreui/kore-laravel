import { defineConfig, devices } from '@playwright/test';

import { baseURL, host, port } from './tests/e2e/support/env';

const isCI = !!process.env.CI;

/**
 * Suite E2E de kore-laravel.
 *
 * Ver `docs/quality/e2e.md` para el porqué de cada decisión (entorno
 * aislado, seeder determinista, storageState por rol).
 */
export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    outputDir: './tests/e2e/results',

    fullyParallel: true,
    forbidOnly: isCI,
    retries: isCI ? 1 : 0,
    workers: isCI ? 2 : undefined,

    // Livewire hace round-trips al servidor: la aserción tiene que poder
    // esperar a que vuelva el HTML, no sólo a que pinte el navegador.
    timeout: 60_000,
    expect: { timeout: 10_000 },

    reporter: [
        ['list'],
        ['html', { outputFolder: './tests/e2e/report', open: 'never' }],
    ],

    use: {
        baseURL,
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        locale: 'es-ES',
        timezoneId: 'UTC',
    },

    projects: [
        {
            // Inicia sesión por la UI real y guarda un storageState por rol.
            name: 'setup',
            testMatch: /auth\.setup\.ts$/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            testMatch: /specs\/.*\.spec\.ts$/,
            use: { ...devices['Desktop Chrome'] },
        },
    ],

    webServer: {
        // APP_ENV viaja al proceso hijo: está en
        // ServeCommand::$passthroughVariables, así que el servidor embebido
        // también lee `.env.e2e`.
        command: `php artisan serve --host=${host} --port=${port}`,
        // `/up` no lleva middleware `web`: responde sin tocar sesión ni base
        // de datos. Importante, porque Playwright arranca el webServer ANTES
        // de globalSetup, que es quien crea y migra la SQLite.
        url: `${baseURL}/up`,
        reuseExistingServer: !isCI,
        timeout: 60_000,
        stdout: 'ignore',
        stderr: 'pipe',
        env: {
            ...process.env,
            APP_ENV: 'e2e',
            // El servidor embebido es monoproceso por defecto y serializaría
            // todas las peticiones de todos los workers.
            PHP_CLI_SERVER_WORKERS: '4',
        },
    },
});
