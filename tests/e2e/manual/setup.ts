import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

import { request as peticiones, type FullConfig } from '@playwright/test';

import { IMAGENES, RAIZ_MANUAL, URL_MANUAL } from './fixtures/rutas';

/**
 * Comprobaciones previas del manual — corre una vez, antes de los recorridos.
 *
 * No inicia sesiones ni prepara `storageState` (de eso vive el `globalSetup` de
 * la suite): el manual entra por el formulario a propósito, porque el acceso es
 * el primer paso que hay que enseñar. Aquí sólo se comprueba que del otro lado
 * hay lo que las capturas necesitan, porque un manual generado contra una base
 * a medias sale con pantallas vacías y nadie se entera hasta que lo abre.
 *
 * Los tres candados del harness (`E2E_HARNESS`, `APP_ENV=e2e` y una base de
 * pruebas) los impone `HarnessGuard` del lado de PHP; aquí se repite el tercero
 * en voz alta, porque el manual **crea y edita datos** y equivocarse de base
 * costaría caro. Ver `docs/modules/e2e.md`.
 */
export default async function manualSetup(_config: FullConfig): Promise<void> {
    mkdirSync(join(process.cwd(), RAIZ_MANUAL, IMAGENES), { recursive: true });

    const api = await peticiones.newContext({ baseURL: URL_MANUAL });

    try {
        const ping = await api.get('/__e2e__/ping', { failOnStatusCode: false });

        if (!ping.ok()) {
            throw new Error(
                `El harness no responde en ${URL_MANUAL}/__e2e__/ping (${ping.status()}).\n` +
                    'El manual se genera contra el entorno E2E, que es quien lo publica.\n' +
                    'Lo más simple: bash scripts/manual.sh',
            );
        }

        const info = (await ping.json()) as {
            app: string;
            environment: string;
            database: string;
            users: number;
        };

        if (!/e2e|test/i.test(info.database)) {
            throw new Error(
                `PELIGRO: la aplicación apunta a la base «${info.database}», que no parece de pruebas.\n` +
                    'El manual crea y edita datos: me niego a generarlo aquí.',
            );
        }

        if (info.users === 0) {
            throw new Error(
                `La base «${info.database}» está vacía: no hay ni una cuenta con la que entrar.\n` +
                    'Siémbrala antes de generar el manual:\n' +
                    '  bash scripts/e2e-seed.sh\n' +
                    'O deja que lo haga todo: bash scripts/manual.sh',
            );
        }

        // Los intentos que dejó la corrida anterior harían que el acceso del
        // primer recorrido se comiera un 429, y el manual empezaría con una
        // captura de «demasiados intentos».
        await api.post('/__e2e__/throttle/clear', { data: { keys: [] } });

        process.stdout.write(
            `\n[manual] ${URL_MANUAL} · base «${info.database}» · ${info.users} cuenta(s) · ` +
                `salida en ${RAIZ_MANUAL}/\n\n`,
        );
    } finally {
        await api.dispose();
    }
}
