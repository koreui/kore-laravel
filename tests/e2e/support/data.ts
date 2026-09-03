import { randomUUID } from 'node:crypto';

/**
 * Datos únicos por test.
 *
 * La base sólo se resetea en `globalSetup`, así que dos tests que usaran el
 * mismo email chocarían con la regla `unique` del formulario — y peor: uno
 * dependería del orden del otro. Todo dato que un spec cree debe salir de
 * aquí.
 */
function suffix(): string {
    return `${Date.now().toString(36)}-${randomUUID().slice(0, 8)}`;
}

/**
 * `user-mgqz1k-1a2b3c4d@spec.test`
 *
 * Dominio `spec.test` a propósito, distinto del `e2e.test` de las cuentas
 * sembradas: así un `buscar "e2e.test"` en la tabla de usuarios devuelve
 * exactamente las del seeder, sin que se cuelen las que crean otros specs en
 * paralelo.
 */
export function uniqueEmail(prefix = 'user'): string {
    return `${prefix}-${suffix()}@spec.test`;
}

/** `Test User mgqz1k-1a2b3c4d` */
export function uniqueName(prefix = 'Test User'): string {
    return `${prefix} ${suffix()}`;
}

/** Contraseña válida para el formulario de usuarios (min:8 + confirmed). */
export const STRONG_PASSWORD = 'SuperSecret123!';
