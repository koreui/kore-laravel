<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;

/**
 * Guardar, versionar y servir archivos.
 *
 * Es la frontera entre el módulo que **implementa** el almacenamiento
 * (`App\Modules\Files`, sobre spatie/laravel-medialibrary) y todo el que sólo
 * lo **usa**: un componente Livewire que sube un avatar, un controller que
 * adjunta un PDF, una Action de facturación que archiva el justificante del mes
 * pasado. Ninguno de ellos importa una sola clase de Files (R5), ni conoce
 * `Media`, ni sabe en qué disco acabó el fichero.
 *
 * La implementación se bindea en `FilesModuleServiceProvider::register()` y
 * **sólo con `FILES_ENABLED=true`**. Con el toggle apagado no hay binding: quien
 * resuelva el contrato recibe un `BindingResolutionException`, que es la
 * respuesta correcta —«esta instalación no guarda archivos»— y no un fallo
 * silencioso a mitad de una subida. Por eso quien lo consume lo hace detrás de
 * `config('kore-app.files.enabled')`.
 *
 * ## El modelo: slots y versiones
 *
 * No se guarda «un fichero en un modelo», se guarda «la versión N del slot X del
 * modelo Y» (ver {@see FileSlotData}). Reemplazar no destruye: la versión
 * anterior deja de ser la vigente (`is_current`), se le anota cuándo
 * (`replaced_at`) y se queda. Sólo `delete()` borra de verdad, y existe para el
 * dueño que se borra a sí mismo y para la purga programada — no para un botón
 * de la interfaz.
 *
 * Ver `docs/modules/files.md`.
 */
interface FileStore
{
    /**
     * Guarda el fichero como la versión siguiente del slot y archiva la anterior.
     *
     * Las versiones previas se marcan como no vigentes **después** de que el
     * fichero esté escrito, nunca antes: si la escritura falla, el slot se queda
     * con la versión que ya tenía en vez de quedarse sin ninguna.
     *
     * `$uploadedBy` es el id del actor, y llega por parámetro a propósito: el
     * contrato vive en `Core`, donde `auth()` está prohibido (R19) porque una
     * Action, un job o un comando tienen que poder guardar un archivo igual.
     */
    public function store(HasMedia $owner, UploadedFile $file, FileSlotData $slot, int $uploadedBy): StoredFileData;

    /**
     * La versión vigente del slot, o `null` si el slot está vacío.
     */
    public function current(HasMedia $owner, FileSlotData $slot): ?StoredFileData;

    /**
     * Todas las versiones del slot, de la más reciente a la más antigua.
     *
     * @return Collection<int, StoredFileData>
     */
    public function history(HasMedia $owner, FileSlotData $slot): Collection;

    /**
     * Saca el archivo de la vista sin destruirlo: `is_current = false` y
     * `replaced_at` a la hora actual.
     *
     * Idempotente: archivar dos veces no mueve la marca de tiempo original, que
     * es la que dice cuándo dejó de valer.
     */
    public function archive(int $fileId): void;

    /**
     * URL temporal firmada del archivo.
     *
     * Firmada y con caducidad porque el disco por defecto es privado: no hay
     * dirección pública que dar. `$minutes` es opcional; sin él manda
     * `files.url_ttl_minutes`.
     *
     * La URL lleva la versión del fichero **dentro de la firma**. Ver
     * `docs/modules/files.md` §«La URL firmada y el `v=`».
     */
    public function url(int $fileId, ?int $minutes = null): string;

    /**
     * Borra el archivo y su fichero, de verdad y sin vuelta atrás.
     *
     * No es lo que hace la interfaz cuando alguien pulsa «eliminar» —eso es
     * {@see archive()}—: esto lo llama el dueño cuando se borra a sí mismo y la
     * purga de `files:cleanup`, que son los dos momentos en que conservar el
     * fichero deja de ser prudencia y pasa a ser un dato guardado sin motivo.
     */
    public function delete(int $fileId): void;
}
