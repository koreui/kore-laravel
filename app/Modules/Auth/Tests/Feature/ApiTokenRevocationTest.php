<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Events\ApiTokenRevoked;
use App\Modules\Auth\Models\Role;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/*
|--------------------------------------------------------------------------
| R26 por API — cambiar permisos retira los tokens
|--------------------------------------------------------------------------
|
| Las abilities de un token de Sanctum se congelan al emitirlo. Sin esta
| revocación, degradar a alguien en la pantalla de usuarios le quita el botón
| del navegador y no le quita nada de la API: su móvil seguiría presentando un
| token con `users.delete` dentro hasta que caducara.
|
| Lo hace `App\Modules\Auth\Listeners\RevokeApiTokensOnPermissionChange`,
| cableado en `AuthModuleServiceProvider` sobre los cuatro eventos de
| spatie/laravel-permission.
|
*/

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
});

it('mantiene encendidos los eventos de spatie, sin los cuales el listener no corre nunca', function (): void {
    // El default del paquete es `false`. Con esa clave apagada este archivo
    // entero pasaría a verde por la vía equivocada: sin eventos no hay
    // listener, y sin listener no hay nada que probar.
    expect(config('permission.events_enabled'))->toBeTrue();
});

it('cablea el listener sobre los cuatro eventos', function (): void {
    foreach ([RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class] as $event) {
        expect(Event::hasListeners($event))->toBeTrue("Nadie escucha {$event}");
    }
});

it('retira los tokens al asignar un rol', function (): void {
    $user = User::factory()->create();
    $user->createToken('Móvil');
    $user->createToken('Tablet');

    $user->assignRole(Role::ADMIN);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('retira los tokens al quitar un rol', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);

    $user->createToken('Móvil');

    $user->removeRole(Role::ADMIN);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('retira los tokens al conceder un permiso directo', function (): void {
    $user = User::factory()->create();
    $user->createToken('Móvil');

    $user->givePermissionTo('users.view');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('retira los tokens al revocar un permiso directo', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('users.view');

    $user->createToken('Móvil');

    $user->revokePermissionTo('users.view');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('dispara ApiTokenRevoked con la razón permissions_changed', function (): void {
    $user = User::factory()->create();
    $user->createToken('Móvil');

    Event::fake([ApiTokenRevoked::class]);

    $user->assignRole(Role::ADMIN);

    Event::assertDispatched(ApiTokenRevoked::class, fn (ApiTokenRevoked $event): bool => $event->user->is($user)
        && $event->tokenId === null
        && $event->reason === 'permissions_changed');
});

it('no toca los tokens de los demás usuarios', function (): void {
    $degradado = User::factory()->create();
    $degradado->createToken('Móvil');

    $ajeno = User::factory()->create();
    $ajeno->createToken('Portátil');

    $degradado->assignRole(Role::ADMIN);

    expect(PersonalAccessToken::query()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->firstOrFail()->tokenable_id)->toBe($ajeno->getKey());
});

it('el token retirado deja de abrir la API', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);

    $token = $user->createToken('Móvil', ['users.view'])->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.users.index'))->assertOk();

    $user->removeRole(Role::ADMIN);

    // El guard cachea el usuario que resolvió en la petición anterior; en
    // producción cada petición trae su aplicación.
    auth()->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.users.index'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('no dispara nada cuando el usuario no tenía tokens', function (): void {
    Event::fake([ApiTokenRevoked::class]);

    User::factory()->create()->assignRole(Role::ADMIN);

    Event::assertNotDispatched(ApiTokenRevoked::class);
});

it('ignora los cambios de permisos de un rol, que son cosa del despliegue', function (): void {
    // `ModulesSeeder` y `kore:auth:permissions` sincronizan los permisos de
    // todos los roles en cada despliegue. Si el listener reaccionara a eso,
    // añadir un módulo echaría a toda la plantilla de sus móviles.
    $user = User::factory()->create();
    $user->assignRole(Role::ADMIN);
    $user->createToken('Móvil');

    Role::findByName(Role::ADMIN)->revokePermissionTo('users.view');

    expect(PersonalAccessToken::query()->count())->toBe(1);
});
