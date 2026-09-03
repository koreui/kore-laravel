<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers\Api\V1;

use App\Core\Enums\SystemRole;
use App\Core\Http\Api\Controllers\ApiController;
use App\Models\User;
use App\Modules\Users\Actions\UserCreateAction;
use App\Modules\Users\Actions\UserDeleteAction;
use App\Modules\Users\Actions\UserUpdateAction;
use App\Modules\Users\Http\Requests\Api\V1\UserStoreRequest;
use App\Modules\Users\Http\Requests\Api\V1\UserUpdateRequest;
use App\Modules\Users\Http\Resources\Api\V1\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CRUD de usuarios por API (`api/v1/users`).
 *
 * Es el endpoint de referencia del boilerplate: el que hay que copiar cuando un
 * módulo nuevo necesita publicar su recurso. Enseña las cinco piezas juntas —
 * ruta con abilities, controller que autoriza contra la Policy, request que
 * produce el DTO, Action que escribe y resource que publica— y **no reimplementa
 * ninguna**: las Actions, los DTOs y las reglas anti-escalada son literalmente
 * las mismas que usa la pantalla Livewire.
 *
 * **Doble barrera, igual que en Livewire (R23 · R25).** La ruta exige la
 * ability del token (`abilities:users.edit`) y el método vuelve a preguntarle a
 * la Policy (`$this->authorize('update', $user)`). No es redundante: la ability
 * dice qué se le concedió a *este token* cuando se emitió, y la Policy qué puede
 * *este usuario* ahora mismo sobre *este registro* —«sólo un superadmin edita a
 * otro superadmin» no es algo que una ability pueda expresar—. Quitar
 * cualquiera de las dos deja un agujero distinto.
 *
 * @see docs/guides/api.md
 * @see docs/modules/users.md
 */
#[Group('Users')]
final class UserController extends ApiController
{
    /**
     * Listado de usuarios.
     *
     * Paginación por cursor (`?per_page=`, `?cursor=`) con `meta.next_cursor`.
     * Filtros opcionales: `search` (nombre o email) y `role` (nombre exacto del
     * rol).
     */
    #[ApiResponse(200, type: UserResource::class)]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->paginateWithCursor($this->query($request), $request);

        return $this->respond(UserResource::collection($users), meta: $this->cursorMeta($users));
    }

    /**
     * Un usuario.
     */
    #[ApiResponse(200, type: UserResource::class)]
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return $this->respond(UserResource::make($user->load('roles', 'permissions')));
    }

    /**
     * Alta de un usuario con su rol y sus permisos directos.
     *
     * El rol y los permisos pasan por `GrantableRole` y `GrantablePermission`:
     * nadie concede lo que no tiene (R26), y el intento sale como un 422
     * `validation_failed` con el motivo en `details`.
     */
    #[ApiResponse(201, type: UserResource::class)]
    public function store(UserStoreRequest $request, UserCreateAction $create): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $create->handle($request->toData());

        return $this->respond(UserResource::make($user->load('roles', 'permissions')), status: 201);
    }

    /**
     * Edita un usuario.
     *
     * Omitir `password` significa «no la cambies». Sólo un superadmin puede
     * editar a otro superadmin: lo decide `UserPolicy::update()`.
     */
    #[ApiResponse(200, type: UserResource::class)]
    public function update(UserUpdateRequest $request, User $user, UserUpdateAction $update): JsonResponse
    {
        $this->authorize('update', $user);

        $updated = $update->handle($user, $request->toData());

        return $this->respond(UserResource::make($updated->load('roles', 'permissions')));
    }

    /**
     * Borra un usuario. Responde 204 sin cuerpo.
     */
    #[ApiResponse(204)]
    public function destroy(Request $request, User $user, UserDeleteAction $delete): Response
    {
        // Guarda explícita de auto-borrado, la misma que `TableUsers`: el
        // `Gate::before` del superadmin devuelve true antes de consultar la
        // policy, así que sin esto un superadmin podría borrarse a sí mismo
        // desde la API y dejar la instalación sin nadie que la administre.
        abort_if($user->is($request->user()), 403);

        $this->authorize('delete', $user);

        $delete->handle($user);

        return $this->respondNoContent();
    }

    /**
     * El listado que ve la API, con sus filtros.
     *
     * **Los superadmins no salen**, exactamente igual que en `TableUsers`: es un
     * rol que sólo se asigna por consola y publicarlo sería regalarle a
     * cualquiera con `users.view` la lista de las cuentas que más interesa
     * atacar. Que la pantalla los oculte y la API los enseñara sería tener dos
     * respuestas distintas a la misma pregunta.
     *
     * El orden es por id descendente y no por `created_at`: la paginación por
     * cursor necesita una columna única para no saltarse ni repetir filas, y dos
     * usuarios sembrados en la misma transacción comparten `created_at`.
     *
     * @return Builder<User>
     */
    private function query(Request $request): Builder
    {
        /** @var Builder<User> $query */
        $query = User::query()
            ->with(['roles', 'permissions'])
            ->whereDoesntHave('roles', fn (Builder $role): Builder => $role->where('name', '=', SystemRole::Superadmin->value))
            ->when(
                $request->filled('search'),
                function (Builder $users) use ($request): Builder {
                    $term = '%'.$request->string('search')->trim()->value().'%';

                    return $users->where(fn (Builder $match): Builder => $match
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));
                },
            )
            ->when(
                $request->filled('role'),
                fn (Builder $users): Builder => $users->whereHas(
                    'roles',
                    fn (Builder $role): Builder => $role->where('name', '=', $request->string('role')->value()),
                ),
            )
            ->orderByDesc('id');

        return $query;
    }
}
