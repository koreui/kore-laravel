<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Controllers;

use App\Core\Http\Api\Concerns\HandlesCursorPagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Routing\Controller;

/**
 * Base de todo controller de la API (R54).
 *
 * Garantiza el envelope de éxito —`{ data, meta? }`— desde un único sitio, para
 * que ningún endpoint tenga que acordarse de él. Un controller de la API no
 * construye `JsonResponse` a mano: llama a `respond()` o a `respondNoContent()`.
 *
 * Trae también `AuthorizesRequests`, así que `$this->authorize(...)` funciona
 * igual que en un controller web: lanza `AuthorizationException` y
 * `ApiExceptionRenderer` la convierte en el 403 del contrato. La decisión sigue
 * viviendo en la Policy (R25), y nunca en un `FormRequest` (ver
 * `BaseApiRequest`).
 *
 * Lo que **no** hace: abortar con `abort()`. R20 deja `abort*()` para la capa
 * Http de la aplicación y de los módulos, no para `App\Core`; aquí se lanza la
 * excepción que toca (`AuthorizationException`,
 * `App\Exceptions\ConflictException`, `ModelNotFoundException`) y el
 * renderer decide el status.
 *
 * Trae también `HandlesCursorPagination`, que es quien produce el `meta` que
 * `respond()` publica: las dos mitades del envelope de un listado viven juntas,
 * y así ningún controller tiene que acordarse de importarlo. Sus tres métodos
 * son `protected`, así que no ensanchan la superficie pública de nadie.
 *
 * ```php
 * final class DeviceController extends ApiController
 * {
 *     public function index(Request $request): JsonResponse
 *     {
 *         $this->authorize('viewAny', Device::class);
 *
 *         $devices = $this->paginateWithCursor(Device::query()->latest(), $request);
 *
 *         return $this->respond(DeviceResource::collection($devices), meta: $this->cursorMeta($devices));
 *     }
 * }
 * ```
 *
 * Ver `docs/guides/api.md`.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;
    use HandlesCursorPagination;

    /**
     * Respuesta de éxito con el envelope `{ data, meta? }`.
     *
     * Acepta un `JsonResource` (lo normal: el `$wrap = 'data'` de
     * `BaseApiResource` pone el sobre) o un array plano, que se envuelve aquí.
     * `meta` sólo aparece si se pasa: un recurso único no arrastra un `meta`
     * vacío.
     *
     * @param JsonResource|array<array-key, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function respond(JsonResource|array $data, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof JsonResource) {
            /*
             * Un resource sobre un paginador se rinde por `PaginatedResourceResponse`,
             * que añade su propio `meta` (`path`, `per_page`, cursores) y lo funde
             * con el nuestro usando `array_merge_recursive`. El resultado no es un
             * `meta` con más claves: es `meta.per_page = [25, 25]`, un array donde
             * el cliente espera un número. El envelope del contrato tiene un solo
             * `meta` y es el que pasa el endpoint, así que aquí se arma a mano.
             */
            if ($meta !== [] && $this->isPaginated($data)) {
                return new JsonResponse(['data' => $data->resolve(), 'meta' => $meta], $status);
            }

            $resource = $meta === [] ? $data : $data->additional(['meta' => $meta]);

            return $resource->response()->setStatusCode($status);
        }

        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * ¿Este resource envuelve un paginador?
     *
     * Es la misma condición que usa `ResourceCollection::toResponse()` para
     * decidir si rinde por `PaginatedResourceResponse`, y por eso se comprueba
     * igual: contra las dos bases abstractas de paginación —la clásica
     * (`paginate`, `simplePaginate`) y la de cursor—, no contra sus
     * implementaciones.
     */
    private function isPaginated(JsonResource $data): bool
    {
        return $data->resource instanceof AbstractPaginator
            || $data->resource instanceof AbstractCursorPaginator;
    }

    /**
     * 204 sin cuerpo, para los `DELETE` y los comandos que no devuelven nada.
     *
     * No es un `JsonResponse` vacío a propósito: un 204 con cuerpo `null`
     * obliga al cliente a distinguir «sin contenido» de «contenido nulo».
     */
    protected function respondNoContent(): Response
    {
        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
