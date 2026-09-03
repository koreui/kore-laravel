import { expect, type APIRequestContext, type APIResponse, type Page } from '@playwright/test';

/**
 * Cliente del harness (`/__e2e__/*`): el atrezzo de la suite.
 *
 * La regla de uso, que conviene respetar: **lo que el test prueba se hace por
 * la interfaz; lo que el test da por hecho se monta con el harness.** Un spec
 * del formulario de edición no debería crear el usuario a mano por la pantalla
 * de alta —ya está probada en otro sitio— para llegar al campo que le
 * interesa.
 *
 * ## Estado: el backend vive en `app/Modules/E2E` (v2.1.0)
 *
 * En el boilerplate el harness está siempre disponible con `.env.e2e`. El
 * `test.skip` de `specs/harness` queda como red para un derivado que no haya
 * copiado el módulo: ningún otro spec depende del harness.
 *
 * Si el módulo `app/Modules/E2E` no está (un derivado que no lo copió),
 * `/__e2e__/ping` responde 404 y este cliente lo dice sin drama: `estaDisponible()` devuelve `false` y
 * `specs/harness/harness.spec.ts` se salta entero. **Ningún otro spec de la
 * suite depende todavía del harness**, a propósito: la suite tiene que seguir
 * verde con el módulo apagado.
 *
 * ## El contrato
 *
 * | Endpoint | Cuerpo | Devuelve |
 * | --- | --- | --- |
 * | `GET /__e2e__/ping` | — | `{ok, app, environment, database, users}` |
 * | `POST /__e2e__/login-as` | `{email}` | el usuario |
 * | `POST /__e2e__/logout` | — | `{ok}` |
 * | `POST /__e2e__/users` | `{role, email?, name?, password?, permissions?}` | 201 `{id, email, roles, permissions}` |
 * | `DELETE /__e2e__/users` | `{email}` | `{deleted}` |
 * | `GET /__e2e__/mail/last?to=` | — | `{to, subject, body, otp?}` · 404 si no hay |
 * | `DELETE /__e2e__/mail` | — | `{ok}` |
 * | `POST /__e2e__/artisan` | `{command, arguments?}` | `{exit_code, output}` |
 * | `POST /__e2e__/throttle/clear` | `{keys?: string[]}` | `{ok}` |
 */

export type HarnessPing = {
    readonly ok: boolean;
    readonly app: string;
    readonly environment: string;
    readonly database: string;
    readonly users: number;
};

export type HarnessUser = {
    readonly id: number;
    readonly email: string;
    readonly roles: string[];
    readonly permissions: string[];
};

export type HarnessMail = {
    readonly to: string | null;
    readonly subject: string | null;
    readonly body: string;
    /** Código de 6 dígitos del magic link, si el correo lo lleva. */
    readonly otp?: string | null;
};

export type CrearUsuario = {
    /** Rol de spatie/laravel-permission. Obligatorio. */
    role: string;
    email?: string;
    name?: string;
    password?: string;
    /** Permisos directos, además de los del rol. */
    permissions?: string[];
};

/** Comandos que el harness acepta. Espejo de su lista blanca en PHP. */
export type ComandoPermitido = 'kore:regenerate-permissions' | 'cache:clear';

export class Harness {
    private constructor(
        private readonly api: APIRequestContext,
        private readonly page?: Page,
    ) {}

    /**
     * Construye el harness sobre el contexto de peticiones de una página, para
     * que **comparta cookies con el navegador**. Sin eso, `loginAs()` abriría
     * una sesión que la página no vería.
     */
    static forPage(page: Page): Harness {
        return new Harness(page.request, page);
    }

    /** Sobre un `APIRequestContext` suelto, para lo que no necesita navegador. */
    static forRequest(api: APIRequestContext): Harness {
        return new Harness(api);
    }

    /** Latido: confirma que la suite le está pegando al entorno correcto. */
    async ping(): Promise<HarnessPing> {
        return this.json(await this.api.get('/__e2e__/ping'), 'hacer ping');
    }

    /**
     * ¿Existe el módulo E2E en este árbol?
     *
     * Un 404 significa que las rutas no están registradas (el módulo no está
     * portado, o su toggle está apagado): no es un fallo, es una ausencia.
     * Cualquier otro código sí es un problema y se deja pasar para que el
     * siguiente `ping()` lo cuente con detalle.
     */
    async estaDisponible(): Promise<boolean> {
        const res = await this.api.get('/__e2e__/ping', { failOnStatusCode: false });

        return res.status() !== 404;
    }

    /**
     * Inicia sesión sin pasar por el formulario (el formulario se prueba en
     * `specs/auth/login.spec.ts`).
     *
     * **Antes borra las cookies del contexto**, y no es un detalle: los specs
     * que usan `storageState` comparten la misma cookie de sesión dentro de un
     * worker, así que un `loginAs` sin limpiar cambiaría de usuario esa sesión
     * compartida y con ella la de cualquier test en curso. Fallos difusos, en
     * otro archivo, sin relación aparente con el cambio.
     */
    async loginAs(email: string): Promise<HarnessUser> {
        await this.page?.context().clearCookies();

        return this.json(
            await this.api.post('/__e2e__/login-as', { data: { email } }),
            `entrar como ${email}`,
        );
    }

    async logout(): Promise<void> {
        await this.api.post('/__e2e__/logout');
    }

    /** Crea un usuario con su rol y, si hace falta, permisos directos. */
    async createUser(input: CrearUsuario): Promise<HarnessUser> {
        return this.json(await this.api.post('/__e2e__/users', { data: input }), 'crear usuario');
    }

    /** Borra un usuario sembrado por un test. */
    async deleteUser(email: string): Promise<void> {
        await this.api.delete('/__e2e__/users', { data: { email } });
    }

    /**
     * Último correo enviado, ya desarmado (asunto, cuerpo, código).
     *
     * Pasa siempre el destinatario si lo sabes: la suite corre en paralelo y
     * «el último correo» a secas puede ser el que provocó otro test.
     */
    async lastMail(to?: string): Promise<HarnessMail> {
        return this.json(await this.api.get(this.urlDelBuzon(to)), this.describirBuzon(to));
    }

    /**
     * Espera a que llegue un correo para alguien y lo devuelve.
     *
     * **Sobre R38.** Aquí no se espera al DOM sino a un evento externo —que el
     * servidor termine de mandar un correo—, y no hay nada observable en la
     * página a lo que engancharse. La espera se hace con `expect.poll`, que es
     * el reintento con plazo de Playwright: cada intento es una petición real
     * y en cuanto hay respuesta se corta. No es un `sleep` ciego, que es lo
     * que R38 prohíbe, y por eso no lleva válvula.
     */
    async esperarCorreo(to: string, timeout = 15_000): Promise<HarnessMail> {
        let ultimo: HarnessMail | null = null;

        await expect
            .poll(
                async (): Promise<boolean> => {
                    const res = await this.api.get(this.urlDelBuzon(to), {
                        failOnStatusCode: false,
                    });

                    if (!res.ok()) {
                        return false;
                    }

                    ultimo = (await res.json()) as HarnessMail;

                    return true;
                },
                {
                    timeout,
                    message: `[harness] No llegó ningún correo para «${to}» en ${timeout} ms.`,
                },
            )
            .toBe(true);

        // `poll` sólo devuelve cuando la condición se cumplió, así que aquí
        // `ultimo` ya está. El check es para el compilador, no para el test.
        if (ultimo === null) {
            throw new Error(`[harness] El buzón devolvió un correo vacío para «${to}».`);
        }

        return ultimo;
    }

    /** Vacía el buzón: quien espera «el último correo» no quiere el de antes. */
    async clearMail(): Promise<void> {
        await this.api.delete('/__e2e__/mail');
    }

    /**
     * Olvida los intentos acumulados del limitador de peticiones.
     *
     * La suite entera sale de una IP, así que el cubo del login se agota
     * enseguida y empezarían a caer tests con 429 sin relación con lo que
     * probaban. Que el límite funciona se comprueba aparte, a propósito.
     *
     * Sin `keys` el harness vacía el almacén completo: la clave de Fortify
     * combina correo e IP y no hay forma de enumerarla desde fuera.
     */
    async clearThrottle(keys: string[] = []): Promise<void> {
        await this.api.post('/__e2e__/throttle/clear', { data: { keys } });
    }

    /** Corre un comando de artisan de la lista blanca del harness. */
    async artisan(
        command: ComandoPermitido,
        args: Record<string, unknown> = {},
    ): Promise<{ exit_code: number; output: string }> {
        return this.json(
            await this.api.post('/__e2e__/artisan', { data: { command, arguments: args } }),
            `correr artisan ${command}`,
        );
    }

    private urlDelBuzon(to?: string): string {
        return to === undefined
            ? '/__e2e__/mail/last'
            : `/__e2e__/mail/last?to=${encodeURIComponent(to)}`;
    }

    private describirBuzon(to?: string): string {
        return `leer el último correo${to === undefined ? '' : ` de ${to}`}`;
    }

    /**
     * Un fallo del harness es un fallo del **montaje**, no de lo que el test
     * quería probar. Se marca en el mensaje para no perder media hora buscando
     * el bug en el sitio equivocado.
     */
    private async json<T>(res: APIResponse, accion: string): Promise<T> {
        if (!res.ok()) {
            const cuerpo = await res.text();

            throw new Error(
                `[harness] Falló el montaje al ${accion}: ${res.status()} ${res.statusText()}\n` +
                    cuerpo.slice(0, 800),
            );
        }

        return (await res.json()) as T;
    }
}
