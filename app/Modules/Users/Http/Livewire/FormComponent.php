<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Livewire;

use App\Core\Concerns\HandlesSlotUploads;
use App\Core\Concerns\RedirectsWithToast;
use App\Core\Contracts\AuthorizationCatalog;
use App\Core\Contracts\FileStore;
use App\Core\Data\Authorization\PermissionModuleData;
use App\Core\Data\Authorization\RoleOptionData;
use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use App\Core\Enums\SystemRole;
use App\Models\User;
use App\Modules\Users\Actions\UserCreateAction;
use App\Modules\Users\Actions\UserUpdateAction;
use App\Modules\Users\Forms\UserForm;
use KoreUi\Core\Concerns\InteractsWithFeedback;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\MediaLibrary\HasMedia;

#[Layout('layouts.app')]
final class FormComponent extends Component
{
    /**
     * El avatar del usuario, como ejemplo de referencia de `FileStore`.
     *
     * El trait aporta `$slotUpload`, `uploadSlot()` y `deleteUpload()`; lo que
     * este componente pone son las tres respuestas de abajo: de quién es el
     * archivo, qué slot ocupa y quién puede tocarlo. Ver
     * `App\Core\Concerns\HandlesSlotUploads` y `docs/modules/files.md`.
     */
    use HandlesSlotUploads;

    use InteractsWithFeedback;
    use RedirectsWithToast;

    #[Locked]
    public ?User $model = null;

    public UserForm $form;

    public function mount(): void
    {
        if (! $this->model instanceof User) {
            $this->authorize('create', User::class);

            return;
        }

        $this->authorize('update', $this->model);

        $firstRole = $this->model->roles->first();
        $roleName = $firstRole !== null ? (string) $firstRole->getAttribute('name') : SystemRole::User->value;

        $this->form->fill([
            'id' => $this->model->id,
            'name' => $this->model->name,
            'email' => $this->model->email,
            'role' => $roleName,
            'permissions' => $this->model->getDirectPermissions()->pluck('name')->all(),
        ]);
    }

    /**
     * Autoriza → valida → DTO → Action. Nada más: la escritura vive en la
     * Action y este método sólo orquesta.
     *
     * Las Actions llegan por inyección de método (Livewire las resuelve del
     * contenedor), que es lo que permite fakearlas en un test sin tocar el
     * componente.
     *
     * Las rutas de `users` llevan middleware `permission:*`, pero las llamadas
     * Livewire viajan por /livewire/update, donde ese middleware NO corre. Por
     * eso la autorización tiene que vivir dentro del componente.
     */
    public function save(UserCreateAction $createUser, UserUpdateAction $updateUser): mixed
    {
        if ($this->model instanceof User) {
            $this->authorize('update', $this->model);
        } else {
            $this->authorize('create', User::class);
        }

        $this->form->validate();

        $data = $this->form->toData();

        $user = $this->model instanceof User
            ? $updateUser->handle($this->model, $data)
            : $createUser->handle($data);

        $this->form->id = $user->id;
        $this->form->password = null;
        $this->form->password_confirmation = null;

        return $this->redirectWithToast('users.index', __('¡Listo!'), __('Usuario guardado correctamente.'));
    }

    #[Computed]
    public function title(): string
    {
        return $this->model instanceof User ? __('Editar usuario') : __('Crear usuario');
    }

    /**
     * ¿Se puede subir el avatar en esta pantalla?
     *
     * Dos condiciones. El toggle, porque sin el módulo Files no hay binding de
     * `FileStore` y resolverlo lanzaría. Y que el usuario **exista**: un archivo
     * cuelga de una fila, y en el alta todavía no la hay. Quien quiera avatar en
     * la creación tendría que guardar el fichero en la sesión y moverlo después,
     * que es exactamente la clase de estado a medias que este módulo evita.
     */
    #[Computed]
    public function avatarEnabled(): bool
    {
        return (bool) config('kore-app.files.enabled') && $this->model instanceof User;
    }

    /**
     * El avatar vigente, ya serializado para `<x-files::slot-upload>`.
     *
     * Sale del componente como ARRAY y no como modelo: lo que llega a una Blade
     * es un dato, nunca Eloquent (R30). Las cuatro claves son las que
     * `<x-kore::upload static>` entiende.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function avatar(): ?array
    {
        if (! (bool) config('kore-app.files.enabled') || ! $this->model instanceof User) {
            return null;
        }

        $store = resolve(FileStore::class);
        $current = $store->current($this->model, $this->avatarSlot());

        if (! $current instanceof StoredFileData) {
            return null;
        }

        return [
            'name' => $current->name,
            'size' => $current->size,
            'type' => $current->mimeType,
            'url' => $current->isImage() ? $store->url($current->id) : null,
        ];
    }

    /**
     * Roles asignables, serializados a `{value, label}` para
     * `<x-kore::select :options>`.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function roles(): array
    {
        return array_map(
            fn (RoleOptionData $role): array => $role->toArray(),
            resolve(AuthorizationCatalog::class)->assignableRoles(),
        );
    }

    /**
     * Estructura de módulos para el editor de permisos. Cada item tiene
     * `module`, `permissions` (lista de {value,label}) y `roles` (metadata
     * usada por Alpine para auto-seleccionar al elegir un rol).
     *
     * Llega por el contrato de Core: el módulo Users no conoce `Module` ni
     * `Role` (regla 3 de CLAUDE.md).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function modules(): array
    {
        return array_map(
            fn (PermissionModuleData $module): array => $module->toArray(),
            resolve(AuthorizationCatalog::class)->permissionModules(),
        );
    }

    public function render(): mixed
    {
        return view('users::livewire.form-component');
    }

    /**
     * Dueño, slot y validación del avatar.
     *
     * El slot no lleva `key`: un usuario tiene un avatar, no una lista. Y no es
     * `public`, así que el fichero va al disco privado y sólo se alcanza por la
     * URL firmada de `FileStore::url()` — que es lo que hace que cambiar la foto
     * no deje la anterior colgando en `/storage` para siempre.
     *
     * @return array{owner: HasMedia, slot: FileSlotData, rules: array<int, mixed>}
     */
    protected function slotUploadTarget(): array
    {
        return [
            'owner' => $this->avatarOwner(),
            'slot' => $this->avatarSlot(),
            'rules' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * Quién sube. El componente sí puede mirar la sesión; el trait vive en
     * `Core` y no (R19).
     */
    protected function slotUploadActorId(): int
    {
        return (int) auth()->id();
    }

    /**
     * La misma policy que gobierna el formulario: quien puede editar al usuario
     * puede cambiarle la foto, y nadie más. Los archivos no tienen policy
     * propia — la tiene su dueño.
     *
     * Corre en cada subida y en cada archivado, porque la llamada viaja por
     * `/livewire/update` y ahí el `permission:users.edit` de la ruta no está
     * (R23).
     */
    protected function authorizeSlotUpload(): void
    {
        $this->authorize('update', $this->avatarOwner());
    }

    /**
     * El usuario al que pertenece el avatar.
     *
     * En la pantalla de alta todavía no hay fila, así que la vista ni siquiera
     * pinta la zona de subida. Pero `/livewire/update` acepta llamadas a
     * cualquier método público del componente, la vista no es la frontera: sin
     * este corte, `uploadSlot()` sobre el formulario de creación llegaría a
     * `authorize('update', null)` y reventaría con un TypeError en vez de con un
     * 403.
     */
    private function avatarOwner(): User
    {
        abort_unless($this->model instanceof User, 403);

        return $this->model;
    }

    private function avatarSlot(): FileSlotData
    {
        return new FileSlotData(collection: 'avatar');
    }
}
