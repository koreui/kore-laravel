<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * El usuario autenticado tal y como lo ve él mismo: identidad + lo que puede
 * hacer (`GET /api/v1/user`).
 *
 * Es una lista blanca, no un `$user->toArray()`. Hasta la v2.1.0 ese endpoint
 * devolvía el modelo Eloquent a pelo, con todos los atributos que tuviera la
 * tabla el día del `git pull` —incluidos los que un `#[Hidden]` mal puesto
 * dejara pasar—. Un resource obliga a decidir campo a campo qué es público.
 *
 * `roles` y `permissions` van como listas de strings (no como objetos) porque
 * es lo que un cliente evalúa: `permissions.includes('users.update')`.
 *
 * @mixin User
 */
final class UserMeResource extends BaseApiResource
{
    /**
     * @return array{id: int|string|null, name: string, email: string, roles: array<int, string>, permissions: array<int, string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            // `array_map(strval(...))` no es decorativo: `getRoleNames()` y el
            // `pluck()` de los permisos devuelven `mixed` para el análisis
            // estático, y sin fijar el tipo aquí ni Larastan ni Scramble saben
            // que estas dos listas son de strings.
            'roles' => array_map(strval(...), $this->getRoleNames()->values()->all()),
            'permissions' => array_map(strval(...), $this->getAllPermissions()->pluck('name')->values()->all()),
        ];
    }
}
