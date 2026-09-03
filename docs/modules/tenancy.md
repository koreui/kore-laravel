# Módulo Tenancy

**TL;DR**: stancl/tenancy v3 envuelto en `app/Modules/Tenancy/` con un toggle `TENANCY_ENABLED`. Cuando está OFF, el provider hace early return y nada del paquete se registra. Para activarlo: `php artisan kore:tenancy:enable`.

## Estado por defecto

`TENANCY_ENABLED=false` — el módulo está **inactivo**:
- `Stancl\Tenancy\TenancyServiceProvider` NO se registra
- Sin migraciones tenancy
- Sin rutas tenant
- El comando `kore:tenancy:enable` **sí está disponible** siempre

## Activar

```bash
php artisan kore:tenancy:enable
```

Hace lo siguiente en orden:

1. Publica `tenancy-config` → `config/tenancy.php`
2. Publica `tenancy-migrations` → `database/migrations/`
3. Escribe `TENANCY_ENABLED=true` en `.env` (preserva o agrega)
4. Limpia `config:clear`
5. Imprime los próximos pasos:
   ```
   1. Revisa config/tenancy.php para personalizar
   2. php artisan migrate           # crea tabla tenants
   3. php artisan tenants:create    # crea tu primer tenant
   4. Docs: https://tenancyforlaravel.com/docs/v3/
   ```

Flag útil: `--force` para sobrescribir configs/migraciones existentes.

## Desactivar

```bash
# en .env
TENANCY_ENABLED=false

php artisan config:clear
```

El módulo deja de bootear. Las migraciones publicadas y `config/tenancy.php` quedan en el repo (no las borramos automáticamente; eliminar manual si se desea).

## Modos

> **No hay `TENANCY_MODE` en `.env`.** El único toggle del boilerplate es
> `TENANCY_ENABLED`. El modo se decide al ejecutar `kore:tenancy:enable`, que
> publica `config/tenancy.php`: es la lista de `bootstrappers` de stancl la que
> determina si cada tenant tiene su propia base de datos o si comparten una.
> Existió una clave `kore-app.tenancy.mode` que no leía nadie; se borró en v1.0.0.

### single-db (default — row-based)

Todos los tenants comparten la misma DB; las filas se scopean por `tenant_id`.
Ventajas: barato, simple. Desventaja: los bugs de scoping son catastróficos (data leak).

Es lo que obtienes si dejas `config/tenancy.php` sin el `DatabaseTenancyBootstrapper`.
Aplicas el trait `BelongsToTenant` (de stancl) en cada modelo de negocio.

### multi-db

Cada tenant tiene su propia base de datos. Ventajas: aislamiento total, backups
por tenant, compliance. Desventaja: muchas conexiones, costo, deploys más cuidadosos.

Se activa dejando `Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper`
en `config/tenancy.php` (`bootstrappers`) y configurando `tenancy.database`.

## Modelo Tenant

`app/Modules/Tenancy/Models/Tenant.php`:

```php
use Stancl\Tenancy\Database\Models\Tenant as StanclTenant;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

final class Tenant extends StanclTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'plan', 'created_at', 'updated_at'];
    }
}
```

## Rutas tenant

`app/Modules/Tenancy/Routes/tenant.php` (cargada sólo cuando ON):

```php
Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    Route::get('/', fn () => view('welcome'))->name('tenant.home');
});
```

## Cómo está implementado el toggle

`app/Modules/Tenancy/Providers/TenancyModuleServiceProvider.php`:

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

public function boot(): void
{
    if (! $this->isTenancyEnabled()) {
        return;
    }

    $base = __DIR__.'/..';

    $this->loadJsonTranslationsFrom("{$base}/Resources/lang");

    // `kore:tenancy:enable` publica aquí las migraciones de stancl. En un
    // clon fresco la carpeta puede no existir todavía (sólo lleva .gitkeep),
    // y loadMigrationsFrom() con una ruta inexistente revienta al migrar.
    if (is_dir($migrations = "{$base}/Database/Migrations")) {
        $this->loadMigrationsFrom($migrations);
    }

    if (file_exists($routes = "{$base}/Routes/tenant.php")) {
        $this->loadRoutesFrom($routes);
    }
}

private function isTenancyEnabled(): bool
{
    return (bool) config('kore-app.tenancy.enabled', false);
}
```

`composer.json` tiene `stancl/tenancy` en `extra.laravel.dont-discover` para que NO se registre automáticamente — sólo cuando nuestro provider lo decide.

Las dos guardas del `boot()` no son adorno: en un clon fresco `Database/Migrations/`
sólo contiene un `.gitkeep` y `Routes/tenant.php` no existe hasta que corre
`kore:tenancy:enable`. Sin ellas, encender el toggle antes de publicar revienta
el primer `php artisan migrate`.

Registrar `EnableTenancyCommand` **antes** del early return es la única excepción
a R10 («un toggle apagado no registra nada»), y está escrita como tal en el
catálogo: sin ella, `TENANCY_ENABLED=false` no tendría forma de encenderse.

## Tests del toggle

`app/Modules/Tenancy/Tests/Feature/TenancyToggleTest.php` verifica:

- Cuando `TENANCY_ENABLED=false`, `Stancl\Tenancy\TenancyServiceProvider` NO está
  cargado (y `TenancyModuleServiceProvider` sí existe, para distinguir «apagado»
  de «roto»).
- El comando `kore:tenancy:enable` está expuesto **siempre**, con el toggle
  apagado incluido: es la excepción documentada de R10.
- Con el toggle apagado, el router no tiene ninguna ruta llamada `tenant.home`,
  que es la que carga `Routes/tenant.php`.

## Activar en producción (Docker)

```bash
docker compose -f docker-compose.prod.yml exec app php artisan kore:tenancy:enable
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml restart app queue scheduler
```

Confirmar en `.env` del VPS que `TENANCY_ENABLED=true`, y en `config/tenancy.php` que los `bootstrappers` son los del modo que quieres.

## Recursos

- Docs oficiales: https://tenancyforlaravel.com/docs/v3/
- Comparación con Spatie: https://tenancyforlaravel.com/docs/v3/package-comparison/

## Nota Laravel 13 · precedencia de rutas con dominio

Laravel 13 registra **antes** las rutas con dominio explícito (`->domain()`) que
las que no lo tienen. `Routes/tenant.php` identifica el tenant por middleware
(`InitializeTenancyByDomain`), no por `->domain()`, así que en principio no le
afecta; pero es lo primero que hay que comprobar tras `kore:tenancy:enable` si
alguna ruta central deja de resolver. `stancl/tenancy` 3.10 declara soporte de
Laravel 13; no hay v4 estable (v2.0.0).
