<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Dónde acaba cada fichero dentro del disco.
 *
 *     {tabla_del_dueño}/{id_del_dueño}/{colección}/{id_del_media}/archivo.ext
 *     users/17/avatar/42/perfil.png
 *
 * El generador por defecto del paquete pone todo en `{id_del_media}/`, así que
 * el disco acaba siendo una lista plana de números: para saber de quién es un
 * fichero hay que preguntarle a la base. Con esta forma el disco se puede leer
 * —y auditar, y respaldar por dueño— sin consultar nada, que es lo que se
 * agradece el día que hay que responder «bórrame todo lo mío».
 *
 * Es un port del `CustomPathGenerator` de Notarium. Lo único que cambia es que
 * el segmento del dueño sale de `getTable()` en vez de un `match` sobre los
 * modelos conocidos: aquí no se sabe qué modelos tendrá el proyecto derivado.
 *
 * El id del media sigue estando, y es lo que hace que dos versiones del mismo
 * slot no se pisen: cada versión es un `Media` distinto, así que cada una tiene
 * su carpeta aunque el fichero se llame igual.
 *
 * **`media-library.moves_media_on_update` sigue en `false`** a propósito: los
 * cuatro segmentos son inmutables (nadie cambia de dueño ni de colección un
 * archivo ya subido), así que no hay nada que mover.
 */
final class SlotPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    /**
     * `{prefijo}/{tabla}/{id}/{colección}/{id del media}`, sin barras sueltas.
     *
     * `$media->model` NO se toca: cargarlo aquí dispararía una consulta por
     * fichero justo en el camino que sirve los ficheros. La tabla y el id se
     * deducen de `model_type` / `model_id`, que ya están en la fila.
     */
    private function basePath(Media $media): string
    {
        $modelClass = Relation::getMorphedModel((string) $media->model_type) ?? (string) $media->model_type;

        $segments = array_filter([
            (string) config('media-library.prefix', ''),
            $this->tableFor($modelClass),
            (string) $media->model_id,
            Str::slug((string) $media->collection_name) ?: 'default',
            (string) $media->getKey(),
        ], static fn (string $segment): bool => $segment !== '');

        return implode('/', $segments);
    }

    /**
     * Nombre de tabla del dueño, o un `slug` de su clase si no es un modelo
     * resoluble (un alias de morph huérfano tras renombrar una clase).
     */
    private function tableFor(string $modelClass): string
    {
        if (is_a($modelClass, Model::class, true)) {
            return new $modelClass()->getTable();
        }

        return Str::slug(str_replace('\\', '-', $modelClass)) ?: 'unknown';
    }
}
