# Arrancar la aplicación con otras variables de entorno en un test

**TL;DR**: para probar lo que pasa con `BACKUP_ENABLED=true` o `APP_DEBUG=true`
hay que **rearrancar** la aplicación, no cambiar un `config()`. El helper
`withEnvironment()` de `tests/Pest.php` escribe la variable como «definida desde
fuera», llama a `refreshApplication()` y restaura al salir. `Env::getRepository()->set()`
a secas no vale, y el motivo es sutil.

## Contexto

Hay tres cosas distintas que un test puede querer cambiar, y sólo la tercera
necesita este patrón:

| Qué se quiere probar | Herramienta |
|----------------------|-------------|
| Una rama que lee `config('x.y')` en tiempo de petición | `Config::set('x.y', …)` |
| Un provider que lee la config en su `boot()` | `Config::set(…)` + `$this->app->register(Provider::class, force: true)` |
| Lo que hace la aplicación **al arrancar** con otra variable de entorno | `withEnvironment([...], fn () => …)` |

El tercer caso aparece en cuanto una decisión se toma **antes** de que el test
pueda tocar nada: qué providers se registran, qué rutas se publican, si la
aplicación arranca siquiera.

## Problema

Dos trampas, una encima de la otra.

**La primera: `Config::set()` llega tarde.** Un toggle que decide si se registra
el provider de un paquete ya decidió cuando el test empieza. Poner la config
después no desregistra nada, y el test pasa en verde probando el estado que la
suite tenía de todas formas.

**La segunda: `Env::getRepository()->set()` no pisa el `.env`.** Es el patrón
obvio y es el que usaba `TwoFactorToggleTest` hasta la v1.3.0. Funciona… hasta
que la variable existe en el `.env` del desarrollador. El repositorio de Dotenv
es **inmutable** —`set()` no cambia una variable que ya viene del `.env`— y,
además, la recarga del `.env` en el siguiente `refreshApplication()` vuelve a
escribir todo lo que tiene apuntado en su mapa `loaded`. Lo único que Dotenv
respeta es una variable «definida desde fuera»: presente en `$_ENV` / `$_SERVER`
/ `putenv()` **sin** estar en ese mapa.

El resultado es el peor tipo de test: verde en CI (donde no hay `.env`) y rojo
en la máquina de quien acaba de seguir el `composer setup` documentado. Eso fue
literalmente lo que pasó con `TwoFactorToggleTest`.

## Solución

`tests/Pest.php` expone dos funciones. La de arriba es la que se usa:

```php
withEnvironment(['BACKUP_ENABLED' => 'true'], function (): void {
    expect(config('kore-app.backup.enabled'))->toBeTrue()
        ->and(array_keys(app()->getLoadedProviders()))
        ->toContain(SpatieBackupServiceProvider::class);
});
```

Lo que hace, en orden:

1. Guarda el valor anterior de cada variable (`getenv()`).
2. Escribe el nuevo con `writeRawEnvVariable()`, que primero hace
   `Env::getRepository()->clear($name)` —para sacarla del mapa `loaded`— y
   después la pone en `$_SERVER`, `$_ENV` y `putenv()`. Los tres, porque los
   tres adaptadores de Dotenv se consultan.
3. `test()->refreshApplication()`: la aplicación arranca de nuevo, esta vez
   leyendo el valor nuevo.
4. Ejecuta el callback.
5. En un `finally`, restaura los valores anteriores y **vuelve a arrancar**. El
   `finally` no es cosmético: sin él, un fallo dentro del callback dejaría la
   variable puesta para todos los tests siguientes del mismo proceso.

Por eso el test da el mismo resultado tenga el desarrollador la variable en su
`.env` o no, que es la propiedad que se buscaba.

### Cuándo **no** usarlo

Cuesta un arranque completo de la aplicación por llamada. Si lo que quieres
probar es el `boot()` de un provider con otra config, sale mucho más barato:

```php
Config::set('kore-app.auth.two_factor', false);

$this->app->register(FortifyServiceProvider::class, force: true);
```

`force: true` re-registra el provider aunque ya esté registrado, y su `boot()`
corre contra la config que acabas de fijar. `TwoFactorToggleTest` y
`ProductionConfigTest` usan las dos formas, cada una donde toca.

### Envolverlo cuando el escenario se repite

Si un archivo de test arranca siempre con la misma variable, se le pone nombre
una vez:

```php
function withBackupEnabled(Closure $callback, array $env = []): void
{
    withEnvironment(['BACKUP_ENABLED' => 'true', ...$env], $callback);
}
```

## Dónde está en el código

- `tests/Pest.php` — `withEnvironment()` y `writeRawEnvVariable()`, con el
  porqué del `clear()` escrito en el docblock (incluida la razón por la que el
  borrado copia `$_ENV` en una variable local en vez de hacer `unset($_ENV[…])`:
  el `EnvVariableToEnvHelperRector` de rector-laravel reescribe cualquier lectura
  de `$_ENV[...]` como `Env::get(...)` y no distingue la que está dentro de un
  `unset`).
- `tests/Feature/BackupTest.php` — `withBackupEnabled()`, el envoltorio con
  nombre.
- `tests/Feature/ProductionConfigTest.php` — el caso extremo: se espera que el
  arranque **lance**, así que el `withEnvironment()` entero va dentro de un
  `expect(fn () => …)->toThrow(...)`.
- `app/Modules/Auth/Tests/Feature/TwoFactorToggleTest.php` — el que descubrió el
  problema.

## Las tres apariciones

| # | Dónde | Versión | Qué aportó |
|---|-------|---------|------------|
| 1 | `TwoFactorToggleTest` | v1.0.0 → arreglado en v1.3.0 | Nació con `Env::getRepository()->set()` y era verde en CI y rojo en local en cuanto el `.env` definía `AUTH_2FA_ENABLED`. Es la aparición que enseñó el modo de fallo. |
| 2 | `BackupTest` | v1.3.0 | El caso de volumen: 16 tests que necesitan la aplicación arrancada con el toggle encendido para ver el provider del paquete, sus comandos, sus tareas del scheduler y su check de `/health`. Aportó el envoltorio con nombre (`withBackupEnabled()`). |
| 3 | `ProductionConfigTest` | v1.3.0 | El caso en que lo que se prueba es que la aplicación **no** arranque (R47, `APP_DEBUG=true` en producción). Confirmó que el `finally` tiene que restaurar también cuando el arranque lanza. |

Las tres convergieron en la v1.3.0 en el mismo helper de `tests/Pest.php`, que
es lo que lo convirtió en patrón y no en tres soluciones parecidas.

## Reglas relacionadas

- **R10** — un toggle apagado no registra nada; comprobarlo exige arrancar la
  aplicación en los dos estados. Ver
  [`toggle-provider.md`](toggle-provider.md).
- **R11** — el toggle tiene un lector real, y este patrón es la forma de
  ejercitarlo.
- **R17** — `env()` sólo dentro de `config/`. Por eso el test manipula el
  entorno y deja que la config lo lea: es el camino real, no un atajo.
