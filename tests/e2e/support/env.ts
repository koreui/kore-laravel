import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Raíz del repo (tests/e2e/support → ../../..). */
export const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

const envFile = path.join(repoRoot, '.env.e2e');

/**
 * Lector mínimo de `.env.e2e`.
 *
 * No usamos `dotenv`: la única dependencia npm que este workstream puede
 * añadir es `@playwright/test`. El formato del archivo es plano
 * (`CLAVE=valor`, comillas opcionales, `#` para comentarios) y eso es todo
 * lo que necesitamos leer desde Node.
 */
function parseEnvFile(file: string): Record<string, string> {
    if (!existsSync(file)) {
        throw new Error(
            `No existe ${file}. Es un archivo commiteado del repo: recupéralo con \`git checkout .env.e2e\`.`,
        );
    }

    const result: Record<string, string> = {};

    for (const rawLine of readFileSync(file, 'utf8').split('\n')) {
        const line = rawLine.trim();

        if (line === '' || line.startsWith('#')) {
            continue;
        }

        const separator = line.indexOf('=');

        if (separator === -1) {
            continue;
        }

        const key = line.slice(0, separator).trim();
        let value = line.slice(separator + 1).trim();

        // Un comentario al final de la línea sólo cuenta fuera de comillas.
        if (!value.startsWith('"') && !value.startsWith("'")) {
            const comment = value.indexOf(' #');
            if (comment !== -1) {
                value = value.slice(0, comment).trim();
            }
        }

        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        result[key] = value;
    }

    return result;
}

export const e2eEnv = parseEnvFile(envFile);

/** URL base de la app bajo test, tal y como la declara `.env.e2e`. */
export const baseURL = (e2eEnv.APP_URL ?? 'http://localhost:8010').replace(/\/+$/, '');

const url = new URL(baseURL);

export const host = url.hostname;
export const port = url.port !== '' ? Number(url.port) : 80;

/** Ruta absoluta a la SQLite dedicada de E2E. */
export const databasePath = path.resolve(repoRoot, e2eEnv.DB_DATABASE ?? 'database/e2e.sqlite');

/**
 * Log donde `MAIL_MAILER=log` escribe los emails (magic link).
 *
 * Desde que `.env.e2e` define `MAIL_LOG_CHANNEL=e2e_mail`, el correo va a su
 * propio archivo (`config/logging.php` → canal `e2e_mail`) y no a
 * `laravel.log`. Es el MISMO archivo que sirve `GET /__e2e__/mail/last`, así
 * que el lector de Node y el del harness ven lo mismo.
 */
export const mailLogPath = path.join(repoRoot, 'storage', 'logs', 'e2e-mail.log');

/** Manifest de Vite: sin él, cualquier vista con `@vite(...)` revienta. */
export const viteManifestPath = path.join(repoRoot, 'public', 'build', 'manifest.json');

/** Carpeta con los `storageState` que produce el proyecto `setup`. */
export const authStateDir = path.join(repoRoot, 'tests', 'e2e', '.auth');
