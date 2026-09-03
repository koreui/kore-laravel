<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('devuelve el usuario autenticado en api.v1.user.me bajo el envelope del contrato', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.user.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $user->getKey())
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertExactJsonStructure(['data' => ['id', 'name', 'email', 'roles', 'permissions']]);
});

it('publica los roles y permisos del usuario', function (): void {
    $this->seed(ModulesSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.v1.user.me'))->assertOk();

    expect($response->json('data.roles'))->toContain(Role::ADMIN)
        ->and($response->json('data.permissions'))->not->toBeEmpty();
});

it('no filtra ningún atributo del modelo fuera de la lista blanca', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    // Hasta la v2.1.0 el endpoint devolvía `$request->user()` a pelo, con todos
    // los atributos que tuviera la tabla ese día.
    expect(array_keys((array) $this->getJson(route('api.v1.user.me'))->json('data')))
        ->toBe(['id', 'name', 'email', 'roles', 'permissions']);
});

it('rechaza al invitado con el código canónico del contrato', function (): void {
    $this->getJson(route('api.v1.user.me'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('ya no responde en la ruta sin versionar', function (): void {
    // `GET /api/user` desapareció en la v2.2.0: la API vive bajo `api/v1`.
    $this->getJson('/api/user')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});
