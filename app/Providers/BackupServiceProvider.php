<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\ServiceProvider;
use Override;
use Spatie\Backup\BackupServiceProvider as SpatieBackupServiceProvider;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Facades\Health;

/**
 * spatie/laravel-backup detrás del toggle `BACKUP_ENABLED` (R10, R11).
 *
 * El provider del paquete está en `extra.laravel.dont-discover` del
 * composer.json —igual que stancl/tenancy—, así que Laravel no lo autodescubre:
 * lo registramos aquí y sólo cuando el toggle está encendido. Con el toggle
 * apagado no existe ni `backup:run` ni el check de salud, y el scheduler
 * (`routes/console.php`) tampoco programa nada.
 */
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

    /**
     * `backup:monitor` avisa por correo; este check pone lo mismo en /health,
     * que es donde mira un monitor externo.
     *
     * Se registra desde aquí y no desde HealthServiceProvider porque
     * `Health::checks()` **acumula** (hace `array_merge` sobre los checks ya
     * registrados, ver Spatie\Health\Health::checks()): llamarlo dos veces suma,
     * no sustituye. Así el check vive junto al toggle que lo enciende.
     *
     * Vigila el PRIMER disco de `backup.backup.destination.disks` y la carpeta
     * `backup.backup.name`, que es exactamente donde el paquete deja los zips.
     * Ojo con dos cosas:
     *
     * - `locatedAt()` NO es un glob cuando se combina con `onDisk()`: el check
     *   hace `listContents()` sobre esa ruta, así que tiene que ser la carpeta
     *   (`kore-laravel`), no un patrón (`kore-laravel/*.zip`), que no listaría
     *   nada y daría «No backups found» siempre.
     * - `onDisk()` resuelve el disco (`Storage::disk()`) al arrancar, así que el
     *   primer disco de BACKUP_DISKS tiene que ser resoluble. Deja `local` el
     *   primero salvo que hayas instalado el adaptador correspondiente.
     */
    private function registerHealthCheck(): void
    {
        $disks = array_values(array_filter(
            (array) config('backup.backup.destination.disks', []),
            is_string(...),
        ));

        Health::checks([
            BackupsCheck::new()
                ->onDisk($disks[0] ?? 'local')
                ->locatedAt((string) config('backup.backup.name', 'kore-laravel'))
                ->youngestBackShouldHaveBeenMadeBefore(CarbonImmutable::now()->subDay()),
        ]);
    }
}
