<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Documentación OpenAPI (Scramble) detrás de API_DOCS + gate
|--------------------------------------------------------------------------
|
| Las rutas de Scramble las registra su propio provider durante el arranque, así
| que probar el toggle exige rearrancar la aplicación: `withEnvironment()`
| (docs/patterns/test-con-otro-entorno.md). `phpunit.xml` fuerza
| `API_DOCS=false`, que es el estado por defecto.
|
*/

beforeEach(function (): void {
    $this->seed(ModulesSeeder::class);
});

/** Arranca la aplicación con la documentación encendida. */
function withApiDocs(Closure $callback): void
{
    withEnvironment(['API_DOCS' => 'true'], $callback);
}

it('no registra ninguna ruta de Scramble con API_DOCS apagado', function (): void {
    expect(config('kore-api.docs.enabled'))->toBeFalse()
        ->and(Route::has('scramble.docs.ui'))->toBeFalse()
        ->and(Route::has('scramble.docs.document'))->toBeFalse();

    $this->get('/api/docs')->assertNotFound();
    $this->get('/api/docs.json')->assertNotFound();
});

it('registra /api/docs y /api/docs.json con API_DOCS encendido', function (): void {
    withApiDocs(function (): void {
        expect(config('kore-api.docs.enabled'))->toBeTrue()
            ->and(Route::has('scramble.docs.ui'))->toBeTrue()
            ->and(Route::has('scramble.docs.document'))->toBeTrue()
            ->and(route('scramble.docs.ui', absolute: false))->toBe('/api/docs')
            ->and(route('scramble.docs.document', absolute: false))->toBe('/api/docs.json');
    });
});

it('no deja que el visor de /docs se quede la URL de la documentación de la API', function (): void {
    // El módulo Docs registra `GET /docs/{path}` con un `where` que casa
    // `docs/api`; por eso la doc de la API vive en `/api/docs`. Con los dos
    // toggles encendidos las cuatro rutas conviven sin solaparse.
    withEnvironment(['API_DOCS' => 'true', 'DOCS_ENABLED' => 'true'], function (): void {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        expect($uris)->toContain('api/docs', 'api/docs.json', 'docs', 'docs/{path}');

        expect(Route::getRoutes()->match(
            Request::create('/api/docs', 'GET')
        )->getName())->toBe('scramble.docs.ui');
    });
});

it('rechaza a un invitado', function (): void {
    withApiDocs(function (): void {
        $this->get('/api/docs')->assertForbidden();
        $this->get('/api/docs.json')->assertForbidden();
    });
});

it('rechaza a un usuario que no es superadmin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);

    withApiDocs(function () use ($admin): void {
        $this->actingAs($admin)->get('/api/docs')->assertForbidden();
        $this->actingAs($admin)->get('/api/docs.json')->assertForbidden();
    });
});

it('deja pasar al superadmin', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    withApiDocs(function () use ($superadmin): void {
        $this->actingAs($superadmin)->get('/api/docs')->assertOk();
    });
});

it('sirve un OpenAPI 3 con el endpoint del contrato', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    withApiDocs(function () use ($superadmin): void {
        $response = $this->actingAs($superadmin)->get('/api/docs.json');

        $response->assertOk();

        $document = $response->json();

        expect($document['openapi'] ?? null)->toStartWith('3.')
            ->and($document['info']['version'] ?? null)->toBe(config('kore-api.version'))
            ->and($document['info']['title'] ?? null)->toBe(config('app.name').' API')
            ->and($document['paths'] ?? [])->toHaveKey('/api/v1/user');

        // El envelope del contrato tiene que verse en la spec, no sólo en la
        // respuesta real: es lo que lee quien genera un cliente.
        expect($document['paths']['/api/v1/user']['get']['responses'])->toHaveKey('200')
            ->and($document['components']['schemas'] ?? [])->toHaveKey('UserMeResource');
    });
});

it('no documenta sus propias rutas', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::SUPERADMIN);

    withApiDocs(function () use ($superadmin): void {
        $paths = array_keys($this->actingAs($superadmin)->get('/api/docs.json')->json('paths'));

        expect($paths)->not->toContain('/api/docs')
            ->and($paths)->not->toContain('/api/docs.json');
    });
});

it('exporta la spec a un archivo con scramble:export', function (): void {
    withApiDocs(function (): void {
        $path = storage_path('framework/testing/openapi-test.json');

        @unlink($path);

        expect(Artisan::call('scramble:export', ['--path' => $path]))->toBe(0)
            ->and(is_file($path))->toBeTrue();

        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        expect($document)->toHaveKey('paths')
            ->and($document['paths'])->toHaveKey('/api/v1/user');

        @unlink($path);
    });
});

it('exporta por defecto a storage/app, que está fuera de git', function (): void {
    expect(config('scramble.export_path'))->toBe(storage_path('app/openapi.json'));
});
