<?php

declare(strict_types=1);

use App\Providers\BackupServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\BackupServiceProvider as SpatieBackupServiceProvider;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Facades\Health;

/**
 * Arranca la aplicación con BACKUP_ENABLED=true (más lo que se le pase) y
 * ejecuta el callback. Ver `withEnvironment()` en tests/Pest.php.
 *
 * @param array<string, string> $env variables extra para este arranque
 */
function withBackupEnabled(Closure $callback, array $env = []): void
{
    withEnvironment(['BACKUP_ENABLED' => 'true', ...$env], $callback);
}

/*
|--------------------------------------------------------------------------
| Toggle apagado (estado por defecto de la suite)
|--------------------------------------------------------------------------
*/

it('does not register the spatie backup provider when the toggle is off', function (): void {
    expect(config('kore-app.backup.enabled'))->toBeFalse()
        ->and(array_keys(app()->getLoadedProviders()))
        ->not->toContain(SpatieBackupServiceProvider::class);
});

it('does not expose the backup commands when the toggle is off', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('backup:run');
});

it('does not schedule anything backup related when the toggle is off', function (): void {
    $commands = collect(resolve(Schedule::class)->events())
        ->map(fn (object $event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'backup:')))->toBeFalse();
});

it('does not register the backups health check when the toggle is off', function (): void {
    expect(Health::registeredChecks()->contains(fn (Check $check): bool => $check instanceof BackupsCheck))
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Toggle encendido
|--------------------------------------------------------------------------
*/

it('registers the spatie backup provider when the toggle is on', function (): void {
    withBackupEnabled(function (): void {
        expect(config('kore-app.backup.enabled'))->toBeTrue()
            ->and(array_keys(app()->getLoadedProviders()))
            ->toContain(SpatieBackupServiceProvider::class);
    });
});

it('exposes the backup commands when the toggle is on', function (): void {
    withBackupEnabled(function (): void {
        expect(array_keys(Artisan::all()))
            ->toContain('backup:run')
            ->toContain('backup:clean')
            ->toContain('backup:monitor')
            ->toContain('backup:list');
    });
});

it('schedules the backup commands when the toggle is on', function (string $needle): void {
    withBackupEnabled(function () use ($needle): void {
        $scheduled = collect(resolve(Schedule::class)->events())
            ->map(fn (object $event): string => (string) $event->command)
            ->contains(fn (string $command): bool => str_contains($command, $needle));

        expect($scheduled)->toBeTrue();
    });
})->with([
    'backup:clean',
    'backup:run',
    'backup:monitor',
]);

it('monitors the very destination the backups are written to', function (): void {
    withBackupEnabled(function (): void {
        expect(config('backup.monitor_backups.0.disks'))->toBe(config('backup.backup.destination.disks'))
            ->and(config('backup.monitor_backups.0.name'))->toBe(config('backup.backup.name'));
    });
});

it('splits BACKUP_DISKS into the destination and the monitor alike', function (): void {
    withBackupEnabled(function (): void {
        expect(config('backup.backup.destination.disks'))->toBe(['local', 's3'])
            ->and(config('backup.monitor_backups.0.disks'))->toBe(['local', 's3']);
    }, ['BACKUP_DISKS' => 'local, s3']);
});

it('keeps the backups out of their own source directory', function (): void {
    withBackupEnabled(function (): void {
        $name = config('backup.backup.name');

        expect(config('backup.backup.source.files.include'))
            ->toContain(storage_path('app/private'))
            ->and(config('backup.backup.source.files.exclude'))
            ->toContain(storage_path('app/private/'.$name));
    });
});

it('registers the backups health check when the toggle is on', function (): void {
    withBackupEnabled(function (): void {
        expect(Health::registeredChecks()->contains(fn (Check $check): bool => $check instanceof BackupsCheck))
            ->toBeTrue();
    });
});

it('points the backups health check at the folder the zips land in', function (): void {
    withBackupEnabled(function (): void {
        // El check se construye en el boot del provider, así que hay que
        // rehacerlo contra el disco falso. `clearChecks()` evita el error de
        // nombres duplicados de Health::checks().
        Storage::fake('local');
        Health::clearChecks();
        app()->register(BackupServiceProvider::class, force: true);

        Storage::disk('local')->put(config('backup.backup.name').'/2026-09-03-02-00-00.zip', 'zip');

        /** @var BackupsCheck $check */
        $check = Health::registeredChecks()->first(fn (Check $check): bool => $check instanceof BackupsCheck);

        // `Status` es un spatie/enum, no un enum nativo: se compara por su
        // valor en string para no arrastrar `Status::ok()`, que Rector reescribe
        // por error a una constante que no existe.
        expect((string) $check->run()->status)->toBe('ok');
    });
});

/*
|--------------------------------------------------------------------------
| Cifrado del zip
|--------------------------------------------------------------------------
*/

it('encrypts the backup archive when BACKUP_ARCHIVE_PASSWORD is set', function (): void {
    withBackupEnabled(function (): void {
        expect(encryptionMethodOfFilesBackup())->not->toBe(ZipArchive::EM_NONE);
    }, ['BACKUP_ARCHIVE_PASSWORD' => 'un-secreto-de-pruebas']);
});

it('leaves the backup archive in the clear without a password', function (): void {
    withBackupEnabled(function (): void {
        expect(encryptionMethodOfFilesBackup())->toBe(ZipArchive::EM_NONE);
    });
});

/**
 * Corre `backup:run --only-files` contra un disco falso y devuelve el método de
 * cifrado de la primera entrada del zip resultante.
 *
 * `--only-files` evita depender de `sqlite3` / `mysqldump` en la máquina que
 * corre la suite, y las notificaciones se apagan por partida doble (opción del
 * comando y config vacía) para que ningún canal intente resolver el mailer.
 */
function encryptionMethodOfFilesBackup(): int
{
    Storage::fake('local');

    $source = storage_path('framework/testing/backup-source-'.Str::random(8));
    mkdir($source, recursive: true);
    file_put_contents($source.'/kore.txt', 'contenido de prueba');

    try {
        Config::set('backup.backup.source.files.include', [$source]);
        Config::set('backup.backup.source.files.exclude', []);
        Config::set('backup.backup.source.databases', []);
        Config::set('backup.notifications.notifications', []);

        Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

        $archives = Storage::disk('local')->allFiles(config('backup.backup.name'));

        expect($archives)->toHaveCount(1);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($archives[0]));

        /** @var array{encryption_method: int} $stat */
        $stat = $zip->statIndex(0);
        $zip->close();

        return $stat['encryption_method'];
    } finally {
        array_map(unlink(...), glob($source.'/*') ?: []);
        rmdir($source);
    }
}
