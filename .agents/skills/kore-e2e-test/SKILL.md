---
name: kore-e2e-test
description: Crear o ampliar tests E2E de Playwright en tests/e2e/ de kore-laravel, con su page object y sus specs por módulo. Úsalo cuando el usuario pida "crear un test e2e", "agregar un spec de playwright", "probar el flujo X en el navegador" o verificar en un navegador real que Livewire, Alpine y koreUi funcionan juntos.
compatibility: "kore-laravel (Laravel 13, Livewire 4, Pest 5). Claude Code, Codex y cualquier cliente Agent Skills."
---

# Crear tests E2E (Playwright) en kore-laravel

Doc de referencia: [`docs/quality/e2e.md`](../../../docs/quality/e2e.md). Léelo si necesitas el porqué de una decisión; aquí está lo operativo.

## Cuándo usarlo

- **Sí**: un flujo completo de usuario en el navegador (login, alta, búsqueda, borrado con confirmación), verificar que un `wire:model` / dropdown / modal de koreUi se comporta, o comprobar quién ve qué según su rol.
- **No**: reglas de una Action, validaciones de un Form Object, políticas o un componente Livewire aislado. Eso es Pest (`app/Modules/{Domain}/Tests/`), es más rápido y más preciso.

## Reglas (catálogo completo: `docs/architecture/rules.md`)

- **R37** · La suite vive **sólo** en `tests/e2e/`. No se toca nada de `app/`
  para hacerla pasar: **nada de añadir `data-testid` a las Blade**. Lo verifica
  `composer arch`, así que el atajo ni siquiera llega al commit.
- **R38 · Prohibido `page.waitForTimeout()`.** Se espera a un cambio
  observable; y cuando no lo hay, a Livewire (`waitForLivewireReady`,
  `esperarLivewire`, `conRoundTrip`). También lo verifica `composer arch`.
- **R39 · Datos únicos por test** con `uniqueEmail()` / `uniqueName()`. La base
  sólo se resetea en `globalSetup`; ningún test puede depender de otro ni del
  orden.
- **R52 · Toda pantalla nueva entra en `tests/e2e/fixtures/access-map.ts`.**
  Con eso queda cubierta por RBAC y por el smoke sin escribir un test. Lo
  verifica `composer arch` comparando las rutas GET con nombre contra los
  `path:` del mapa.
- `declare(strict_types=1)` y `final class` aplican al PHP; aquí es TypeScript
  estricto: tipos explícitos, sin `any`.
- Localizadores accesibles: `getByRole` → `getByLabel` → `getByPlaceholder` →
  `getByText`. **Lee la Blade real** antes de escribir un locator (los textos
  están en español) y, si el componente es de koreUi, mira
  `vendor/kore-ui/kore-ui/resources/views/` para saber qué HTML genera.
- Las cuentas de `E2eSeeder` (`superadmin@`, `editor@`, `viewer@`, `member@`
  `e2e.test`, contraseña `password`) son de **sólo lectura**: si el test
  necesita modificar un usuario, que lo cree él.

## Checklist para un módulo nuevo

1. **El mapa de acceso primero.** Una entrada por pantalla en
   `tests/e2e/fixtures/access-map.ts` (R52). Eso ya te da el smoke y la matriz
   de autorización — R36 cumplido sin escribir un test:

   ```ts
   {
       path: '/facturas',          // literal, sin parámetros: lo lee kore:arch:check
       nombre: 'Facturas · listado',
       heading: 'Facturas',        // el getByRole('heading') que prueba que cargó
       roles: {
           invitado: 'login',
           member: 403,
           viewer: 200,
           editor: 200,
           superadmin: 200,
       },
   },
   ```

   Los seis valores posibles: `200`, `403`, `404`, `'login'`, `'dashboard'`
   (rebote de `guest`) y `'confirm'` (rebote de `password.confirm`).

2. **Apunta los flujos** en `tests/e2e/FLUJOS.md`, con su marca de cobertura.
3. `mkdir tests/e2e/specs/{modulo}`
4. Page object en `tests/e2e/pages/{Pantalla}Page.ts` por cada pantalla.
5. Specs para lo que el mapa **no** puede describir:
   - `{caso-de-uso}.spec.ts` — happy path completo con datos del propio test.
   - Lo que dependa de un parámetro de ruta, de una acción oculta según el
     permiso o de varios pasos encadenados.
6. Si el módulo añade permisos, súmalos al rol que toque en
   `database/seeders/E2eSeeder.php`.
7. `npm run e2e` hasta verde, y luego `npx playwright test --repeat-each=2`
   para cazar flakiness. **La segunda no es opcional**: KORE-E2E-007 (un
   formulario que se enviaba como GET nativo, con la contraseña en la URL) sólo
   apareció ahí.

## Plantilla de page object

```ts
import { expect, type Locator, type Page } from '@playwright/test';

import { waitForLivewireReady } from '../support/livewire';

/** `/{ruta}` — vista `{modulo}::pages.{vista}`. */
export class {Pantalla}Page {
    readonly heading: Locator;

    readonly submit: Locator;

    constructor(private readonly page: Page) {
        this.heading = page.getByRole('heading', { name: '{Título}', exact: true });
        this.submit = page.getByRole('button', { name: 'Guardar' });
    }

    async goto(): Promise<void> {
        await this.page.goto('/{ruta}');
        // Sólo si la pantalla tiene Livewire: sin esto, un fill() puede
        // escribir antes de que nadie escuche el evento `input`.
        await waitForLivewireReady(this.page);
    }

    /** Punto de sincronización: filtra y espera a que la lista se asiente. */
    async focusOn(term: string): Promise<Locator> {
        await this.search.fill(term);
        await expect(this.rows).toHaveCount(1);

        return this.rows.first();
    }
}
```

## Plantilla de spec

```ts
import { expect, SEEDED_USERS, test } from '../../fixtures';
import { {Pantalla}Page } from '../../pages/{Pantalla}Page';
import { uniqueEmail, uniqueName } from '../../support/data';

test.describe('{Módulo} · {caso}', () => {
    // Todo el describe autenticado con ese rol. Omítelo para probar como invitado.
    test.use({ role: 'superadmin' });

    test('{qué debe pasar}', async ({ page }) => {
        const pantalla = new {Pantalla}Page(page);
        const nombre = uniqueName();

        await pantalla.goto();
        await expect(pantalla.heading).toBeVisible();

        await pantalla.submit.click();

        // Cambio observable, nunca un sleep.
        await expect(page.getByText('¡Listo!')).toBeVisible();
        await expect(page).toHaveURL(/\/{ruta}$/);
    });
});
```

## Plantilla de spec de autorización

```ts
import { expect, test } from '../../fixtures';

test.describe('{Módulo} · rutas protegidas', () => {
    test('un invitado acaba en /login', async ({ page }) => {
        await page.goto('/{ruta}');
        await expect(page).toHaveURL(/\/login$/);
    });

    // Fixtures asSuperadmin / asEditor / asViewer / asMember: varios roles en
    // el mismo test, cada uno con su contexto ya autenticado.
    test('member no tiene acceso (403)', async ({ asMember }) => {
        const response = await asMember.goto('/{ruta}');

        expect(response?.status()).toBe(403);
    });

    test('editor sí (200)', async ({ asEditor }) => {
        const response = await asEditor.goto('/{ruta}');

        expect(response?.status()).toBe(200);
    });
});
```

Se comprueba el **status** de la navegación, no un texto de la página de error: es lo que devuelve el middleware `permission:` y no depende del idioma ni del diseño.

## Trampas de este stack (te van a morder)

| Síntoma | Causa |
| --- | --- |
| `getByLabel('Contraseña')` devuelve 4 elementos | `getByLabel` también mira `aria-label`, y el ojo de `<x-kore::password>` se llama "Mostrar la contraseña". Usa `{ exact: true }`. |
| `getByLabel('Contraseña', { exact: true })` no encuentra nada en `/register` | El asterisco de `required` va DENTRO del `<label>`: el texto es `Contraseña *`. |
| `getByLabel(/^Rol$/)` no encuentra nada | Con expresión regular, `getByLabel` no normaliza espacios: compara contra el `textContent` crudo. Usa cadena + `{ exact: true }`. |
| Dos headings con el mismo nombre | El layout pinta un `<h1>` con el título de la página y `<x-kore::card>` un `<h3>`. Desambigua con `level`. |
| El `menuitem` de una fila del DataTable "no existe" | El menú se teletransporta a `<body>`; todas las filas dejan el suyo en el DOM. Filtra con `.filter({ visible: true })`. |
| `page.waitForResponse: Timeout` al escribir en un campo | Livewire aún no había hidratado. Usa `waitForLivewireReady(page)` antes. |
| Un test pasa suelto y falla en la suite | Estado compartido. Revisa `uniqueEmail()` y filtra antes de contar filas. |
| `Too many login attempts` | Fortify permite 5 logins/min por `email\|ip`. Si el spec se autentica, que se cree su propia cuenta con `createUserViaUi()`. |

## Cómo correr y verificar

```bash
npm run e2e                                   # suite completa
npx playwright test tests/e2e/specs/{modulo}  # sólo tu módulo
npx playwright test -g 'nombre del test'
npm run e2e:ui                                # depurar
npx playwright test --repeat-each=2           # cazar flakiness
```

No des un spec por bueno hasta que pase **la suite entera** tres veces seguidas y una vez con `--repeat-each=2`: en paralelo aparecen carreras que en solitario no existen.

## Qué reportar

- Specs añadidos y qué cubre cada uno.
- Resultado de las corridas (incluida la de `--repeat-each=2`) y tiempos.
- **Candidatos a mejora de accesibilidad**: todo elemento que hayas tenido que localizar con un selector CSS por no tener nombre accesible, con `archivo:línea`.
- Bugs de la app o de koreUi que hayas encontrado, con la causa raíz. Si un test no puede pasar por un bug ajeno, márcalo con `test.fixme()` y un comentario que explique el porqué y cuándo quitarlo — nunca lo escribas para que pase asertando el comportamiento roto.
