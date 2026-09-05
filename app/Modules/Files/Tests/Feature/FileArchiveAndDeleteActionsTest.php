<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Models\User;
use App\Modules\Files\Actions\FileArchiveAction;
use App\Modules\Files\Actions\FileDeleteAction;
use App\Modules\Files\Actions\FileStoreAction;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| Archivar ≠ borrar
|--------------------------------------------------------------------------
|
| Las dos Actions que sacan un archivo de la vista, y la diferencia entre ellas
| es el punto entero del módulo: `FileArchiveAction` es reversible y la llama la
| interfaz; `FileDeleteAction` no lo es y sólo la llaman el dueño y la purga.
|
*/

beforeEach(function (): void {
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->slot = new FileSlotData(collection: 'avatar');
    $this->media = resolve(FileStoreAction::class)->handle(
        $this->owner,
        UploadedFile::fake()->image('a.png'),
        $this->slot,
        $this->owner->id,
    );
});

it('archivar deja el fichero en disco y la fila fuera de la vista', function (): void {
    resolve(FileArchiveAction::class)->handle((int) $this->media->getKey());

    $this->media->refresh();

    expect($this->media->getCustomProperty(MediaSlots::IS_CURRENT))->toBeFalse()
        ->and($this->media->getCustomProperty(MediaSlots::REPLACED_AT))->toBeString()
        ->and(Storage::disk('local')->exists($this->media->getPathRelativeToRoot()))->toBeTrue()
        ->and(MediaSlots::current($this->owner->fresh(), $this->slot))->toBeNull();
});

it('archivar dos veces no mueve la marca de tiempo original', function (): void {
    $archivar = resolve(FileArchiveAction::class);

    $archivar->handle((int) $this->media->getKey());
    $primera = $this->media->fresh()?->getCustomProperty(MediaSlots::REPLACED_AT);

    // La marca dice cuándo dejó de valer, no cuándo alguien volvió a pulsar el
    // botón.
    $this->travel(1)->hours();
    $archivar->handle((int) $this->media->getKey());

    expect($this->media->fresh()?->getCustomProperty(MediaSlots::REPLACED_AT))->toBe($primera);
});

it('archivar un id que no existe no es un error', function (): void {
    resolve(FileArchiveAction::class)->handle(999_999);

    expect(Media::count())->toBe(1);
});

it('borrar se lleva la fila y el fichero', function (): void {
    $path = $this->media->getPathRelativeToRoot();

    resolve(FileDeleteAction::class)->handle((int) $this->media->getKey());

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('borrar un id que no existe no es un error', function (): void {
    resolve(FileDeleteAction::class)->handle(999_999);

    expect(Media::count())->toBe(1);
});
