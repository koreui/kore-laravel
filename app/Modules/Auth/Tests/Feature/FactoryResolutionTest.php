<?php

declare(strict_types=1);

use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Auth\Database\Factories\ModuleFactory;
use App\Modules\Auth\Database\Factories\RoleFactory;
use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `AppServiceProvider::configureFactories()` enseña a Laravel la convención de
 * factories por módulo. Sin ella, `Module::factory()` buscaría
 * `Database\Factories\ModuleFactory` y reventaría.
 */
it('resolves module factories inside the module', function (): void {
    expect(Factory::resolveFactoryName(Module::class))->toBe(ModuleFactory::class)
        ->and(Factory::resolveFactoryName(Role::class))->toBe(RoleFactory::class);
});

it('keeps resolving global models to database/factories', function (): void {
    expect(Factory::resolveFactoryName(User::class))->toBe(UserFactory::class);
});

it('creates a module with its factory', function (): void {
    $module = Module::factory()->create();

    expect($module->exists)->toBeTrue()
        ->and($module->active)->toBeTrue()
        ->and($module->permissions)->toHaveCount(4);

    expect(Module::factory()->inactive()->create()->active)->toBeFalse();
});

it('creates a role with its factory', function (): void {
    $role = Role::factory()->create();

    expect($role->exists)->toBeTrue()
        ->and($role->guard_name)->toBe('web');

    expect(Role::factory()->system(SystemRole::Admin)->create()->name)
        ->toBe(SystemRole::Admin->value);
});
