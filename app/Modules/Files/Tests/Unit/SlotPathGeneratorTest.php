<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Files\Support\SlotPathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| SlotPathGenerator
|--------------------------------------------------------------------------
|
| El disco tiene que poder leerse sin preguntarle nada a la base: quién es el
| dueño y de qué colección es el fichero se ven en la ruta. Y sin cargar la
| relación `model`, porque esto corre por cada fichero que se sirve.
|
*/

/**
 * Un `Media` en memoria, sin tocar la base.
 */
function mediaForPath(string $modelType, int $modelId, string $collection, int $id): Media
{
    $media = new Media;
    $media->forceFill([
        'id' => $id,
        'model_type' => $modelType,
        'model_id' => $modelId,
        'collection_name' => $collection,
    ]);

    return $media;
}

it('reparte los ficheros por dueño, colección y versión', function (): void {
    $path = new SlotPathGenerator()->getPath(
        mediaForPath(User::class, 17, 'avatar', 42)
    );

    expect($path)->toBe('users/17/avatar/42/');
});

it('cuelga conversiones y responsivas de la misma carpeta', function (): void {
    $generator = new SlotPathGenerator;
    $media = mediaForPath(User::class, 17, 'avatar', 42);

    expect($generator->getPathForConversions($media))->toBe('users/17/avatar/42/conversions/')
        ->and($generator->getPathForResponsiveImages($media))->toBe('users/17/avatar/42/responsive-images/');
});

it('respeta el prefijo global de media-library', function (): void {
    config()->set('media-library.prefix', 'archivos');

    expect(new SlotPathGenerator()->getPath(mediaForPath(User::class, 3, 'documentos', 9)))
        ->toBe('archivos/users/3/documentos/9/');
});

it('sanea el nombre de la colección', function (): void {
    // La colección la elige quien programa, pero puede llevar espacios o
    // acentos; la ruta no.
    expect(new SlotPathGenerator()->getPath(mediaForPath(User::class, 3, 'Actas Notariales', 9)))
        ->toBe('users/3/actas-notariales/9/');
});

it('no se queda sin ruta si el morph ya no resuelve a una clase', function (): void {
    // Renombrar una clase deja filas apuntando a un `model_type` huérfano. El
    // fichero sigue existiendo y tiene que poder alcanzarse.
    expect(new SlotPathGenerator()->getPath(mediaForPath('App\\Modelo\\Borrado', 3, 'documentos', 9)))
        ->toBe('app-modelo-borrado/3/documentos/9/');
});
