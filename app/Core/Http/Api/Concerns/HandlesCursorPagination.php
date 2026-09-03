<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;

/**
 * Paginación por cursor para los listados de la API.
 *
 * Cursor y no `page=N` porque una API la consume un cliente que scrollea: con
 * offset, insertar una fila mientras el usuario baja duplica o se salta
 * registros, y el `COUNT(*)` de `paginate()` se paga en cada petición para un
 * total que nadie enseña.
 *
 * El tope de `per_page` sale de `config('kore-api.pagination')` y no es
 * negociable: sin él, `?per_page=100000` es una denegación de servicio de un
 * solo carácter.
 *
 * El `meta` que produce `cursorMeta()` es parte del contrato:
 * `next_cursor` (null en la última página), `prev_cursor` y `per_page`.
 *
 * ```php
 * $devices = $this->paginateWithCursor(Device::query()->latest(), $request);
 *
 * return $this->respond(DeviceResource::collection($devices), meta: $this->cursorMeta($devices));
 * ```
 *
 * Ver `docs/guides/api.md`.
 */
trait HandlesCursorPagination
{
    /**
     * El contrato `Builder` (y no `Eloquent\Builder`) porque no es genérico y
     * sirve igual para un query builder: el trait no necesita saber qué modelo
     * hay detrás, sólo cuántas filas devolver.
     *
     * @return CursorPaginator<int, mixed>
     */
    protected function paginateWithCursor(Builder $query, Request $request, ?int $default = null): CursorPaginator
    {
        /** @var CursorPaginator<int, mixed> $paginator */
        $paginator = $query->cursorPaginate($this->resolvePerPage($request, $default));

        return $paginator;
    }

    /**
     * Tamaño de página efectivo: lo que pide el cliente, acotado entre 1 y
     * `kore-api.pagination.max`. Sin `per_page`, el default del config (o el
     * que pase el endpoint, cuando sus filas son especialmente caras).
     */
    protected function resolvePerPage(Request $request, ?int $default = null): int
    {
        $default ??= (int) config('kore-api.pagination.default', 25);
        $max = (int) config('kore-api.pagination.max', 100);

        $requested = $request->has('per_page') ? $request->integer('per_page') : $default;

        return max(1, min($requested, $max));
    }

    /**
     * @param CursorPaginator<int, mixed> $paginator
     * @return array{next_cursor: string|null, prev_cursor: string|null, per_page: int}
     */
    protected function cursorMeta(CursorPaginator $paginator): array
    {
        return [
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $paginator->perPage(),
        ];
    }
}
