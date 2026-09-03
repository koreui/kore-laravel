<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Users\Data\UserData;
use App\Modules\Users\Events\UserUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Actualiza un usuario: datos, rol y permisos directos.
 *
 * `UserData::$password` a `null` (o cadena vacía) significa «no la cambies»:
 * es lo que hace el formulario cuando el campo se deja en blanco.
 *
 * Sin `auth()` / `request()` / `session()`: la autorización la hace el
 * llamante.
 */
final class UserUpdateAction extends Action
{
    public function handle(User $user, UserData $data): User
    {
        DB::transaction(function () use ($user, $data): void {
            $attributes = [
                'name' => $data->name,
                'email' => $data->email,
            ];

            if ($data->password !== null && $data->password !== '') {
                $attributes['password'] = Hash::make($data->password);
            }

            $user->fill($attributes)->save();

            $user->syncRoles([$data->role]);
            $user->syncPermissions($data->permissions);
        });

        event(new UserUpdated($user));

        return $user;
    }
}
