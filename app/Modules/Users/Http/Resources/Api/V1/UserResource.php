<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources\Api\V1;

use App\Core\Http\Api\Resources\BaseApiResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Un usuario visto por quien administra usuarios (`api/v1/users`).
 *
 * Es una **lista blanca**, no un `toArray()` del modelo: lo que no esté escrito
 * aquí no sale, y una columna nueva en la tabla no se publica sola. Es la
 * diferencia entre este resource y el `$user->toArray()` que asper-server manda
 * en su login, donde cada migración amplía la API sin que nadie lo decida.
 *
 * `roles` y `permissions` van como listas de strings porque es lo que un
 * cliente evalúa (`permissions.includes('users.edit')`), igual que en
 * `UserMeResource`. `permissions` son los **efectivos** —los de sus roles más
 * los directos—, que es lo que responde a «¿qué puede hacer esta persona?»; el
 * formulario que edita sólo los directos es otra pregunta y otra pantalla.
 *
 * No lleva `email_verified_at` ni nada del 2FA: son estado interno de la cuenta,
 * y una API que los publica acaba siendo el sitio del que alguien saca la lista
 * de quién no ha protegido la suya.
 *
 * @mixin User
 */
final class UserResource extends BaseApiResource
{
    /**
     * @return array{id: int|string|null, name: string, email: string, roles: array<int, string>, permissions: array<int, string>, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            // `array_map(strval(...))`: `getRoleNames()` y el `pluck()` de los
            // permisos devuelven `mixed` para el análisis estático, y sin fijar
            // el tipo ni Larastan ni Scramble saben que son listas de strings.
            'roles' => array_map(strval(...), $this->getRoleNames()->values()->all()),
            'permissions' => array_map(strval(...), $this->getAllPermissions()->pluck('name')->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
