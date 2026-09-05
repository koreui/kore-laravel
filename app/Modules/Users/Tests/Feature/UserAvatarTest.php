<?php

declare(strict_types=1);

use App\Core\Contracts\FileStore;
use App\Core\Data\FileSlotData;
use App\Models\User;
use App\Modules\Auth\Database\Seeders\ModulesSeeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Users\Http\Livewire\FormComponent;
use App\Modules\Users\Http\Livewire\TableUsers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El avatar como consumidor de referencia de FileStore
|--------------------------------------------------------------------------
|
| Es el ejemplo que enseña el módulo Files funcionando de punta a punta dentro
| de una pantalla real: `HandlesSlotUploads` en el componente, la policy del
| dueño como única autorización y el versionado por slot por debajo.
|
| Todos los casos arrancan con `FILES_ENABLED=true`, porque sin el toggle no hay
| binding del contrato y la pantalla ni siquiera pinta la zona de subida.
|
*/

/**
 * Arranca con el módulo encendido, siembra los permisos y ejecuta el caso.
 *
 * @param Closure(User, User): void $callback recibe (editor con permisos, editado)
 */
function withAvatarScreen(Closure $callback): void
{
    withEnvironment(['FILES_ENABLED' => 'true'], function () use ($callback): void {
        Storage::fake('local');

        test()->seed(ModulesSeeder::class);

        $editor = User::factory()->create();
        $editor->assignRole(Role::SUPERADMIN);

        $editado = User::factory()->create();
        $editado->assignRole(Role::USER);

        $callback($editor, $editado);
    });
}

/**
 * El slot del avatar, tal y como lo declara `FormComponent`.
 */
function avatarSlot(): FileSlotData
{
    return new FileSlotData(collection: 'avatar');
}

it('subir crea la versión 1 del slot', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        Livewire::actingAs($editor)
            ->test(FormComponent::class, ['model' => $editado])
            ->set('slotUpload', UploadedFile::fake()->image('a.png'))
            ->call('uploadSlot')
            ->assertHasNoErrors()
            // La propiedad se suelta al terminar: si no, el mismo fichero
            // temporal volvería a subirse en el siguiente round-trip.
            ->assertSet('slotUpload', null)
            ->assertDispatched('slot-uploaded');

        $vigente = resolve(FileStore::class)->current($editado->fresh(), avatarSlot());

        expect($vigente?->version)->toBe(1)
            ->and($vigente?->isCurrent)->toBeTrue()
            ->and($vigente?->uploadedBy)->toBe($editor->id)
            ->and($vigente?->name)->toBe('a.png');
    });
});

it('subir otro crea la versión 2 y archiva la 1', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        $componente = Livewire::actingAs($editor)->test(FormComponent::class, ['model' => $editado]);

        $componente->set('slotUpload', UploadedFile::fake()->image('uno.png'))->call('uploadSlot');
        $componente->set('slotUpload', UploadedFile::fake()->image('dos.png'))->call('uploadSlot');

        $historial = resolve(FileStore::class)->history($editado->fresh(), avatarSlot());

        expect($historial)->toHaveCount(2)
            ->and($historial->first()?->version)->toBe(2)
            ->and($historial->first()?->isCurrent)->toBeTrue()
            ->and($historial->first()?->name)->toBe('dos.png')
            ->and($historial->last()?->isCurrent)->toBeFalse();
    });
});

it('la papelera del componente archiva, no borra', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        $componente = Livewire::actingAs($editor)->test(FormComponent::class, ['model' => $editado]);

        $componente->set('slotUpload', UploadedFile::fake()->image('a.png'))->call('uploadSlot');

        // `deleteUpload` es el método que `<x-kore::upload deletable>` llama por
        // defecto. Archivar es lo que tiene que hacer: el fichero se conserva.
        $componente->call('deleteUpload', ['name' => 'a.png']);

        $store = resolve(FileStore::class);

        expect($store->current($editado->fresh(), avatarSlot()))->toBeNull()
            ->and($store->history($editado->fresh(), avatarSlot()))->toHaveCount(1);
    });
});

it('el avatar vigente llega a la vista como array, no como modelo', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        $componente = Livewire::actingAs($editor)->test(FormComponent::class, ['model' => $editado]);

        $componente->set('slotUpload', UploadedFile::fake()->image('a.png'))->call('uploadSlot');

        // R30: lo que cruza a la Blade es un dato ya resuelto, con la URL
        // firmada incluida.
        $avatar = $componente->instance()->avatar();

        expect($avatar)->toBeArray()
            ->and($avatar)->toHaveKeys(['name', 'size', 'type', 'url'])
            ->and($avatar['name'])->toBe('a.png')
            ->and($avatar['url'])->toContain('signature=');
    });
});

it('quien sólo puede mirar no llega ni a montar el formulario', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        $mirón = User::factory()->create();
        $mirón->assignRole(Role::USER);
        $mirón->syncPermissions(['users.view']);

        Livewire::actingAs($mirón)
            ->test(FormComponent::class, ['model' => $editado])
            ->assertForbidden();

        expect(resolve(FileStore::class)->current($editado->fresh(), avatarSlot()))->toBeNull();
    });
});

it('perder el permiso después de montar corta la subida y el archivado', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        $editorParcial = User::factory()->create();
        $editorParcial->assignRole(Role::USER);
        $editorParcial->syncPermissions(['users.view', 'users.edit']);

        // Dos pantallas abiertas por la misma persona, montadas mientras el
        // permiso todavía estaba: un 403 deja el snapshot de Livewire
        // inservible, así que cada intento necesita el suyo.
        $paraSubir = Livewire::actingAs($editorParcial)->test(FormComponent::class, ['model' => $editado]);
        $paraArchivar = Livewire::actingAs($editorParcial)->test(FormComponent::class, ['model' => $editado]);

        $paraSubir->set('slotUpload', UploadedFile::fake()->image('a.png'))
            ->call('uploadSlot')
            ->assertHasNoErrors();

        // R23 · el formulario ya está montado y las llamadas siguientes viajan
        // por /livewire/update, donde el `permission:users.edit` de la ruta NO
        // corre. Si la autorización sólo estuviera en `mount()`, esta sesión
        // seguiría subiendo archivos después de perder el permiso.
        $editorParcial->syncPermissions(['users.view']);
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $paraSubir->set('slotUpload', UploadedFile::fake()->image('b.png'))
            ->call('uploadSlot')
            ->assertForbidden();

        $paraArchivar->call('deleteUpload')->assertForbidden();

        // La versión 1 sigue siendo la vigente: no se subió la 2 ni se archivó.
        $vigente = resolve(FileStore::class)->current($editado->fresh(), avatarSlot());

        expect($vigente?->version)->toBe(1)
            ->and($vigente?->name)->toBe('a.png');
    });
});

it('no acepta un fichero que no sea una imagen', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        Livewire::actingAs($editor)
            ->test(FormComponent::class, ['model' => $editado])
            ->set('slotUpload', UploadedFile::fake()->create('virus.exe', 10))
            ->call('uploadSlot')
            ->assertHasErrors('slotUpload');

        expect(resolve(FileStore::class)->current($editado->fresh(), avatarSlot()))->toBeNull();
    });
});

it('en el alta no hay dueño del que colgar el archivo', function (): void {
    withAvatarScreen(function (User $editor): void {
        $componente = Livewire::actingAs($editor)->test(FormComponent::class);

        expect($componente->instance()->avatarEnabled())->toBeFalse()
            ->and($componente->instance()->avatar())->toBeNull();

        // La vista no lo pinta, pero /livewire/update acepta llamadas a
        // cualquier método público: el corte tiene que estar en el componente.
        $componente->call('uploadSlot')->assertForbidden();
    });
});

it('el listado pinta el avatar sin caer en un N+1', function (): void {
    withAvatarScreen(function (User $editor, User $editado): void {
        resolve(FileStore::class)->store(
            $editado,
            UploadedFile::fake()->image('a.png'),
            avatarSlot(),
            $editor->id,
        );

        $tabla = Livewire::actingAs($editor)->test(TableUsers::class);

        $tabla->assertOk();

        // La columna existe y su valor sale resuelto desde el componente.
        $avatar = collect($tabla->instance()->columns())
            ->first(fn (object $column): bool => $column->getLabel() === __('Avatar'));

        expect($avatar)->not->toBeNull()
            ->and($avatar?->getValue(User::query()->with('media')->findOrFail($editado->id)))
            ->toContain('signature=');
    });
});

it('el listado no pinta la columna del avatar con el módulo apagado', function (): void {
    $this->seed(ModulesSeeder::class);

    $editor = User::factory()->create();
    $editor->assignRole(Role::SUPERADMIN);

    $columnas = collect(Livewire::actingAs($editor)->test(TableUsers::class)->instance()->columns())
        ->map(fn (object $column): string => (string) $column->getLabel());

    expect($columnas)->not->toContain(__('Avatar'));
});
