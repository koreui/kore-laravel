<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Contracts\FileStore;
use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\HasMedia;

/**
 * Subir y archivar el archivo de un slot desde un componente Livewire.
 *
 * Es el otro extremo de `App\Core\Contracts\FileStore`: el contrato dice cómo se
 * guarda un archivo, y esto dice cómo se pide desde una pantalla. El bloque que
 * envuelve —recibir el fichero temporal, autorizar, validar, llamar al store,
 * limpiar la propiedad y refrescar la relación— es el mismo en todas las
 * pantallas que suben algo; lo único que cambia de una a otra es **de quién** es
 * el archivo, **qué slot** ocupa y **quién** puede tocarlo, que es justo lo que
 * el componente implementa.
 *
 * ```php
 * final class FormComponent extends Component
 * {
 *     use HandlesSlotUploads;
 *
 *     protected function slotUploadTarget(): array
 *     {
 *         return [
 *             'owner' => $this->model,
 *             'slot' => new FileSlotData(collection: 'avatar'),
 *             'rules' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
 *         ];
 *     }
 *
 *     protected function slotUploadActorId(): int
 *     {
 *         return (int) auth()->id();
 *     }
 *
 *     protected function authorizeSlotUpload(): void
 *     {
 *         $this->authorize('update', $this->model);
 *     }
 * }
 * ```
 *
 * **R19 · por qué el actor entra por un método y no por `auth()`.** Este trait
 * vive en `Core`, y en `Core` los helpers `auth()`, `request()` y `session()`
 * están prohibidos: lo verifica `phpstan-disallowed.neon`. No es purismo —es que
 * el mismo código tiene que servir desde un job o un comando, donde esos helpers
 * devuelven `null` en silencio y el archivo acabaría sin dueño—. El componente
 * sí puede llamarlos, porque es capa de entrega, y por eso `slotUploadActorId()`
 * lo implementa él.
 *
 * **R23 · dónde queda la autorización.** `uploadSlot()` llama a
 * `authorizeSlotUpload()` **siempre** y antes de tocar nada, y el método es
 * `abstract`: un componente que se olvide de autorizar no llega a compilar. Eso
 * es más fuerte que el check textual de R23 —que barre los `public function`
 * cuyo nombre empieza por un verbo de escritura dentro de
 * `app/Modules/{X}/Http/Livewire` y no alcanza ni a este archivo (vive en `Core`)
 * ni a `uploadSlot`/`authorizeSlotUpload` (no empiezan por uno de esos
 * verbos)—, pero no lo sustituye: si el componente además escribe algo por su
 * cuenta, el check sigue mirándolo.
 *
 * **Por qué `deleteUpload()` archiva y no borra.** Es el nombre que
 * `<x-kore::upload deletable>` invoca por defecto (`delete_method` en
 * `config/kore-ui.php`), así que es lo que ocurre cuando alguien pulsa la
 * papelera en la interfaz. Y lo que tiene que ocurrir ahí es archivar: el
 * fichero sigue existiendo, deja de ser el vigente y el historial lo conserva.
 * Borrar de verdad es `FileStore::delete()`, y no hay ningún botón que lo llame.
 *
 * @phpstan-require-extends Component
 */
trait HandlesSlotUploads
{
    use WithFileUploads;

    /**
     * El fichero recién elegido, mientras viaja del navegador al servidor.
     *
     * `mixed` y no `?TemporaryUploadedFile` porque Livewire asigna aquí el
     * valor crudo del cliente antes de convertirlo, y un type hint estrecho
     * revienta la hidratación en vez de fallar la validación.
     */
    public mixed $slotUpload = null;

    /**
     * Sube el fichero elegido como versión nueva del slot.
     *
     * Autorizar → validar → store. Ni una línea de negocio: la escritura entera
     * vive detrás de `FileStore` (R4).
     */
    public function uploadSlot(): void
    {
        $this->authorizeSlotUpload();

        $target = $this->slotUploadTarget();

        $this->validate(['slotUpload' => $target['rules']]);

        $file = $this->slotUpload;

        if (! $file instanceof UploadedFile) {
            return;
        }

        resolve(FileStore::class)->store(
            $target['owner'],
            $file,
            $target['slot'],
            $this->slotUploadActorId(),
        );

        $this->afterSlotChange($target['owner']);
    }

    /**
     * Archiva el archivo vigente del slot: lo que hace la papelera de
     * `<x-kore::upload deletable>`.
     *
     * koreUi invoca este método con el fichero que había en la lista
     * (`['name' => …]`); aquí no hace falta, porque el slot ya dice cuál es el
     * vigente, pero el parámetro tiene que existir para que la llamada no
     * reviente con un `ArgumentCountError`.
     */
    public function deleteUpload(mixed $file = null): void
    {
        $this->archiveSlot();
    }

    /**
     * Archiva el archivo vigente del slot, si lo hay.
     *
     * Pasa por la misma autorización que subir: quien no puede reemplazar el
     * archivo tampoco puede retirarlo.
     */
    public function archiveSlot(): void
    {
        $this->authorizeSlotUpload();

        $target = $this->slotUploadTarget();
        $store = resolve(FileStore::class);
        $current = $store->current($target['owner'], $target['slot']);

        if (! $current instanceof StoredFileData) {
            return;
        }

        $store->archive($current->id);

        $this->afterSlotChange($target['owner']);
    }

    /**
     * Quién es el dueño, qué slot y con qué reglas se valida el fichero.
     *
     * @return array{owner: HasMedia, slot: FileSlotData, rules: array<int, mixed>}
     */
    abstract protected function slotUploadTarget(): array;

    /**
     * Id de quien sube. El componente sí puede mirar la sesión; este trait no
     * (R19, ver el docblock de arriba).
     */
    abstract protected function slotUploadActorId(): int;

    /**
     * Autoriza subir o archivar en este slot. Lo normal es delegar en la policy
     * del dueño: los archivos no tienen policy propia, la tiene quien los lleva
     * colgando.
     */
    abstract protected function authorizeSlotUpload(): void;

    /**
     * Deja la pantalla coherente después de tocar el slot.
     *
     * Los tres pasos importan:
     *
     *   - soltar `$slotUpload` evita que el mismo fichero temporal se vuelva a
     *     subir en el siguiente round-trip;
     *   - `unsetRelation('media')` tira la relación cacheada: sin esto el
     *     componente seguiría pintando el archivo anterior durante el resto de
     *     la petición, que es el despiste más caro de media-library;
     *   - `slot-uploaded` avisa a quien esté escuchando (una tabla, una vista
     *     hermana) de que el archivo cambió.
     */
    private function afterSlotChange(HasMedia $owner): void
    {
        $this->slotUpload = null;

        if ($owner instanceof Model) {
            $owner->unsetRelation('media');
        }

        $this->dispatch('slot-uploaded');
    }
}
