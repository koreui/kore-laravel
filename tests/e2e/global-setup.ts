import { execFileSync, execSync } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';

import { authStateDir, databasePath, mailLogPath, repoRoot, viteManifestPath } from './support/env';

const E2E_SEEDER = 'Database\\Seeders\\E2eSeeder';

function log(message: string): void {
    process.stdout.write(`[e2e:setup] ${message}\n`);
}

function run(command: string): void {
    execSync(command, { cwd: repoRoot, stdio: 'inherit' });
}

/**
 * Sin `public/build/manifest.json` cualquier vista con `@vite(...)` lanza
 * ViteException y la suite muere en la primera navegación. Se compila sólo si
 * falta (o si se fuerza con `E2E_BUILD=1`), para no pagar el build en cada
 * corrida local.
 */
function ensureViteManifest(): void {
    if (existsSync(viteManifestPath) && process.env.E2E_BUILD !== '1') {
        log('manifest de Vite presente, no se recompila (E2E_BUILD=1 para forzar).');

        return;
    }

    log('compilando assets con `npm run build`…');
    run('npm run build');
}

/**
 * Base limpia en cada corrida. Se borra el archivo entero en vez de hacer
 * sólo `migrate:fresh` para que un esquema viejo o una SQLite corrupta no
 * sobrevivan.
 */
function resetDatabase(): void {
    for (const suffix of ['', '-shm', '-wal']) {
        rmSync(`${databasePath}${suffix}`, { force: true });
    }

    mkdirSync(path.dirname(databasePath), { recursive: true });
    closeSync(openSync(databasePath, 'w'));

    log(`migrando y sembrando ${path.relative(repoRoot, databasePath)}…`);

    execFileSync(
        'php',
        [
            'artisan',
            'migrate:fresh',
            '--seed',
            `--seeder=${E2E_SEEDER}`,
            '--force',
            '--no-interaction',
        ],
        { cwd: repoRoot, stdio: 'inherit', env: { ...process.env, APP_ENV: 'e2e' } },
    );

    // WAL: varios workers de Playwright leen a la vez mientras uno escribe.
    // El modo queda grabado en el archivo, así que basta con activarlo una vez.
    execFileSync(
        'php',
        ['-r', `$pdo = new PDO("sqlite:" . $argv[1]); $pdo->exec("PRAGMA journal_mode=WAL");`, '--', databasePath],
        { cwd: repoRoot, stdio: 'ignore' },
    );
}

/**
 * El log arranca vacío para que `support/mail-log.ts` no tenga que competir
 * con correos de corridas anteriores al buscar el código del magic link.
 */
function resetMailLog(): void {
    mkdirSync(path.dirname(mailLogPath), { recursive: true });
    writeFileSync(mailLogPath, '');
}

/** `.auth/` se vacía: un storageState viejo apunta a una sesión ya borrada. */
function resetAuthState(): void {
    rmSync(authStateDir, { recursive: true, force: true });
    mkdirSync(authStateDir, { recursive: true });
}

export default function globalSetup(): void {
    ensureViteManifest();
    resetDatabase();
    resetMailLog();
    resetAuthState();
    log('entorno E2E listo.');
}
