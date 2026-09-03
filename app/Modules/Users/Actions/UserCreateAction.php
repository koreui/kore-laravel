<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Core\Actions\Action;
use App\Models\User;
use App\Modules\Users\Data\UserData;
use App\Modules\Users\Events\UserCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Alta de un usuario con su rol y sus permisos directos.
 *
 * No consulta `auth()`, `request()` ni `session()`: la autorización es
 * responsabilidad del llamante (el componente Livewire), así que esta Action
 * sirve igual desde un comando artisan, un seeder o un job.
 *
 * El usuario nace verificado porque lo da de alta alguien con `users.create`,
 * no un registro público (ese camino es Fortify + `AuthUserRegisterAction`).
 */
final class UserCreateAction extends Action
{
    public function handle(UserData $data): User
    {
        // Transacción: el usuario sin su rol/permisos es un registro a medias.
        // Si falla cualquiera de los tres sync, no queremos la fila huérfana.
        $user = DB::transaction(function () use ($data): User {
            $user = new User;

            $user->fill([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make((string) $data->password),
                'email_verified_at' => now(),
            ])->save();

            $user->syncRoles([$data->role]);
            $user->syncPermissions($data->permissions);

            return $user;
        });

        // Fuera de la transacción: un listener no debe ver datos sin commitear.
        event(new UserCreated($user));

        return $user;
    }
}
