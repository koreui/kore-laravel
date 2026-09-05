<?php

declare(strict_types=1);

use App\Core\Contracts\FileStore;
use App\Core\Data\FileSlotData;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| GET /files/{file}
|--------------------------------------------------------------------------
|
| La ruta NO lleva `auth`: la firma ES la autorización, porque emitirla ya
| implica haber pasado por la policy del dueño (ver el docblock de
| `FileServeController`). Lo que estos tests fijan es justo eso: sin firma no se
| entra, con firma sí, y una firma para OTRA versión del fichero tampoco.
|
*/

/**
 * Arranca con el módulo encendido y prepara un archivo servible.
 *
 * @param Closure(FileStore, Media, User): void $callback
 */
function withServedFile(Closure $callback): void
{
    withEnvironment(['FILES_ENABLED' => 'true'], function () use ($callback): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $store = resolve(FileStore::class);

        $archivo = $store->store(
            $owner,
            UploadedFile::fake()->image('perfil.png'),
            new FileSlotData(collection: 'avatar'),
            $owner->id,
        );

        $callback($store, Media::findOrFail($archivo->id), $owner);
    });
}

it('rechaza la petición sin firma', function (): void {
    withServedFile(function (FileStore $store, Media $media): void {
        // 403 y no 404: el recurso existe, lo que falta es la firma.
        $this->get('/files/'.$media->getKey())->assertForbidden();
    });
});

it('rechaza una firma manipulada', function (): void {
    withServedFile(function (FileStore $store, Media $media): void {
        $url = $store->url((int) $media->getKey());

        $this->get($url.'x')->assertForbidden();
    });
});

it('sirve el fichero por stream con la URL firmada y sin sesión', function (): void {
    withServedFile(function (FileStore $store, Media $media): void {
        $response = $this->get($store->url((int) $media->getKey()));

        $response->assertOk()
            ->assertHeader('Content-Type', $media->mime_type)
            ->assertHeader('Content-Disposition', 'inline; filename="perfil.png"');

        expect($response->streamedContent())
            ->toBe(Storage::disk('local')->get($media->getPathRelativeToRoot()));
    });
});

it('la URL cambia cuando cambia el fichero, y la vieja deja de valer', function (): void {
    withServedFile(function (FileStore $store, Media $media): void {
        $antes = $store->url((int) $media->getKey());

        // Comprimir o rotar sobrescriben el fichero en su sitio: la ruta no
        // cambia, así que sin el `v` dentro de la firma el navegador seguiría
        // enseñando la copia cacheada para siempre.
        $this->travel(1)->minutes();
        $media->touch();

        $despues = resolve(FileStore::class)->url((int) $media->getKey());

        expect($despues)->not->toBe($antes);

        $this->get($despues)->assertOk();
    });
});

it('devuelve 404 si la fila existe y el fichero no', function (): void {
    withServedFile(function (FileStore $store, Media $media): void {
        $url = $store->url((int) $media->getKey());

        Storage::disk('local')->delete($media->getPathRelativeToRoot());

        $this->get($url)->assertNotFound();
    });
});
