/**
 * Matriz de control de acceso: qué perfil ve qué pantalla.
 *
 * **Fuente única** de dos specs que no se escriben a mano:
 *
 * - `specs/access/rbac.spec.ts`  — cada ruta × cada perfil: el status (o la
 *   redirección) que el servidor tiene que devolver.
 * - `specs/access/smoke.spec.ts` — cada ruta que da 200 para alguien: que
 *   carga de verdad, que muestra su `heading` y que no revienta por debajo.
 *
 * Añadir una pantalla aquí la cubre en los dos sin escribir un test. Es la
 * forma automática de cumplir R36 («todo módulo con UI aporta smoke +
 * autorización»): la entrada del mapa *es* el test.
 *
 * ## La forma de una entrada es contrato
 *
 * `path` es siempre un **literal entre comillas simples**, absoluto y sin
 * parámetros dinámicos. No es capricho: `kore:arch:check` compara las rutas
 * GET con nombre de los `Routes/web.php` contra estos `path:`, y lee el
 * archivo como texto. Un `path` construido (`` `/users/${id}/edit` ``) o
 * sacado de una constante sería invisible para el check.
 *
 * ## Lo que queda fuera, y por qué
 *
 * - **`/users/{user}/edit`** · lleva parámetro, así que no cabe en un mapa de
 *   literales. Su autorización la cubre `specs/users/edit.spec.ts`, que crea
 *   su propio usuario y entra por la fila de la tabla (que es como se llega en
 *   la aplicación real). Si algún día hiciera falta aquí, la vía sería sembrar
 *   un id fijo en `E2eSeeder` y sustituirlo al construir el test — no
 *   inventarse un id, que daría 404 y probaría otra cosa.
 * - **`/up`**, **`/health/json`** · no son pantallas: son endpoints de
 *   monitorización sin sesión ni HTML. `/up` lo cubre `specs/smoke/landing`.
 * - Los endpoints de ceremonia de Fortify y de passkeys
 *   (`/user/confirm-password`, `/passkeys/login/options`…): no son pantallas
 *   con las que alguien navegue, sino pasos internos de un flujo. Los cubren
 *   los specs de `auth/`.
 */

import type { Role } from '../support/users';

/**
 * Los cinco perfiles: el invitado más las cuatro cuentas de `E2eSeeder`.
 * `null` en la opción `role` de la fixture significa «sin sesión».
 */
export type PerfilAcceso = 'invitado' | Role;

/**
 * Lo que el servidor devuelve al pedir la ruta.
 *
 * Los tres valores de texto son redirecciones, y cada una dice algo distinto:
 *
 * | Valor | Qué significa | Quién lo provoca |
 * | --- | --- | --- |
 * | `'login'` | 302 a `/login` | middleware `auth` |
 * | `'dashboard'` | 302 a `/dashboard` | middleware `guest` de Fortify sobre alguien que ya entró |
 * | `'confirm'` | 302 a `/user/confirm-password` | middleware `password.confirm` |
 *
 * `'dashboard'` y `'confirm'` no estaban previstos y hubo que modelarlos: sin
 * ellos, `/login` para un usuario autenticado y `/user/passkeys` para
 * cualquiera no se pueden describir sin mentir.
 */
export type ResultadoAcceso = 200 | 403 | 404 | 'login' | 'dashboard' | 'confirm';

export type RutaAcceso = {
    /** Ruta absoluta y literal, sin parámetros. */
    path: string;
    /** Texto para el título del test. */
    nombre: string;
    /**
     * El `getByRole('heading')` que prueba que cargó la pantalla correcta y no
     * una página de error con buena cara. Se compara por subcadena, así que
     * basta el trozo estable del título.
     *
     * `undefined` significa «esta pantalla no tiene ningún heading»: es el
     * caso de `/pulse`, cuyo título es un `<span>` (ver el comentario de su
     * entrada).
     */
    heading?: string;
    /** El resultado esperado para cada uno de los cinco perfiles. */
    roles: Record<PerfilAcceso, ResultadoAcceso>;
};

/** Orden en que se recorren los perfiles en los tests generados. */
export const PERFILES: readonly PerfilAcceso[] = [
    'invitado',
    'member',
    'viewer',
    'editor',
    'superadmin',
];

/**
 * Todas las pantallas GET del boilerplate.
 *
 * Los permisos son espejo de `database/seeders/E2eSeeder.php`; las rutas, de
 * `routes/web.php` y de los `Routes/web.php` de cada módulo más lo que
 * publican Fortify, spatie/laravel-health y Laravel Pulse.
 */
export const RUTAS: RutaAcceso[] = [
    {
        path: '/',
        nombre: 'Landing pública',
        heading: 'Boilerplate Laravel',
        roles: {
            invitado: 200,
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        // Las cuatro pantallas de `guest` de Fortify rebotan al dashboard a
        // quien ya tiene sesión. No es un 403: es que no tienen sentido.
        path: '/login',
        nombre: 'Login',
        heading: 'Bienvenido de vuelta',
        roles: {
            invitado: 200,
            member: 'dashboard',
            viewer: 'dashboard',
            editor: 'dashboard',
            superadmin: 'dashboard',
        },
    },
    {
        path: '/register',
        nombre: 'Registro',
        heading: 'Crear tu cuenta',
        roles: {
            invitado: 200,
            member: 'dashboard',
            viewer: 'dashboard',
            editor: 'dashboard',
            superadmin: 'dashboard',
        },
    },
    {
        path: '/forgot-password',
        nombre: 'Recuperar contraseña',
        heading: 'Recuperar contraseña',
        roles: {
            invitado: 200,
            member: 'dashboard',
            viewer: 'dashboard',
            editor: 'dashboard',
            superadmin: 'dashboard',
        },
    },
    {
        /*
         * KORE-E2E-002 · `/magic-link` NO lleva `guest` (se registra en el
         * módulo Auth con `middleware('web')` a secas), así que responde 200
         * también a quien ya entró. Está bien que sea así —pedir un código
         * para la cuenta de otro es un caso real—, pero es una asimetría con
         * las cuatro pantallas de Fortify y por eso se fija aquí.
         */
        path: '/magic-link',
        nombre: 'Entrar con código por email',
        heading: 'Iniciar sesión con código',
        roles: {
            invitado: 200,
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        // `auth` + `verified`, sin permiso: cualquiera que haya entrado lo ve.
        path: '/dashboard',
        nombre: 'Dashboard',
        heading: 'Dashboard',
        roles: {
            invitado: 'login',
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        /*
         * `permission:settings.manage` (módulo Platform). Ninguna de las tres
         * cuentas con rol `Usuario` lo tiene: los ajustes de la instalación son
         * cosa del superadmin y del rol Administrador, que `E2eSeeder` no
         * siembra. Platform NO tiene toggle, así que —a diferencia de
         * `/pdf/preview`— esta pantalla existe siempre.
         */
        path: '/settings',
        nombre: 'Platform · ajustes de la instalación',
        heading: 'Ajustes',
        roles: {
            invitado: 'login',
            member: 403,
            viewer: 403,
            editor: 403,
            superadmin: 200,
        },
    },
    {
        // `permission:users.view` — el `member` del seeder no lo tiene.
        path: '/users',
        nombre: 'Users · listado',
        heading: 'Usuarios',
        roles: {
            invitado: 'login',
            member: 403,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        /*
         * `permission:users.create`. Ojo con el heading: el `<h1>` del layout
         * dice «Nuevo usuario» y el `<h3>` de la tarjeta, «Crear usuario». El
         * que prueba que la PANTALLA cargó es el primero.
         */
        path: '/users/create',
        nombre: 'Users · alta',
        heading: 'Nuevo usuario',
        roles: {
            invitado: 'login',
            member: 403,
            viewer: 403,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        /*
         * `permission:invitations.manage`, que `E2eSeeder` sólo le da al
         * superadmin (por el `Gate::before`, no por un permiso directo). Las
         * dos pantallas existen porque `.env.e2e` enciende `AUTH_INVITATIONS`.
         */
        path: '/invitations',
        nombre: 'Invitaciones · listado',
        heading: 'Invitaciones',
        roles: {
            invitado: 'login',
            member: 403,
            viewer: 403,
            editor: 403,
            superadmin: 200,
        },
    },
    {
        path: '/invitations/create',
        nombre: 'Invitaciones · alta',
        heading: 'Nueva invitación',
        roles: {
            invitado: 'login',
            member: 403,
            viewer: 403,
            editor: 403,
            superadmin: 200,
        },
    },
    {
        /*
         * La pantalla de espera. Es 200 para cualquiera que haya entrado,
         * también para quien ya está activo: es una página informativa, y darle
         * un 403 a quien llega por un enlace viejo sería peor respuesta que
         * enseñarle que su cuenta está bien. Es además una de las rutas libres
         * de `EnsureAccountIsActive`; si no, se redirigiría a sí misma.
         */
        path: '/account/pending',
        nombre: 'Cuenta en revisión',
        heading: 'Tu cuenta está en revisión',
        roles: {
            invitado: 'login',
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        /*
         * Sin `heading`: para nadie autenticado es 200 directo. La ruta lleva
         * `password.confirm`, así que la primera visita de cada sesión rebota
         * a `/user/confirm-password`. Que la pantalla existe y lista vacío lo
         * comprueba `specs/auth/passkeys.spec.ts`, que sí confirma la
         * contraseña antes.
         */
        path: '/user/passkeys',
        nombre: 'Passkeys',
        roles: {
            invitado: 'login',
            member: 'confirm',
            viewer: 'confirm',
            editor: 'confirm',
            superadmin: 'confirm',
        },
    },
    {
        // El visor es público a propósito: los mismos documentos están en
        // GitHub. Quien decide si existe es el toggle `DOCS_ENABLED`.
        path: '/docs',
        nombre: 'Docs · índice',
        heading: 'Documentación de kore-laravel',
        roles: {
            invitado: 200,
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        // Subcadena a propósito: el `<h1>` real lleva el rango de reglas
        // entre paréntesis y crece con el catálogo.
        path: '/docs/architecture/rules',
        nombre: 'Docs · catálogo de reglas',
        heading: 'Reglas de arquitectura',
        roles: {
            invitado: 200,
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        /*
         * `web` + `auth` + `can:viewHealth`, y el gate sólo deja pasar al
         * superadmin (`AuthModuleServiceProvider`). El panel expone versiones,
         * espacio en disco y estado de la base: no es información pública.
         *
         * El heading que pinta spatie/laravel-health es un `<h4>`, no un
         * `<h1>`; `getByRole('heading')` lo encuentra igual.
         */
        path: '/health',
        nombre: 'Health',
        heading: 'Laravel Health',
        roles: {
            invitado: 'login',
            member: 403,
            viewer: 403,
            editor: 403,
            superadmin: 200,
        },
    },
    {
        /*
         * KORE-E2E-003 · Pulse no lleva `auth` en su middleware: su puerta es
         * el gate `viewPulse`, que también es sólo superadmin. Por eso un
         * invitado se lleva 403 y no la redirección al login — es la única
         * ruta del boilerplate que se comporta así, y conviene tenerlo
         * escrito.
         *
         * KORE-E2E-004 · Sin `heading`: el título de la pantalla es un
         * `<span>` con «Laravel Pulse» dentro, no un encabezado. Localizarlo
         * pediría un selector CSS sobre el HTML de un paquete, que cambia sin
         * avisar; el smoke se conforma con que la pantalla monte y no lance
         * nada. CANDIDATO A MEJORA DE ACCESIBILIDAD, pero en el paquete.
         */
        path: '/pulse',
        nombre: 'Pulse',
        roles: {
            invitado: 403,
            member: 403,
            viewer: 403,
            editor: 403,
            superadmin: 200,
        },
    },
    {
        /*
         * `auth` + `verified` y sin `permission:`: una bandeja no es una
         * sección a la que se dé acceso, es algo que todo el mundo tiene. Por
         * eso las dos pantallas del módulo son 200 para los cuatro perfiles
         * autenticados y sólo el invitado rebota al login — es el primer par de
         * entradas del mapa con esa forma, y conviene tenerlo escrito.
         *
         * `NOTIFICATIONS_ENABLED=true` en `.env.e2e`: al revés que el módulo
         * Pdf, encenderlo no cuelga la suite de ningún servicio externo.
         */
        path: '/notifications',
        nombre: 'Notifications · bandeja',
        heading: 'Notificaciones',
        roles: {
            invitado: 'login',
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        path: '/notifications/preferences',
        nombre: 'Notifications · preferencias',
        heading: 'Preferencias de notificación',
        roles: {
            invitado: 'login',
            member: 200,
            viewer: 200,
            editor: 200,
            superadmin: 200,
        },
    },
    {
        // Sólo se registra en `local` (AuthModuleServiceProvider). En E2E el
        // entorno es `e2e`, así que para todo el mundo tiene que ser un 404:
        // esta entrada prueba que el switcher no se filtra fuera de local.
        path: '/dev/switch-account',
        nombre: 'Dev · switcher de cuentas (sólo local)',
        roles: {
            invitado: 404,
            member: 404,
            viewer: 404,
            editor: 404,
            superadmin: 404,
        },
    },
    {
        /*
         * Las dos pantallas del módulo Pdf van con `PDF_ENABLED=false` en
         * `.env.e2e`, así que para todo el mundo son 404 — igual que el
         * switcher de arriba, y por una razón parecida: aquí se prueba que el
         * toggle apagado no deja nada suelto.
         *
         * Y encenderlo no sería gratis. `/pdf/preview/download` convierte la
         * hoja llamando a Gotenberg, que corre en su propio contenedor: en CI
         * no lo hay, así que ese 200 no existiría y la suite entera quedaría
         * colgada de un servicio externo por una pantalla de maquetación. La
         * pantalla y el PDF los cubren los tests de Pest del módulo, que usan
         * `Pdf::fake()` y no necesitan red.
         *
         * El día que un derivado levante Gotenberg en su CI, esto pasa a
         * `superadmin: 200` (el gate `viewPdfPreview` deja entrar también al
         * rol Administrador, que el `E2eSeeder` no siembra) y el resto a 403.
         */
        path: '/pdf/preview',
        nombre: 'Pdf · vista previa del tema (toggle apagado)',
        roles: {
            invitado: 404,
            member: 404,
            viewer: 404,
            editor: 404,
            superadmin: 404,
        },
    },
    {
        path: '/pdf/preview/download',
        nombre: 'Pdf · descarga del ejemplo (toggle apagado)',
        roles: {
            invitado: 404,
            member: 404,
            viewer: 404,
            editor: 404,
            superadmin: 404,
        },
    },
];

/** El resultado que este perfil debe obtener en esta ruta. */
export function resultadoEsperado(perfil: PerfilAcceso, ruta: RutaAcceso): ResultadoAcceso {
    return ruta.roles[perfil];
}

/** Las pantallas que este perfil sí puede abrir: lo que recorre el smoke. */
export function rutasVisiblesPara(perfil: PerfilAcceso): RutaAcceso[] {
    return RUTAS.filter((ruta) => ruta.roles[perfil] === 200);
}

/** El rol de la fixture `role` para un perfil (`null` = invitado). */
export function rolDe(perfil: PerfilAcceso): Role | null {
    return perfil === 'invitado' ? null : perfil;
}
