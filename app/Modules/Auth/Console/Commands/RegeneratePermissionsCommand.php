<?php

declare(strict_types=1);

namespace App\Modules\Auth\Console\Commands;

use App\Core\Console\Concerns\SupportsDryRun;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-corre el seeder de módulos/permisos/roles y sincroniza a todos los
 * Administradores los permisos vigentes. Útil al agregar un módulo nuevo
 * o cambiar el set de permisos.
 *
 * Con `--dry-run` no toca nada: dice cuántos permisos hay hoy y a cuántos
 * Administradores se los sincronizaría. Es el comando del boilerplate que más
 * se parece a los que un derivado va a correr en producción a ciegas —reescribe
 * los permisos de **todas** las cuentas de administración— y por eso es el que
 * estrena `SupportsDryRun`.
 */
#[Description('Regenera modules/permissions y sincroniza permisos a todos los Administradores')]
#[Signature('kore:regenerate-permissions')]
final class RegeneratePermissionsCommand extends Command
{
    use SupportsDryRun;

    public function handle(): int
    {
        if ($this->isDryRun()) {
            return $this->reportWithoutWriting();
        }

        $this->call(ModulesSeeder::class);

        $permissions = Module::where('active', true)->get()->flatPermissions();

        User::role(Role::ADMIN)->each(function (User $admin) use ($permissions): void {
            $admin->syncPermissions($permissions);
        });

        $this->info('✔ Permisos regenerados y sincronizados con los Administradores.');

        return self::SUCCESS;
    }

    /**
     * El ensayo: se cuenta sobre el estado actual, sin correr el seeder.
     *
     * No es el mismo número que después de sembrar —un módulo nuevo todavía no
     * existe en la base— y por eso el mensaje habla de «los permisos de hoy».
     * Lo que el ensayo responde es la pregunta que se hace antes de pulsar:
     * a cuántas cuentas les va a cambiar el set de permisos.
     */
    private function reportWithoutWriting(): int
    {
        $permissions = Module::where('active', true)->get()->flatPermissions();
        $admins = User::role(Role::ADMIN)->count();

        $this->dryRunNotice(sprintf(
            'se re-correría ModulesSeeder y se sincronizarían los %d permiso(s) de hoy a %d Administrador(es).',
            count($permissions),
            $admins,
        ));

        return self::SUCCESS;
    }
}
