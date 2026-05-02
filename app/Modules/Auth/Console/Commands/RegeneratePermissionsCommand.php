<?php

declare(strict_types=1);

namespace App\Modules\Auth\Console\Commands;

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Illuminate\Console\Command;

/**
 * Re-corre el seeder de módulos/permisos/roles y sincroniza a todos los
 * Administradores los permisos vigentes. Útil al agregar un módulo nuevo
 * o cambiar el set de permisos.
 */
final class RegeneratePermissionsCommand extends Command
{
    protected $signature = 'kore:regenerate-permissions';

    protected $description = 'Regenera modules/permissions y sincroniza permisos a todos los Administradores';

    public function handle(): int
    {
        $this->call(ModulesSeeder::class);

        $permissions = Module::where('active', true)->get()->flatPermissions();

        User::role(Role::ADMIN)->each(function (User $admin) use ($permissions): void {
            $admin->syncPermissions($permissions);
        });

        $this->info('✔ Permisos regenerados y sincronizados con los Administradores.');

        return self::SUCCESS;
    }
}
