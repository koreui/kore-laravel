import { fileURLToPath } from 'node:url';

import { expect } from '@playwright/test';

import { esperarLivewire, SEEDED_USERS } from '../fixtures';
import { DashboardPage } from '../pages/DashboardPage';
import { LoginPage } from '../pages/LoginPage';
import { UserFormPage } from '../pages/UserFormPage';
import { UsersIndexPage } from '../pages/UsersIndexPage';
import { e2eEnv } from '../support/env';
import { STRONG_PASSWORD, uniqueEmail, uniqueName } from '../support/data';
import { recorrido } from './fixtures/guia';

/**
 * Gestionar las personas que entran a la aplicación.
 *
 * Es la guía de ejemplo del boilerplate: enseña el patrón completo —entrar,
 * listar, crear, editar, buscar y subir una foto— sobre el único CRUD que
 * kore-laravel trae de fábrica. Copia su forma para las guías del proyecto
 * derivado; lo que cambia es el dominio, no la manera de contarlo.
 *
 * Se apoya en los **mismos page objects que la suite** (`tests/e2e/pages/`), y
 * eso es lo que mantiene el manual honesto: cuando una pantalla cambia y hay
 * que tocar el page object, el manual se regenera solo con la pantalla nueva.
 *
 * El paso de la foto de perfil depende de `FILES_ENABLED`. En `.env.e2e` está
 * encendido, así que aquí siempre entra; en un derivado que lo apague, la guía
 * se salta ese capítulo en vez de fallar.
 */
const CON_ARCHIVOS = e2eEnv.FILES_ENABLED === 'true';

/** Un PNG de 1×1 (69 bytes), el mismo que usa `specs/users/avatar.spec.ts`. */
const AVATAR = fileURLToPath(new URL('../fixtures/files/avatar.png', import.meta.url));

recorrido(
    {
        slug: '01-usuarios',
        titulo: 'Gestionar usuarios',
        paraQuien: 'Para quien administra las cuentas: dar de alta, editar y encontrar personas.',
        introduccion:
            'Todo lo que se hace en la aplicación se hace **con una cuenta**, y cada cuenta tiene un ' +
            'rol que decide qué puede tocar. Esta guía recorre el ciclo entero: entrar, ver el ' +
            'listado de usuarios, dar de alta a una persona, corregirle un dato y volver a ' +
            'encontrarla cuando el listado ya tenga cientos de filas.\n\n' +
            'Hace falta una cuenta con permiso para gestionar usuarios. Si al entrar no ves ' +
            '«Usuarios» en el menú de la izquierda, es que tu cuenta no lo tiene: pídeselo a quien ' +
            'administre el sistema.',
    },
    async ({ page, guia }) => {
        const login = new LoginPage(page);
        const dashboard = new DashboardPage(page);
        const usuarios = new UsersIndexPage(page);
        const formulario = new UserFormPage(page);

        const nombre = uniqueName('Ana Beltrán');
        const nombreCorregido = uniqueName('Ana Beltrán Ruiz');
        const correo = uniqueEmail('ana.beltran');

        /* ── Entrar ─────────────────────────────────────────────────────── */

        await guia.capitulo(
            'Entrar a la aplicación',
            'El primer paso, y el único que se hace sin haber entrado.',
        );

        await guia.paso(
            'Abre la pantalla de acceso',
            'Escribe la dirección de la aplicación en el navegador. Si no has entrado nunca —o si ' +
                'cerraste sesión— te recibe esta pantalla.',
            () => login.goto(),
        );

        await guia.paso(
            'Escribe tu correo y tu contraseña',
            'Son los que te dio quien administra el sistema. **Mantener sesión iniciada** te ahorra ' +
                'volver a escribirlos cada día; déjalo sin marcar si el equipo no es tuyo.',
            async () => {
                await login.email.fill(SEEDED_USERS.superadmin.email);
                await login.password.fill(SEEDED_USERS.superadmin.password);
            },
        );

        await guia.senalar(
            'Pulsa «Entrar»',
            'Si el correo o la contraseña no cuadran, la pantalla te lo dice sin decir cuál de los ' +
                'dos falla — a propósito.',
            login.submit,
            async () => {
                await login.submit.click();
                await expect(page).toHaveURL(/\/dashboard$/);
            },
        );

        await guia.paso(
            'Ya estás dentro',
            'Ésta es la pantalla de inicio. A la izquierda está el menú, y abajo del todo tu nombre, ' +
                'el conmutador de tema y el botón de salir.',
            () => expect(dashboard.greeting).toBeVisible(),
            { completa: true },
        );

        /* ── El listado ─────────────────────────────────────────────────── */

        await guia.capitulo(
            'El listado de usuarios',
            'Quiénes tienen cuenta, con qué correo y desde cuándo.',
        );

        await guia.senalar(
            'Entra a «Usuarios»',
            'Está en el menú de la izquierda, en el grupo **Gestión**. Sólo aparece si tu cuenta ' +
                'puede ver usuarios.',
            dashboard.usersSidebarLink,
            async () => {
                await dashboard.usersSidebarLink.click();
                await expect(page).toHaveURL(/\/users$/);
                await esperarLivewire(page);
            },
        );

        await guia.paso(
            'Esto es el listado',
            'Una fila por persona. Arriba, el **buscador**; a la derecha de cada fila, el botón de ' +
                '**acciones**, que es desde donde se edita o se da de baja.',
            undefined,
            {
                completa: true,
                nota:
                    'Las cuentas con el rol de administración no salen en este listado: se ' +
                    'gestionan desde fuera de la pantalla, para que nadie se quede sin quien ' +
                    'administre por un clic de más.',
            },
        );

        /* ── Dar de alta ────────────────────────────────────────────────── */

        await guia.capitulo(
            'Dar de alta a una persona',
            'Lo mínimo para que exista: cómo se llama, con qué correo entra y qué puede hacer.',
        );

        await guia.senalar(
            'Pulsa «Nuevo usuario»',
            'Está arriba a la derecha, junto al título.',
            usuarios.newUserButton,
            async () => {
                await usuarios.newUserButton.click();
                await expect(page).toHaveURL(/\/users\/create$/);
                await formulario.waitUntilReady();
            },
        );

        await guia.paso(
            'Elige primero el rol',
            'El **rol** decide qué podrá hacer esta persona. Elígelo antes que nada: al cambiarlo, ' +
                'el formulario se recarga y te muestra los permisos que trae.',
            () => formulario.selectRole('Usuario'),
            {
                nota:
                    '**Administrador** puede con todo; **Usuario** sólo con lo que le marques. ' +
                    'Nadie puede conceder un permiso que él mismo no tenga.',
            },
        );

        await guia.paso(
            'Rellena el nombre, el correo y la contraseña',
            'El **correo** es con lo que entrará, así que tiene que ser suyo y no puede repetirse. ' +
                'La contraseña es inicial: la persona podrá cambiarla después.',
            () => formulario.fill({ name: nombre, email: correo, password: STRONG_PASSWORD }),
            {
                nota:
                    'El nombre y el correo de la captura llevan un sufijo raro porque esta guía se ' +
                    'genera de verdad contra la aplicación y no puede chocar con lo que ya existe. ' +
                    'Tú escribirás los de la persona.',
            },
        );

        await guia.senalar(
            'Guarda',
            'La cuenta queda creada y vuelves al listado con el aviso de que salió bien.',
            formulario.submit,
            async () => {
                await formulario.save();
                await expect(page).toHaveURL(/\/users$/);
                await expect(usuarios.successToast).toBeVisible();
            },
        );

        await guia.paso(
            'Ahí está, en el listado',
            'La persona nueva aparece arriba: el listado ordena por fecha de alta, lo más reciente ' +
                'primero.',
            () => expect(usuarios.row(correo)).toBeVisible(),
            { completa: true },
        );

        /* ── Corregir un dato ───────────────────────────────────────────── */

        await guia.capitulo(
            'Corregir un dato',
            'Un apellido que faltaba, un correo mal tecleado. Se edita desde la propia fila.',
        );

        await guia.paso(
            'Búscala en el listado',
            'Escribe parte de su nombre o de su correo en el buscador. La tabla se filtra mientras ' +
                'escribes, sin pulsar nada.',
            async () => {
                await usuarios.searchFor(correo);
                await expect(usuarios.rows).toHaveCount(1);
            },
        );

        await guia.senalar(
            'Abre las acciones de su fila',
            'El botón del final del renglón despliega lo que se puede hacer con esa persona.',
            usuarios.actionsTrigger(correo),
            () => usuarios.openRowActions(correo),
        );

        await guia.paso(
            'Elige «Editar»',
            'Se abre su ficha con los datos que ya tenía.',
            async () => {
                await usuarios.menuItem('Editar').click();
                await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
                await formulario.waitUntilReady();
            },
        );

        await guia.paso(
            'Cambia lo que haga falta',
            'Aquí se corrige el nombre. **La contraseña se deja en blanco**: en blanco no se toca, ' +
                'y la persona sigue entrando con la que tenía.',
            () => formulario.name.fill(nombreCorregido),
            {
                nota:
                    'Escribir algo en el campo de contraseña **sí** la cambia, y la anterior deja ' +
                    'de servir. Avísale a quien la use antes de hacerlo.',
            },
        );

        await guia.senalar(
            'Guarda el cambio',
            'Vuelves al listado y el nombre ya sale corregido.',
            formulario.submit,
            async () => {
                await formulario.save();
                await expect(page).toHaveURL(/\/users$/);
                await expect(usuarios.successToast).toBeVisible();
            },
        );

        await guia.paso(
            'Encuéntrala otra vez',
            'Con el buscador, escribiendo una parte del nombre o del correo. Es la forma de llegar a ' +
                'alguien cuando el listado ya tiene cientos de filas.',
            async () => {
                const fila = await usuarios.focusOnRow(correo);
                await expect(fila).toContainText(nombreCorregido);
            },
            { recortar: usuarios.table },
        );

        /* ── La foto ────────────────────────────────────────────────────── */

        if (!CON_ARCHIVOS) {
            await guia.nota(
                'Esta instalación no tiene activada la subida de archivos, así que las cuentas no ' +
                    'llevan foto de perfil.',
            );

            return;
        }

        await guia.capitulo(
            'Ponerle una foto',
            'La foto de perfil se sube desde la ficha, y sólo cuando la cuenta ya existe.',
        );

        await guia.paso(
            'Vuelve a su ficha',
            'Por el mismo camino de antes: buscarla, abrir las acciones de su fila y elegir ' +
                '«Editar». Arriba del todo está la **foto de perfil**.',
            async () => {
                await usuarios.clickRowAction(correo, 'Editar');
                await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
                await formulario.waitUntilReady();
            },
        );

        await guia.paso(
            'Elige la imagen y pulsa «Guardar foto»',
            'Vale un PNG, un JPG o un WebP de hasta 2 MB. La foto se guarda **aparte del resto del ' +
                'formulario**: en cuanto pulsas, ya está — no hace falta guardar la ficha.',
            async () => {
                await page
                    .getByLabel('Foto de perfil', { exact: true })
                    .setInputFiles(AVATAR);
                await page.getByRole('button', { name: 'Guardar foto' }).click();

                // Cambio observable, no reloj (R38): con archivo vigente la
                // zona de subida pasa a llamarse «Sustituir por otro archivo».
                await expect(
                    page.getByLabel('Sustituir por otro archivo', { exact: true }),
                ).toBeAttached();
            },
            {
                nota:
                    'Subir otra imagen no borra la anterior: queda guardada como versión previa. ' +
                    'La imagen de la captura es un cuadrado de un solo píxel — el ejemplo mínimo ' +
                    'que trae el boilerplate.',
            },
        );

        await guia.paso(
            'La foto ya sale en el listado',
            'Vuelve a «Usuarios» y ahí está, al principio de su fila.',
            async () => {
                await usuarios.goto();
                await usuarios.focusOnRow(correo);
            },
            { recortar: usuarios.table },
        );
    },
);
