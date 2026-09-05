<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Platform\Database\Seeders\PlatformSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Datos deterministas para la suite E2E (Playwright).
 *
 * Lo ejecuta `tests/e2e/global-setup.ts` sobre `database/e2e.sqlite`:
 *
 *   APP_ENV=e2e php artisan migrate:fresh --seed \
 *     --seeder=Database\\Seeders\\E2eSeeder --force
 *
 * Cuatro cuentas, una por nivel de autorización, todas con email verificado
 * y contraseña `password`. Los specs NUNCA modifican estas cuentas: los datos
 * que un test necesita cambiar los crea el propio test con un email único.
 *
 * | Email                | Rol         | Permisos directos                   |
 * |----------------------|-------------|-------------------------------------|
 * | superadmin@e2e.test  | superadmin  | bypass total (Gate::before)         |
 * | editor@e2e.test      | Usuario     | users.view, users.create, users.edit |
 * | viewer@e2e.test      | Usuario     | users.view                          |
 * | member@e2e.test      | Usuario     | ninguno (sólo dashboard.view del rol)|
 */
final class E2eSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string PASSWORD = 'password';

    public function run(): void
    {
        $this->call(ModulesSeeder::class);
        $this->call(PlatformSeeder::class);

        // superadmin: el rol dispara el Gate::before de
        // AuthModuleServiceProvider, así que pasa cualquier @can/authorize().
        $superadmin = User::factory()->create([
            'name' => 'E2E Superadmin',
            'email' => 'superadmin@e2e.test',
        ]);
        $superadmin->syncRoles([Role::SUPERADMIN]);

        // editor: CRUD de usuarios sin borrar.
        $editor = User::factory()->create([
            'name' => 'E2E Editor',
            'email' => 'editor@e2e.test',
        ]);
        $editor->syncRoles([Role::USER]);
        $editor->syncPermissions(['users.view', 'users.create', 'users.edit']);

        // viewer: sólo lectura del listado.
        $viewer = User::factory()->create([
            'name' => 'E2E Viewer',
            'email' => 'viewer@e2e.test',
        ]);
        $viewer->syncRoles([Role::USER]);
        $viewer->syncPermissions(['users.view']);

        // member: sin permisos del módulo Users; sólo dashboard.
        $member = User::factory()->create([
            'name' => 'E2E Member',
            'email' => 'member@e2e.test',
        ]);
        $member->syncRoles([Role::USER]);
        $member->syncPermissions([]);
    }
}
