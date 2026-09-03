<?php

declare(strict_types=1);

use App\Exceptions\ConflictException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| R54 · el envelope de error de la API
|--------------------------------------------------------------------------
|
| Cada código canónico de `App\Core\Enums\ApiErrorCode` con la excepción que lo
| produce, sobre rutas definidas aquí mismo: el contrato tiene que valer para
| cualquier endpoint futuro, no sólo para los dos que hoy existen.
|
| Las rutas se declaran en el `beforeEach` y viven en el grupo `api` real
| —con su `throttle`, su `api.json` y su `api.audit`—, así que lo que se
| comprueba es la pila entera y no el renderer aislado.
|
*/

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/probe')->group(function (): void {
        Route::get('/ok', fn (): array => ['ok' => true]);

        Route::post('/validate', function (Request $request): array {
            $request->validate([
                'email' => ['required', 'email'],
                'name' => ['required'],
            ]);

            return ['ok' => true];
        });

        Route::get('/unauthenticated', function (): never {
            throw new AuthenticationException;
        });

        Route::get('/forbidden', function (): never {
            throw new AuthorizationException;
        });

        Route::get('/model-missing', function (): never {
            throw (new ModelNotFoundException)->setModel(User::class, [42]);
        });

        Route::get('/conflict', function (): never {
            throw new ConflictException(__('Este dispositivo ya está registrado en otra cuenta.'));
        });

        Route::get('/boom', function (): never {
            throw new RuntimeException('La contraseña de la base de datos es hunter2');
        });

        Route::get('/throttled', fn (): array => ['ok' => true])->middleware('throttle:1,1');
    });

    Route::get('/probe-web', fn (): string => '<h1>Una pantalla</h1>');
});

it('devuelve 422 con details por campo cuando la validación falla', function (): void {
    $response = $this->postJson('/api/probe/validate', ['email' => 'no-es-un-email']);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', 'Los datos enviados no son válidos.')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email', 'name']]]);
});

it('devuelve 401 unauthenticated', function (): void {
    $this->getJson('/api/probe/unauthenticated')
        ->assertStatus(401)
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'No has iniciado sesión.']]);
});

it('devuelve 401 unauthenticated en una ruta protegida sin token', function (): void {
    $this->getJson('/api/v1/user')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('devuelve 403 forbidden', function (): void {
    $this->getJson('/api/probe/forbidden')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonPath('error.message', 'No tienes permiso para hacer esto.');
});

it('devuelve 404 not_found sin filtrar el modelo buscado', function (): void {
    $response = $this->getJson('/api/probe/model-missing');

    $response->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'No se encontró el recurso solicitado.');

    // El mensaje de ModelNotFoundException lleva dentro el FQCN y el id.
    expect($response->json('error.message'))->not->toContain('User');
});

it('devuelve 404 not_found en una ruta de api que no existe', function (): void {
    $this->getJson('/api/probe/no-existe')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('devuelve 405 method_not_allowed con la cabecera Allow', function (): void {
    $this->postJson('/api/probe/ok')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed')
        ->assertHeader('Allow');
});

it('devuelve 429 throttled con Retry-After', function (): void {
    $this->getJson('/api/probe/throttled')->assertOk();

    $this->getJson('/api/probe/throttled')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'throttled')
        ->assertHeader('Retry-After');
});

it('devuelve 409 conflict con el mensaje de dominio', function (): void {
    $this->getJson('/api/probe/conflict')
        ->assertStatus(409)
        ->assertExactJson(['error' => [
            'code' => 'conflict',
            'message' => 'Este dispositivo ya está registrado en otra cuenta.',
        ]]);
});

it('devuelve 500 server_error sin filtrar nada con app.debug apagado', function (): void {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/probe/boom');

    $response->assertStatus(500)
        ->assertExactJson(['error' => [
            'code' => 'server_error',
            'message' => 'Ocurrió un error inesperado.',
        ]]);

    expect($response->getContent())
        ->not->toContain('hunter2')
        ->not->toContain('RuntimeException');
});

it('añade el bloque debug al 500 sólo con app.debug encendido', function (): void {
    config(['app.debug' => true]);

    $this->getJson('/api/probe/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonPath('error.debug.exception', RuntimeException::class)
        ->assertJsonStructure(['error' => ['debug' => ['exception', 'message', 'file', 'line']]]);
});

it('sólo pone details en el 422', function (): void {
    $sinDetails = [
        ['GET', '/api/probe/unauthenticated'],
        ['GET', '/api/probe/forbidden'],
        ['GET', '/api/probe/model-missing'],
        ['GET', '/api/probe/conflict'],
        ['GET', '/api/probe/boom'],
        ['POST', '/api/probe/ok'],
    ];

    foreach ($sinDetails as [$method, $uri]) {
        $response = $this->json($method, $uri);

        expect($response->json('error'))
            ->not->toHaveKey('details', "{$method} {$uri} devolvió details fuera de un 422");
    }
});

it('responde JSON en api/* aunque el cliente no mande Accept', function (): void {
    // `get()` (no `getJson()`): sin cabecera Accept, que es como llega un curl
    // o un FormData desde un cliente móvil.
    $response = $this->get('/api/probe/forbidden');

    $response->assertStatus(403)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'forbidden');
});

it('deja intacta una ruta web: sigue devolviendo HTML', function (): void {
    $response = $this->get('/probe-web');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('<h1>Una pantalla</h1>');
});

it('deja que una pantalla web renderice su error en HTML', function (): void {
    config(['app.debug' => false]);

    Route::get('/probe-web-forbidden', function (): never {
        throw new AuthorizationException;
    });

    $response = $this->get('/probe-web-forbidden');

    $response->assertStatus(403);

    // Sin `json()` a propósito: `TestResponse::json()` sobre un cuerpo que no es
    // JSON relanza la excepción original y el fallo sería ilegible.
    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->not->toContain('"error"');
});
