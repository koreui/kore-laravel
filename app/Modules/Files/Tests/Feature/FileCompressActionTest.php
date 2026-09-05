<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Core\Enums\FileCompressionStatus;
use App\Models\User;
use App\Modules\Files\Actions\FileCompressAction;
use App\Modules\Files\Actions\FileStoreAction;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| FileCompressAction
|--------------------------------------------------------------------------
|
| Lo que se comprueba aquí no es cuánto comprime —eso depende de Ghostscript y
| del driver de imagen de la máquina— sino la regla que hace que comprimir sea
| seguro: **fallar no cuesta el archivo**. Pase lo que pase, el fichero original
| sigue en disco y servible, y lo único que cambia es el estado.
|
*/

beforeEach(function (): void {
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->compress = resolve(FileCompressAction::class);
});

/**
 * Guarda un fichero y devuelve su `Media`.
 */
function storedFile(User $owner, UploadedFile $file, string $collection = 'documentos'): Media
{
    return resolve(FileStoreAction::class)->handle(
        $owner,
        $file,
        new FileSlotData(collection: $collection),
        $owner->id,
    );
}

it('salta los tipos que no sabe comprimir', function (): void {
    $media = storedFile($this->owner, UploadedFile::fake()->create('datos.zip', 20, 'application/zip'));

    expect($this->compress->handle((int) $media->getKey()))->toBe(FileCompressionStatus::Skipped)
        ->and($media->fresh()?->getCustomProperty(MediaSlots::COMPRESSION_STATUS))
        ->toBe(FileCompressionStatus::Skipped->value);
});

it('salta los PDF cuando Ghostscript no está instalado', function (): void {
    // Un binario que no existe: es el caso de una máquina que no comprime PDF,
    // y eso no es un fallo. `skipped` es «no había nada que hacer aquí».
    Config::set('files.compression.ghostscript_binary', 'ghostscript-que-no-existe-'.uniqid());

    $media = storedFile($this->owner, UploadedFile::fake()->create('acta.pdf', 40, 'application/pdf'));

    expect($this->compress->handle((int) $media->getKey()))->toBe(FileCompressionStatus::Skipped);
});

it('deja el fichero intacto cuando el contenido no es lo que dice ser', function (): void {
    // `create()` produce un fichero de relleno con nombre de imagen: no hay nada
    // que un driver pueda abrir. Da igual si acaba en `failed` (lo intentó) o en
    // `skipped` (el mime real no era de imagen); lo que NO puede pasar es que el
    // fichero original se pierda o cambie.
    $media = storedFile($this->owner, UploadedFile::fake()->create('rota.png', 5, 'image/png'));
    $path = $media->getPathRelativeToRoot();
    $antes = Storage::disk('local')->get($path);

    expect($this->compress->handle((int) $media->getKey()))->not->toBe(FileCompressionStatus::Done)
        ->and($media->fresh()?->getCustomProperty(MediaSlots::COMPRESSION_STATUS))
        ->not->toBe(FileCompressionStatus::Done->value)
        ->and(Storage::disk('local')->get($path))->toBe($antes);
});

it('comprime una imagen de verdad sin engordarla', function (): void {
    $media = storedFile($this->owner, UploadedFile::fake()->image('foto.jpg', 400, 400));
    $original = (int) $media->size;

    expect($this->compress->handle((int) $media->getKey()))->toBe(FileCompressionStatus::Done)
        // La regla: el resultado sólo sustituye al original si pesa menos, así
        // que el tamaño nunca sube.
        ->and((int) $media->fresh()?->size)->toBeLessThanOrEqual($original)
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeTrue();
})->skip(
    ! extension_loaded('gd') && ! extension_loaded('imagick'),
    'Sin driver de imagen (gd o imagick) no hay nada que comprimir.',
);

it('un id que no existe se salta', function (): void {
    expect($this->compress->handle(999_999))->toBe(FileCompressionStatus::Skipped);
});
