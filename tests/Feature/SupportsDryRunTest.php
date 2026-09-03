<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| App\Core\Console\Concerns\SupportsDryRun
|--------------------------------------------------------------------------
|
| El trait pone la opción y los dos helpers; el comando decide qué contar. Lo
| que se prueba aquí es lo que hace útil el ensayo: que `--dry-run` exista sin
| declararlo en la firma, que informe, y —lo importante— que no escriba nada.
|
| El comando de guardia es `kore:regenerate-permissions`, que reescribe los
| permisos de todas las cuentas de administración.
|
*/

it('añade --dry-run sin que el comando lo declare en su firma', function (): void {
    $definition = Artisan::all()['kore:regenerate-permissions']->getDefinition();

    expect($definition->hasOption('dry-run'))->toBeTrue()
        ->and($definition->getOption('dry-run')->acceptValue())->toBeFalse();
});

it('deja intacta la firma original del comando', function (): void {
    expect(Artisan::all()['kore:regenerate-permissions']->getName())
        ->toBe('kore:regenerate-permissions');
});

it('informa de lo que haría y no escribe nada', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $admin->syncPermissions([]);

    $permissions = Module::where('active', true)->get()->flatPermissions();

    $exit = Artisan::call('kore:regenerate-permissions', ['--dry-run' => true]);

    // `components->warn()` parte la línea al ancho de la terminal, así que se
    // normalizan los espacios antes de buscar el mensaje.
    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($exit)->toBe(0)
        ->and($output)->toContain('Simulacro (--dry-run)')
        ->and($output)->toContain(sprintf('los %d permiso(s) de hoy a 1 Administrador(es)', count($permissions)))
        ->and($admin->fresh()?->getDirectPermissions())->toBeEmpty();
});

it('sin --dry-run sí sincroniza', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $admin->syncPermissions([]);

    $this->artisan('kore:regenerate-permissions')->assertSuccessful();

    expect($admin->fresh()?->getDirectPermissions())->not->toBeEmpty();
});
