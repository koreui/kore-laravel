# Hallazgos de la suite E2E

Todo lo que la suite encuentra —bugs, huecos, deudas, comportamientos que
sorprenden— se anota aquí con su identificador, su estado y el test que lo
revela. Es el sistema de seguimiento: **si un hallazgo no está en esta lista,
no existe**.

**Convención**: cada hallazgo lleva un `KORE-E2E-###` que se cita en el
comentario del test y, cuando se corrige, en el comentario del código. Los
números no se reutilizan ni se renumeran: están citados desde el código.

| Estado | Significa |
|---|---|
| 🔴 Abierto | Reproducido, sin corregir |
| 🟢 Corregido | Corregido y con test que lo protege |
| 🔵 Documentado | Es el comportamiento actual y se decidió dejarlo; el test lo fija |

**Estado al día de hoy**: 2 corregidos, 3 documentados, 2 abiertos.

## Plantilla

```markdown
## KORE-E2E-0NN · Titular de una línea, en presente

**Estado**: 🔴 Abierto · **Severidad**: baja | media | alta | crítica ·
**Test**: `specs/…` › «nombre del test»

Qué pasa, contado como lo vería alguien usando la aplicación.

**Causa.** Dónde está de verdad, con archivo y línea si se sabe.

**Por qué no lo vio nadie.** Qué capa de pruebas debería haberlo cazado y por
qué no pudo. Es la parte que más vale de un hallazgo de E2E.

**Corrección** (o **Cómo se arregla**, si sigue abierto): el cambio concreto.
```

Cuando un hallazgo obliga a tolerar un error en el guardia
(`test.use({ tolerarErrores: ['KORE-E2E-0NN'] })`), el patrón tolerado **cita
el identificador**: así se puede buscar y así se sabe cuándo se puede quitar.

---

## KORE-E2E-001 · El detector de traducciones sin resolver se atragantaba con el visor de docs

**Estado**: 🟢 Corregido · **Severidad**: baja (de la suite, no del producto) ·
**Test**: `specs/access/smoke` › «/docs/architecture/rules · Docs · catálogo de reglas»

El smoke comprueba que ninguna pantalla muestre una clave de traducción sin
resolver. La forma de una clave sin resolver es `paquete::archivo.clave`, y en
prosa no hay texto normal con `::` en medio, así que el detector busca
literalmente eso en el texto de la página.

En `/docs/architecture/rules` cazó **23 falsos positivos** a la primera:

```
UserForm::store · Gate::before · DB::table · Model::unguard · CarbonImmutable::class …
```

**Causa.** El visor de documentación renderiza Markdown, y el catálogo de
reglas está lleno de PHP: llamadas estáticas dentro de `` `backticks` `` y de
bloques de código. Son `::` de verdad, escritos a propósito.

**Por qué importa.** Es la clase de falso positivo que mata un detector: a la
tercera vez que salta sin razón, alguien lo borra y con él se va la red que
sí servía.

**Corrección**: el detector mira **la prosa, no el código**. Se recortan
`pre`, `code`, `script` y `style` de una **copia** del DOM —la página no se
toca— antes de leer el texto. Con eso las 14 pantallas del mapa pasan limpias
y el detector sigue cubriendo lo que tiene que cubrir.

---

## KORE-E2E-002 · `/magic-link` no rebota a quien ya tiene sesión

**Estado**: 🔵 Documentado · **Severidad**: baja · **Test**: `specs/access/rbac` ›
«/magic-link → 200» (los cinco perfiles)

Las cuatro pantallas de acceso de Fortify —`/login`, `/register`,
`/forgot-password` y el reset— llevan el middleware `guest`: a quien ya entró
lo mandan a `/dashboard`, porque no tienen sentido para él. `/magic-link` no.

Se registra en `app/Modules/Auth/Routes/web.php` con `middleware('web')` a
secas, así que responde **200** también a un usuario autenticado, que puede
pedirse un código para su propia cuenta —o para otra— sin salir de la sesión
que ya tiene.

**No es un agujero.** El componente no autentica a nadie hasta que el código
correcto llega, y el flujo anti-enumeración es el mismo. Es una asimetría con
el resto de las pantallas de acceso, y de las que se descubren tarde: hasta
que la matriz no recorrió las cinco combinaciones, nadie había abierto
`/magic-link` con sesión abierta.

**Si se decide alinear**: añadir `guest` al grupo de esa ruta en el módulo
Auth. La entrada del mapa de acceso pasaría a `'dashboard'` para los cuatro
roles y el cambio saltaría solo.

---

## KORE-E2E-003 · `/pulse` es la única pantalla que responde 403 a un invitado

**Estado**: 🔵 Documentado · **Severidad**: baja · **Test**: `specs/access/rbac` ›
«/pulse → 403» (invitado)

Todas las pantallas protegidas del boilerplate mandan al invitado a `/login`:
lo hace el middleware `auth`. `/pulse` no lo lleva —su puerta es el gate
`viewPulse`, que `AuthModuleServiceProvider` restringe al superadmin—, así que
un invitado se lleva un **403 seco** en vez de la redirección.

El efecto práctico es menor (nadie ve nada que no deba), pero la experiencia
es peor: alguien con sesión caducada que tenía `/pulse` abierto se encuentra
un 403 sin manera de volver a entrar, en vez del login.

**Si se decide alinear**: añadir `auth` a `pulse.middleware` en
`config/pulse.php`. La entrada del mapa pasaría a `'login'` para el invitado y
seguiría en `403` para los demás.

---

## KORE-E2E-004 · El título de `/pulse` no es un encabezado

**Estado**: 🔴 Abierto · **Severidad**: baja (accesibilidad, en el paquete) ·
**Test**: `specs/access/smoke` › «/pulse · Pulse» (sin `heading` en el mapa)

El panel de Pulse no tiene **ningún elemento con rol de encabezado**. Su
título —«Laravel Pulse»— es un `<span>` dentro de la barra superior, así que
un lector de pantalla no encuentra ni un solo `heading` en toda la pantalla y
no hay forma de saltar al contenido.

Para la suite, el efecto es que la entrada de `/pulse` en el mapa de acceso es
la única sin `heading`: el smoke comprueba que monta y que no revienta, pero no
puede comprobar que montó **la pantalla correcta**.

**Dónde**: es HTML del propio `laravel/pulse`
(`vendor/laravel/pulse/resources/views/dashboard.blade.php`), no del
boilerplate. Anclarlo con un selector CSS sobre el marcado de un paquete sería
peor que no anclarlo: cambia sin avisar y el fallo aparecería como un flake.

**Cómo se arregla**: en el paquete, marcando ese título como `<h1>`. Mientras
tanto, la entrada se queda sin `heading` y con este identificador citado en su
comentario.

---

## KORE-E2E-005 · Dos campos de contraseña sin nombre accesible

**Estado**: 🔴 Abierto · **Severidad**: baja (accesibilidad del producto) ·
**Test**: `specs/auth/login`, `specs/auth/passkeys` (vía `pages/LoginPage.ts` y
`pages/PasskeysPage.ts`)

Dos campos del boilerplate hay que localizarlos con CSS porque no tienen
nombre accesible, y eso es exactamente lo que R37 dice que hay que anotar en
vez de tapar con un `data-testid`:

| Pantalla | Campo | Por qué no se puede localizar |
|---|---|---|
| `/login` | contraseña | `login.blade.php` pinta `<x-kore::password>` **sin `:label`**, así que no se emite `<label for>`. La etiqueta visible es un `<span>` hermano. Se localiza con `#kore-password`. |
| `/user/confirm-password` | contraseña | `getByLabel('Contraseña')` es ambiguo: casa el input («Contraseña *») y el botón de ver/ocultar, cuyo `aria-label` es «Mostrar la contraseña». Se localiza con `input[name="password"]`. |

Quien usa un lector de pantalla en `/login` oye «cuadro de edición» sin saber
de qué; quien lo usa en la confirmación oye dos cosas que se llaman casi igual.

**Cómo se arregla**: pasar `:label` al `<x-kore::password>` de `login.blade.php`
—que es una línea— y, para el segundo, renombrar el `aria-label` del ojo de
`<x-kore::password>` a algo que no empiece por «Contraseña» (eso es de koreUi).
El día que se arregle, los dos selectores CSS de los page objects se pueden
sustituir por `getByLabel`.

---

## KORE-E2E-006 · Las row actions con confirmación no llegaban al servidor

**Estado**: 🔵 Documentado (workaround vigente) · **Severidad**: **alta** ·
**Test**: `specs/users/delete` › «confirmar borra la fila»

Éste es el hallazgo que justifica la suite entera, y está contado con detalle
en [`docs/quality/e2e.md`](../../docs/quality/e2e.md) («Workaround vigente:
confirmar una row action»). En corto:

Con koreUi 2.2, borrar un usuario desde el menú de su fila **no hacía nada**.
El diálogo se abría, se pulsaba «Confirmar» y ahí terminaba todo.

**Causa.** `InteractsWithFeedback::handleConfirmCallback()` sólo ejecuta
métodos previamente autorizados en `$koreConfirmable`, lista que rellena
únicamente `Confirm::send()` en el servidor — camino que las bulk actions
recorren y las row actions no.

**Por qué no lo vio nadie.** Los tests de Livewire invocan el método
directamente (`->call('confirmDelete')`), sin pasar por el diálogo del
navegador. Verde en Pest, roto en pantalla.

**Workaround vigente**: `TableUsers::hydrate()` añade `confirmDelete` a
`$koreConfirmable`. Se quita en cuanto koreUi autorice las row actions por su
cuenta.

---

## KORE-E2E-007 · El formulario de usuarios se envía como GET nativo si Livewire no ha hidratado

**Estado**: 🟢 Corregido (en la suite y en el producto: el botón «Guardar» nace `disabled` y Alpine lo habilita al hidratar — `form-component.blade.php`) ·
**Severidad**: **alta** · **Test**: `specs/users/edit` › «editar sin tocar la
contraseña deja la cuenta usable»

Salió con `--repeat-each=2`: un test que pasa siempre suelto falló una vez de
cada pocas, y el mensaje lo dijo todo:

```
Expected pattern: /\/users$/
Received string:  "http://localhost:8010/users/19/edit?form.name=Sin+password+…
                   &form.email=&form.password=&form.password_confirmation=
                   &form.role=Administrador"
```

Eso no es un timeout: es **el navegador enviando el `<form>` de forma nativa**,
como GET, con todos los campos en la barra de direcciones. Nada se guardó.

**Causa.** Los inputs se renderizan **sin atributo `value`** —se comprueba
pidiendo el HTML crudo de `/users/{id}/edit`—:

```html
<input type="text" id="form-name" name="form.name" wire:model="form.name" />
```

Es Livewire quien los rellena desde el snapshot al hidratar. Hasta ese momento
el formulario se ve **en blanco** y, lo que importa aquí, `wire:submit` todavía
no está enganchado: el `<form>` es un formulario HTML normal y `Guardar` hace lo
que hace un submit normal.

**Lo que eso significa fuera del test.** Una persona con la red lenta que llegue
a `/users/{id}/edit` y pulse Guardar antes de que el JS arranque no sólo no
guarda: **se lleva la contraseña que acababa de escribir a la barra de
direcciones**, y de ahí al historial del navegador y al log de accesos del
servidor. El campo `form.password` sale en la query string.

**Por qué no lo vio nadie.** Los tests de Livewire llaman a `->call('save')`
sin pasar por el DOM, así que el formulario «funciona» en Pest mientras está
roto en la ventana de hidratación. Y en E2E sólo aparece cuando la carga de la
página y el clic caen del lado malo de la carrera — por eso hizo falta
`--repeat-each` para verlo.

**Corrección en la suite**: `UserFormPage.save()` espera a la hidratación antes
de pulsar (`waitUntilReady()`), y `specs/users/edit` la espera además antes de
escribir — porque un `fill()` prehidratación se lo lleva por delante el morph.
Con eso la carrera desaparece del lado del test.

**Cómo se arregla en el producto** (esto sigue abierto): deshabilitar el botón
de envío hasta que Livewire esté vivo. La vía barata es `wire:offline` /
un `x-cloak` sobre el botón; la buena es que el `<button type="submit">` nazca
`disabled` y que Alpine lo habilite en `livewire:init`, de modo que sin JS el
formulario no se pueda mandar a ninguna parte.
