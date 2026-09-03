<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ModulesSeeder::class);

        // Idempotente a propósito: `db:seed` se corre a mano en producción y a
        // veces dos veces (un deploy repetido, un `--seed` sobre una base ya
        // sembrada). Con `User::factory()->create()` a secas, la segunda vez
        // reventaba con una violación de la clave única de `email`.
        // `ModulesSeeder` ya usa updateOrCreate/firstOrCreate.
        $admin = User::query()->firstWhere('email', 'admin@example.com')
            ?? User::factory()->create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
            ]);

        $admin->assignRole(Role::ADMIN);
        $admin->syncPermissions(Permission::all());
    }
}
