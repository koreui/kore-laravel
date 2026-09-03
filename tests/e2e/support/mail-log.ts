import { openSync, readSync, closeSync, existsSync, statSync } from 'node:fs';

import { mailLogPath } from './env';

/**
 * Lectura del último código OTP (magic link) desde el log de correo.
 *
 * `.env.e2e` usa `MAIL_MAILER=log` con `MAIL_LOG_CHANNEL=e2e_mail`, así que
 * cada email se escribe entero en `storage/logs/e2e-mail.log` — el mismo
 * archivo que sirve `GET /__e2e__/mail/last`. El mensaje que manda
 * spatie/laravel-one-time-passwords trae el código en tres sitios; usamos los
 * dos que no dependen del HTML:
 *
 *   To: member@e2e.test
 *   Subject: 816155 is your one-time login code
 *   ...
 *   **816155**            ← parte text/plain del markdown mail
 *
 * Para que sea determinista NO se busca "la última aparición del archivo":
 * el spec anota el tamaño del log ANTES de pedir el código y sólo se leen los
 * bytes añadidos después. Así ni un correo viejo ni otro worker en paralelo
 * pueden colarse.
 */

/** Tamaño actual del log. Anótalo antes de disparar el envío. */
export function mailLogOffset(): number {
    if (!existsSync(mailLogPath)) {
        return 0;
    }

    return statSync(mailLogPath).size;
}

function readSince(offset: number): string {
    if (!existsSync(mailLogPath)) {
        return '';
    }

    const size = statSync(mailLogPath).size;

    if (size <= offset) {
        return '';
    }

    const length = size - offset;
    const buffer = Buffer.alloc(length);
    const fd = openSync(mailLogPath, 'r');

    try {
        readSync(fd, buffer, 0, length, offset);
    } finally {
        closeSync(fd);
    }

    return buffer.toString('utf8');
}

/**
 * Código de 6 dígitos del último correo dirigido a `email` dentro de los
 * bytes escritos después de `offset`. `null` si aún no ha llegado.
 */
export function findOtpCode(email: string, offset: number): string | null {
    const appended = readSince(offset);
    const marker = `To: ${email}`;
    const start = appended.lastIndexOf(marker);

    if (start === -1) {
        return null;
    }

    const block = appended.slice(start);

    // Parte text/plain del markdown mail: **816155**
    const inBody = block.match(/\*\*(\d{6})\*\*/);

    if (inBody) {
        return inBody[1];
    }

    // Fallback: el asunto también lo lleva.
    const inSubject = block.match(/^Subject:.*?(\d{6})/m);

    return inSubject ? inSubject[1] : null;
}

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Espera a que el código aparezca en el log.
 *
 * Es polling de un archivo, no del navegador: la regla de "nada de
 * `waitForTimeout`" habla del DOM, que sí tiene auto-waiting. Aquí no hay
 * otra señal que observar.
 */
export async function waitForOtpCode(
    email: string,
    offset: number,
    timeoutMs = 15_000,
): Promise<string> {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        const code = findOtpCode(email, offset);

        if (code !== null) {
            return code;
        }

        await sleep(150);
    }

    throw new Error(
        `No apareció ningún código OTP para ${email} en ${mailLogPath} tras ${timeoutMs} ms ` +
            '(¿MAIL_MAILER=log y MAIL_LOG_CHANNEL=e2e_mail en .env.e2e?).',
    );
}
