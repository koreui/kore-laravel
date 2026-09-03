# Provider que no registra nada con el toggle apagado

**TL;DR**: una capacidad opcional se enciende con una clave de
`config/kore-app.php` y la apaga **su propio provider**, con un `return`
temprano en `register()` y otro en `boot()`. Apagada no existe: ni rutas, ni
comandos, ni checks, ni entradas del scheduler. Dos excepciones (R10): el
comando que enciende el toggle y el namespace de vistas, que sin rutas no expone
nada.

## Contexto

El boilerplate tiene capacidades que un proyecto derivado puede no querer:
multi-tenancy, backups, 2FA, login social. Todas se controlan desde
`config/kore-app.php` (diez claves hoy) y todas se apagan por defecto o casi.

Dos restricciones marcan la forma:

- **Un `config/*.php` no puede leer otro** (R12): se cargan en orden alfabético,
  así que `config('kore-app.…')` dentro de `config/fortify.php` vale `null`.
- **Un toggle sin lector no existe** (R11): `composer arch` falla si una clave de
  `config/kore-app.php` no aparece en ningún `config('kore-app.{clave}')`.

## Problema

El fallo no es que la capacidad siga funcionando: es que sigue funcionando **a
medias, en silencio**. Un provider que hace el early return sólo en `boot()`
deja registrado en `register()` lo que ya había registrado; uno que registra
«sólo las rutas, que son inofensivas» deja `/backup` respondiendo 500 en
producción, o el provider de un paquete cargando su config y sus comandos.

El derivado que puso `TENANCY_ENABLED=false` descubre el resto por producción, y
lo descubre por un error raro —una migración que no debería estar, un middleware
que resuelve un tenant inexistente—, no por un mensaje que diga «esto está
encendido».

Y el modo de fallo inverso, el de R12: el toggle *parece* funcionar en local
—porque el `env()` de respaldo devuelve lo mismo— y deja de funcionar el día del
primer `php artisan config:cache`, sin ninguna señal.

## Solución

```php
final class BackupServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        if (! $this->isBackupEnabled()) {
            return;
        }

        $this->app->register(SpatieBackupServiceProvider::class);
    }

    public function boot(): void
    {
        if (! $this->isBackupEnabled()) {
            return;
        }

        $this->registerHealthCheck();
    }

    private function isBackupEnabled(): bool
    {
        return (bool) config('kore-app.backup.enabled', false);
    }
}
```

Cuatro detalles que son el patrón, no el ejemplo:

1. **El `return` va en los dos métodos**, y el de `register()` es el que
   importa: es donde se registra el provider del paquete, y un provider
   registrado ya no se puede «desregistrar» desde `boot()`.
2. **La lectura del toggle vive en un método privado** (`isBackupEnabled()`), no
   repetida en cada rama. Es lo que hace que R11 encuentre al lector con un
   `grep` y lo que permite cambiar la clave en un solo sitio.
3. **El provider del paquete va en `extra.laravel.dont-discover`** del
   `composer.json`. Sin eso Laravel lo autodescubre y el toggle no apaga nada:
   los comandos `backup:*` existen igual.
4. **Lo que vive fuera del provider se protege con el mismo `config()`**. El
   scheduler es el caso típico: `routes/console.php` envuelve las tres tareas de
   backup en un `if ((bool) config('kore-app.backup.enabled'))`, porque los
   `Schedule::command()` se registran aunque el comando no exista.

### Variante A · el comando que enciende el toggle

Un módulo opt-in tiene un problema de arranque: con el toggle apagado no hay
forma de encenderlo. La excepción —la primera de las dos que R10 admite— es registrar **ese
comando y sólo ese** antes del early return:

```php
public function register(): void
{
    // El comando vive siempre disponible para que el usuario pueda activar
    // tenancy con `php artisan kore:tenancy:enable`.
    $this->commands([
        EnableTenancyCommand::class,
    ]);

    if (! $this->isTenancyEnabled()) {
        return;
    }

    $this->app->register(StanclTenancyServiceProvider::class);
}
```

Nada más pasa esa línea: ni rutas, ni vistas, ni migraciones. El `boot()` hace
el early return sin excepciones.

### Variante B · mutar la config de un paquete

Cuando lo que hay que apagar no es un provider sino una *feature* de un paquete
que lee su propia config, el toggle se aplica **mutando esa config desde
`register()`**, que es lo único que corre antes del `boot()` del paquete:

```php
private function configureTwoFactorFeature(): void
{
    $twoFactor = Features::twoFactorAuthentication([...]);

    /** @var array<int, string> $features */
    $features = (array) config('fortify.features', []);
    $features = array_values(array_filter($features, fn (string $feature): bool => $feature !== $twoFactor));

    if ((bool) config('kore-app.auth.two_factor', true)) {
        $features[] = $twoFactor;
    }

    config(['fortify.features' => $features]);
}
```

Se quita siempre y se vuelve a añadir si toca, en vez de añadir condicionalmente:
así el resultado no depende de lo que `config/fortify.php` trajera de fábrica.

## Dónde está en el código

- `app/Modules/Tenancy/Providers/TenancyModuleServiceProvider.php` — variante A.
- `app/Modules/Auth/Providers/FortifyServiceProvider.php` — variante B
  (`configureTwoFactorFeature()`, llamado desde `register()`).
- `app/Providers/BackupServiceProvider.php` — la forma base.
- `routes/console.php` — los dos `if` sobre `kore-app.api.enabled` y
  `kore-app.backup.enabled` que protegen el scheduler.
- `composer.json` → `extra.laravel.dont-discover`: `spatie/laravel-backup` y
  `stancl/tenancy`.

## Las tres apariciones

| # | Dónde | Versión | Qué aportó |
|---|-------|---------|------------|
| 1 | `TenancyModuleServiceProvider` | v1.0.0 | La forma base y la excepción del comando de activación: sin `kore:tenancy:enable` disponible con el toggle apagado, `TENANCY_ENABLED=false` sería un callejón sin salida. `TenancyToggleTest` blinda las dos mitades. |
| 2 | `FortifyServiceProvider::configureTwoFactorFeature()` | v1.0.0 | El caso en que el early return no basta: Fortify lee `fortify.features` en su `boot()`, así que el toggle se aplica mutando esa config desde `register()`. Es la cicatriz de R12 y de R17 —`config/fortify.php` llamaba a `env('AUTH_2FA_ENABLED')` y el toggle sólo *parecía* funcionar—. |
| 3 | `BackupServiceProvider` | v1.3.0 | La tercera aparición, tres releases después, sin que nadie coordinara la forma: mismo early return en los dos métodos, mismo método privado de lectura, mismo `dont-discover`. Añadió la pieza que faltaba —el `if` del scheduler— porque `Schedule::command()` no falla aunque el comando no exista. |
| 4 | `FortifyServiceProvider::configurePasskeysFeature()` | v2.0.0 | La variante B por segunda vez, sin cambiarle una coma: quitar la feature y volver a añadirla si toca, la lectura del toggle en un método privado, y el `register()` como único sitio que corre antes del `boot()` de Fortify. Que el patrón se aplicara solo —mismo provider, misma forma, cuatro releases después— es la prueba de que estaba bien escrito. |

La variante se estrenó con el 2FA y se repitió tal cual con las passkeys
(v2.0.0). Lo único que cambia entre las dos es el nombre de la feature y sus
opciones: `['confirm' => true, 'confirmPassword' => true]` en una,
`['confirmPassword' => true]` en la otra. Si algún día hay una tercera, el
método privado se puede extraer; con dos, duplicarlo se lee mejor que
abstraerlo.

### Variante C · el namespace de vistas

`DocsModuleServiceProvider` (v1.4.0) registra `loadViewsFrom()` **antes** del
`return` temprano y el resto detrás. Larastan valida cada `view('docs::x')`
contra el `ViewFactory` de la aplicación que arranca en el análisis, y en CI el
toggle vale su default: sin el namespace, `composer analyse` fallaría por un
archivo que sí está en el repositorio. Sin rutas no hay forma de llegar a esas
vistas, así que no expone nada observable. Es la segunda excepción de R10, y la
última: rutas, middleware, comandos de dominio y traducciones siguen detrás del
`return`.

## Reglas relacionadas

- **R10** — un toggle apagado no registra nada, con la excepción del comando de
  activación.
- **R11** — un toggle sólo existe si alguien lo lee; lo verifica
  `kore:arch:check`.
- **R12** — un `config/*.php` no lee otro; de ahí la variante B.

Y el test: cada toggle trae el suyo (`TenancyToggleTest`, `TwoFactorToggleTest`,
`BackupTest`), que comprueba las dos mitades —apagado no registra, encendido
registra—. Para arrancar la aplicación con el toggle en el otro estado, ver
[`test-con-otro-entorno.md`](test-con-otro-entorno.md).
