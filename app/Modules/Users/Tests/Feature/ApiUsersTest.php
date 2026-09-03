<?php

declare(strict_types=1);

use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Events\UserCreated;
use App\Modules\Users\Events\UserDeleted;
use App\Modules\Users\Events\UserUpdated;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| CRUD de usuarios por API — api/v1/users
|--------------------------------------------------------------------------
|
| El endpoint de referencia del boilerplate, con las dos barreras que lo
| protegen probadas por separado:
|
|   - la ability del token (`abilities:users.edit`), que dice qué se le concedió
|     a ESTE token cuando se emitió;
|   - la Policy (`$this->authorize(...)`), que dice qué puede ESTE usuario ahora
|     mismo sobre ESTE registro.
|
| `Sanctum::actingAs($user, $abilities)` es lo que permite separarlas: un usuario
| con todos los permisos y un token sin abilities tiene que salir 403.
|
*/

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
});

/**
 * Un usuario con el rol indicado, actuando con un token que lleva justo las
 * abilities pedidas. Sin `$abilities`, el token lleva los permisos efectivos
 * del usuario, que es lo que hace el login real.
 *
 * @param array<int, string>|null $abilities
 */
function actingAsUserWith(string $role, ?array $abilities = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    Sanctum::actingAs(
        $user,
        $abilities ?? $user->getAllPermissions()->pluck('name')->values()->all(),
    );

    return $user;
}

/*
|--------------------------------------------------------------------------
| Sin token
|--------------------------------------------------------------------------
*/

it('rechaza al invitado en todos los verbos', function (): void {
    $target = User::factory()->create();

    $peticiones = [
        ['GET', route('api.v1.users.index')],
        ['GET', route('api.v1.users.show', $target)],
        ['POST', route('api.v1.users.store')],
        ['PUT', route('api.v1.users.update', $target)],
        ['DELETE', route('api.v1.users.destroy', $target)],
    ];

    foreach ($peticiones as [$method, $uri]) {
        $this->json($method, $uri)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
});

/*
|--------------------------------------------------------------------------
| index
|--------------------------------------------------------------------------
*/

it('lista usuarios con el envelope y el meta del contrato', function (): void {
    actingAsUserWith(Role::ADMIN);
    User::factory()->count(3)->create();

    $this->getJson(route('api.v1.users.index'))
        ->assertOk()
        ->assertExactJsonStructure([
            'data' => ['*' => ['id', 'name', 'email', 'roles', 'permissions', 'created_at']],
            'meta' => ['next_cursor', 'prev_cursor', 'per_page'],
        ]);
});

it('pagina por cursor y no repite ni se salta filas', function (): void {
    actingAsUserWith(Role::ADMIN);
    User::factory()->count(5)->create();

    $primera = $this->getJson(route('api.v1.users.index', ['per_page' => 2]))->assertOk();

    expect($primera->json('data'))->toHaveCount(2)
        ->and($primera->json('meta.per_page'))->toBe(2)
        ->and($primera->json('meta.next_cursor'))->not->toBeNull();

    $segunda = $this->getJson(route('api.v1.users.index', [
        'per_page' => 2,
        'cursor' => $primera->json('meta.next_cursor'),
    ]))->assertOk();

    $ids = array_merge(
        array_column((array) $primera->json('data'), 'id'),
        array_column((array) $segunda->json('data'), 'id'),
    );

    expect($ids)->toHaveCount(4)->toBe(array_values(array_unique($ids)));
});

it('acota per_page al máximo del contrato', function (): void {
    actingAsUserWith(Role::ADMIN);

    $this->getJson(route('api.v1.users.index', ['per_page' => 100000]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', config('kore-api.pagination.max'));
});

it('filtra por search sobre nombre y email', function (): void {
    actingAsUserWith(Role::ADMIN);

    User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);

    $porNombre = $this->getJson(route('api.v1.users.index', ['search' => 'Lovelace']))->assertOk();
    $porEmail = $this->getJson(route('api.v1.users.index', ['search' => 'grace@']))->assertOk();

    expect($porNombre->json('data'))->toHaveCount(1)
        ->and($porNombre->json('data.0.name'))->toBe('Ada Lovelace')
        ->and($porEmail->json('data'))->toHaveCount(1)
        ->and($porEmail->json('data.0.name'))->toBe('Grace Hopper');
});

it('filtra por rol', function (): void {
    $admin = actingAsUserWith(Role::ADMIN);

    $usuario = User::factory()->create();
    $usuario->assignRole(SystemRole::User->value);

    $response = $this->getJson(route('api.v1.users.index', ['role' => SystemRole::User->value]))->assertOk();

    expect(array_column((array) $response->json('data'), 'id'))
        ->toBe([$usuario->getKey()])
        ->not->toContain($admin->getKey());
});

it('no publica a los superadmins en el listado', function (): void {
    actingAsUserWith(Role::ADMIN);

    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $response = $this->getJson(route('api.v1.users.index'))->assertOk();

    // Misma regla que `TableUsers`: es un rol que sólo se asigna por consola, y
    // publicarlo sería regalarle a cualquiera la lista de las cuentas que más
    // interesa atacar.
    expect(array_column((array) $response->json('data'), 'id'))->not->toContain($superadmin->getKey());
});

it('tampoco publica al superadmin para otro superadmin', function (): void {
    actingAsUserWith(Role::SUPERADMIN);

    $otro = User::factory()->create();
    $otro->assignRole(Role::SUPERADMIN);

    $response = $this->getJson(route('api.v1.users.index'))->assertOk();

    expect(array_column((array) $response->json('data'), 'id'))->not->toContain($otro->getKey());
});

it('rechaza el listado sin la ability del token', function (): void {
    // El usuario SÍ tiene el permiso; el token con el que llega, no.
    actingAsUserWith(Role::ADMIN, abilities: []);

    $this->getJson(route('api.v1.users.index'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('rechaza el listado a quien tiene la ability pero no el permiso', function (): void {
    // Y al revés: el token pide `users.view` y lo lleva, pero su dueño perdió
    // el permiso. La Policy es la que dice que no (R25).
    actingAsUserWith(SystemRole::User->value, abilities: ['users.view']);

    $this->getJson(route('api.v1.users.index'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

/*
|--------------------------------------------------------------------------
| show
|--------------------------------------------------------------------------
*/

it('devuelve un usuario', function (): void {
    actingAsUserWith(Role::ADMIN);
    $target = User::factory()->create();

    $this->getJson(route('api.v1.users.show', $target))
        ->assertOk()
        ->assertJsonPath('data.id', $target->getKey())
        ->assertJsonPath('data.email', $target->email)
        ->assertExactJsonStructure(['data' => ['id', 'name', 'email', 'roles', 'permissions', 'created_at']]);
});

it('no filtra ningún atributo del modelo fuera de la lista blanca', function (): void {
    actingAsUserWith(Role::ADMIN);
    $target = User::factory()->create();

    expect(array_keys((array) $this->getJson(route('api.v1.users.show', $target))->json('data')))
        ->toBe(['id', 'name', 'email', 'roles', 'permissions', 'created_at']);
});

it('devuelve 404 con el código canónico cuando el usuario no existe', function (): void {
    actingAsUserWith(Role::ADMIN);

    $this->getJson(route('api.v1.users.show', 999999))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('rechaza ver un usuario sin la ability', function (): void {
    actingAsUserWith(Role::ADMIN, abilities: ['users.create']);

    $this->getJson(route('api.v1.users.show', User::factory()->create()))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

/*
|--------------------------------------------------------------------------
| store
|--------------------------------------------------------------------------
*/

it('crea un usuario con su rol y sus permisos', function (): void {
    Event::fake([UserCreated::class]);

    actingAsUserWith(Role::ADMIN);

    $response = $this->postJson(route('api.v1.users.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'password-larga',
        'password_confirmation' => 'password-larga',
        'role' => SystemRole::User->value,
        'permissions' => ['users.view'],
    ])->assertCreated();

    $response->assertJsonPath('data.name', 'Ada Lovelace')
        ->assertJsonPath('data.email', 'ada@example.test')
        ->assertJsonPath('data.roles', [SystemRole::User->value]);

    expect($response->json('data.permissions'))->toContain('users.view');

    $creado = User::query()->where('email', 'ada@example.test')->firstOrFail();

    expect($creado->hasRole(SystemRole::User->value))->toBeTrue();

    Event::assertDispatched(UserCreated::class);
});

it('devuelve 422 con details cuando la validación falla', function (): void {
    actingAsUserWith(Role::ADMIN);

    $this->postJson(route('api.v1.users.store'), ['name' => 'Sin email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email', 'password', 'role']]]);
});

it('rechaza un email repetido', function (): void {
    actingAsUserWith(Role::ADMIN);
    $existente = User::factory()->create();

    $this->postJson(route('api.v1.users.store'), [
        'name' => 'Otro',
        'email' => $existente->email,
        'password' => 'password-larga',
        'password_confirmation' => 'password-larga',
        'role' => SystemRole::User->value,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('rechaza crear sin la ability del token', function (): void {
    actingAsUserWith(Role::ADMIN, abilities: ['users.view']);

    $this->postJson(route('api.v1.users.store'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-larga',
        'password_confirmation' => 'password-larga',
        'role' => SystemRole::User->value,
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| R26 · nadie concede lo que no tiene, tampoco por API
|--------------------------------------------------------------------------
*/

it('no deja conceder por API un permiso que el actor no tiene', function (): void {
    $actor = actingAsUserWith(SystemRole::User->value, abilities: ['users.create', 'users.edit']);
    $actor->givePermissionTo(['users.create', 'users.edit']);

    Sanctum::actingAs($actor, ['users.create']);

    $this->postJson(route('api.v1.users.store'), [
        'name' => 'Escalada',
        'email' => 'escalada@example.test',
        'password' => 'password-larga',
        'password_confirmation' => 'password-larga',
        'role' => SystemRole::User->value,
        'permissions' => ['users.delete'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['permissions.0']]]);

    expect(User::query()->where('email', 'escalada@example.test')->exists())->toBeFalse();
});

it('no deja asignar por API un rol más poderoso que el actor', function (): void {
    $actor = actingAsUserWith(SystemRole::User->value, abilities: ['users.create']);
    $actor->givePermissionTo('users.create');

    Sanctum::actingAs($actor, ['users.create']);

    $this->postJson(route('api.v1.users.store'), [
        'name' => 'Escalada',
        'email' => 'escalada@example.test',
        'password' => 'password-larga',
        'password_confirmation' => 'password-larga',
        'role' => SystemRole::Admin->value,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['role']]]);
});

/*
|--------------------------------------------------------------------------
| update
|--------------------------------------------------------------------------
*/

it('edita un usuario', function (): void {
    Event::fake([UserUpdated::class]);

    actingAsUserWith(Role::ADMIN);
    $target = User::factory()->create();

    $this->putJson(route('api.v1.users.update', $target), [
        'name' => 'Nombre nuevo',
        'email' => $target->email,
        'role' => SystemRole::User->value,
        'permissions' => [],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nombre nuevo');

    expect($target->refresh()->name)->toBe('Nombre nuevo');

    Event::assertDispatched(UserUpdated::class);
});

it('acepta PATCH con el mismo cuerpo', function (): void {
    actingAsUserWith(Role::ADMIN);
    $target = User::factory()->create();

    $this->patchJson(route('api.v1.users.update', $target), [
        'name' => 'Por PATCH',
        'email' => $target->email,
        'role' => SystemRole::User->value,
    ])->assertOk();

    expect($target->refresh()->name)->toBe('Por PATCH');
});

it('omitir password significa no la cambies', function (): void {
    actingAsUserWith(Role::ADMIN);
    $target = User::factory()->create();
    $hash = $target->password;

    $this->putJson(route('api.v1.users.update', $target), [
        'name' => $target->name,
        'email' => $target->email,
        'role' => SystemRole::User->value,
    ])->assertOk();

    expect($target->refresh()->password)->toBe($hash);
});

it('rechaza editar sin la ability del token', function (): void {
    actingAsUserWith(Role::ADMIN, abilities: ['users.view']);
    $target = User::factory()->create(['name' => 'Intacto']);

    $this->putJson(route('api.v1.users.update', $target), [
        'name' => 'Cambiado',
        'email' => $target->email,
        'role' => SystemRole::User->value,
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect($target->refresh()->name)->toBe('Intacto');
});

it('no deja editar a un superadmin quien no lo es', function (): void {
    actingAsUserWith(Role::ADMIN);

    $superadmin = User::factory()->create(['name' => 'Intocable']);
    $superadmin->assignRole(Role::SUPERADMIN);

    $this->putJson(route('api.v1.users.update', $superadmin), [
        'name' => 'Secuestrado',
        'email' => $superadmin->email,
        'role' => SystemRole::User->value,
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect($superadmin->refresh()->name)->toBe('Intocable');
});

/*
|--------------------------------------------------------------------------
| destroy
|--------------------------------------------------------------------------
*/

it('borra un usuario y responde 204 sin cuerpo', function (): void {
    Event::fake([UserDeleted::class]);

    actingAsUserWith(Role::ADMIN);
    $target = User::factory()->create();

    $response = $this->deleteJson(route('api.v1.users.destroy', $target));

    $response->assertNoContent();

    expect($response->getContent())->toBe('')
        ->and(User::query()->whereKey($target->getKey())->exists())->toBeFalse();

    Event::assertDispatched(UserDeleted::class);
});

it('rechaza borrar sin la ability del token', function (): void {
    actingAsUserWith(Role::ADMIN, abilities: ['users.view']);
    $target = User::factory()->create();

    $this->deleteJson(route('api.v1.users.destroy', $target))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect(User::query()->whereKey($target->getKey())->exists())->toBeTrue();
});

it('no deja borrar a un superadmin', function (): void {
    actingAsUserWith(Role::ADMIN);

    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    $this->deleteJson(route('api.v1.users.destroy', $superadmin))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect(User::query()->whereKey($superadmin->getKey())->exists())->toBeTrue();
});

it('no deja que nadie se borre a sí mismo, ni siquiera un superadmin', function (): void {
    // El `Gate::before` del superadmin devuelve true antes de consultar la
    // policy, así que la guarda tiene que estar en el controller (misma
    // cicatriz que `TableUsers`).
    $actor = actingAsUserWith(Role::SUPERADMIN);

    $this->deleteJson(route('api.v1.users.destroy', $actor))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    expect(User::query()->whereKey($actor->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Toggle
|--------------------------------------------------------------------------
*/

it('no registra ninguna ruta con API_ENABLED apagado', function (): void {
    withEnvironment(['API_ENABLED' => 'false'], function (): void {
        expect(Route::has('api.v1.users.index'))->toBeFalse()
            ->and(Route::has('api.v1.users.store'))->toBeFalse();

        $this->getJson('/api/v1/users')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    });
});
