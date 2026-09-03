<?php

declare(strict_types=1);

use App\Core\Http\Api\Controllers\ApiController;
use App\Core\Http\Api\Resources\BaseApiResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paginación por cursor del contrato de la API
|--------------------------------------------------------------------------
|
| El trait lo trae `ApiController` y sus métodos son `protected`, así que se
| ejercita desde donde se usa de verdad: un controller de la API montado en una
| ruta del grupo `api`. Así el test cubre también el envelope `{ data, meta }`
| que produce `respond()`.
|
*/

/** @mixin User */
final class CursorProbeResource extends BaseApiResource
{
    /**
     * @return array{id: int|string|null, name: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->getKey(), 'name' => $this->name];
    }
}

final class CursorProbeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $users = $this->paginateWithCursor(User::query()->orderBy('id'), $request);

        return $this->respond(
            data: $users->items(),
            meta: $this->cursorMeta($users),
        );
    }

    /**
     * La forma que documenta `docs/guides/api.md`: el resource envuelve el
     * paginador entero, no sus `items()`.
     */
    public function resourceIndex(Request $request): JsonResponse
    {
        $users = $this->paginateWithCursor(User::query()->orderBy('id'), $request);

        return $this->respond(CursorProbeResource::collection($users), meta: $this->cursorMeta($users));
    }

    public function perPage(Request $request): JsonResponse
    {
        return $this->respond(['per_page' => $this->resolvePerPage($request)]);
    }
}

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/probe')->group(function (): void {
        Route::get('/users', [CursorProbeController::class, 'index']);
        Route::get('/users-resource', [CursorProbeController::class, 'resourceIndex']);
        Route::get('/per-page', [CursorProbeController::class, 'perPage']);
    });
});

it('usa el per_page por defecto de kore-api cuando el cliente no pide nada', function (): void {
    $this->getJson('/api/probe/per-page')
        ->assertOk()
        ->assertJsonPath('data.per_page', config('kore-api.pagination.default'));
});

it('respeta el per_page que pide el cliente', function (): void {
    $this->getJson('/api/probe/per-page?per_page=7')
        ->assertOk()
        ->assertJsonPath('data.per_page', 7);
});

it('recorta el per_page al tope max de kore-api', function (): void {
    $max = (int) config('kore-api.pagination.max');

    $this->getJson('/api/probe/per-page?per_page='.($max * 1000))
        ->assertOk()
        ->assertJsonPath('data.per_page', $max);
});

it('nunca baja de 1, aunque pidan cero o un negativo', function (): void {
    $this->getJson('/api/probe/per-page?per_page=0')->assertJsonPath('data.per_page', 1);
    $this->getJson('/api/probe/per-page?per_page=-5')->assertJsonPath('data.per_page', 1);
});

it('devuelve el meta completo del cursor', function (): void {
    User::factory()->count(5)->create();

    $response = $this->getJson('/api/probe/users?per_page=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.prev_cursor', null)
        ->assertJsonStructure(['data', 'meta' => ['next_cursor', 'prev_cursor', 'per_page']]);

    expect($response->json('meta.next_cursor'))->toBeString();
});

it('recorre las páginas con el next_cursor y cierra con next_cursor nulo', function (): void {
    $users = User::factory()->count(5)->create();

    $vistos = [];
    $cursor = null;

    do {
        $response = $this->getJson('/api/probe/users?per_page=2'.($cursor === null ? '' : '&cursor='.$cursor));

        $response->assertOk();

        foreach ($response->json('data') as $fila) {
            $vistos[] = $fila['id'];
        }

        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($vistos)->toBe($users->pluck('id')->all());
});

it('no arrastra un meta vacío cuando no se pasa ninguno', function (): void {
    Route::middleware('api')->get('/api/probe/sin-meta', fn (): JsonResponse => new class extends ApiController
    {
        public function __invoke(): JsonResponse
        {
            return $this->respond(['id' => 1]);
        }
    }());

    $this->getJson('/api/probe/sin-meta')
        ->assertOk()
        ->assertExactJson(['data' => ['id' => 1]]);
});

it('publica un solo meta cuando el resource envuelve el paginador', function (): void {
    User::factory()->count(5)->create();

    $response = $this->getJson('/api/probe/users-resource?per_page=2');

    // Laravel rinde un resource sobre un paginador con `PaginatedResourceResponse`,
    // que añade SU meta (`path`, `per_page`, cursores) y lo funde con el nuestro
    // con `array_merge_recursive`: sin el arreglo de `respond()`, `meta.per_page`
    // llegaba al cliente como `[2, 2]`.
    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertExactJsonStructure([
            'data' => ['*' => ['id', 'name']],
            'meta' => ['next_cursor', 'prev_cursor', 'per_page'],
        ]);

    expect($response->json('meta.per_page'))->toBe(2)
        ->and($response->json('meta.next_cursor'))->toBeString()
        ->and($response->json('meta.prev_cursor'))->toBeNull();
});
