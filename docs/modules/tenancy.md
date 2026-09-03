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
    // El comando vive siempre disponible
    $this->commands([EnableTenancyCommand::class]);

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

    $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    $this->loadRoutesFrom(__DIR__.'/../Routes/tenant.php');
}

private function isTenancyEnabled(): bool
{
    return (bool) config('kore-app.tenancy.enabled', false);
}
```

`composer.json` tiene `stancl/tenancy` en `extra.laravel.dont-discover` para que NO se registre automáticamente — sólo cuando nuestro provider lo decide.

## Tests del toggle

`app/Modules/Tenancy/Tests/Feature/TenancyToggleTest.php` verifica:

- Cuando `TENANCY_ENABLED=false`, `Stancl\Tenancy\TenancyServiceProvider` NO está cargado.
- El comando `kore:tenancy:enable` siempre está expuesto.
- Las rutas tenant (`tenant.home`) NO existen cuando OFF.

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
