<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;

/*
|--------------------------------------------------------------------------
| Los dos DTOs del contrato de archivos
|--------------------------------------------------------------------------
*/

it('la huella es la misma sin importar el orden de las claves', function (): void {
    $uno = new FileSlotData(collection: 'documentos', key: ['tipo' => 'acta', 'anio' => 2026]);
    $otro = new FileSlotData(collection: 'documentos', key: ['anio' => 2026, 'tipo' => 'acta']);

    expect($uno->fingerprint())->toBe($otro->fingerprint());
});

it('slots distintos dan huellas distintas', function (): void {
    $base = new FileSlotData(collection: 'documentos', key: ['tipo' => 'acta']);

    expect($base->fingerprint())
        ->not->toBe(new FileSlotData(collection: 'documentos', key: ['tipo' => 'anexo'])->fingerprint())
        ->not->toBe(new FileSlotData(collection: 'otra', key: ['tipo' => 'acta'])->fingerprint())
        // Público y privado no son el mismo hueco: acaban en discos distintos.
        ->not->toBe(new FileSlotData(collection: 'documentos', key: ['tipo' => 'acta'], public: true)->fingerprint());
});

it('la huella empieza por la colección para poder leer la fila a ojo', function (): void {
    expect(new FileSlotData(collection: 'avatar')->fingerprint())->toStartWith('avatar:');
});

it('la huella es estable entre llamadas', function (): void {
    // Si cambiara, todo el historial de un slot quedaría huérfano de golpe.
    $slot = new FileSlotData(collection: 'avatar', key: ['a' => 1]);

    expect($slot->fingerprint())->toBe($slot->fingerprint());
});

it('un slot es privado salvo que se diga lo contrario', function (): void {
    expect(new FileSlotData(collection: 'avatar')->public)->toBeFalse();
});

it('isImage mira el mime y no la extensión', function (): void {
    // La extensión la elige quien sube el fichero; el mime lo determina el
    // servidor al recibirlo.
    expect(storedFileData('foto.txt', 'image/png')->isImage())->toBeTrue()
        ->and(storedFileData('trampa.png', 'application/pdf')->isImage())->toBeFalse()
        ->and(storedFileData('sin-mime.png', null)->isImage())->toBeFalse();
});

function storedFileData(string $name, ?string $mimeType): StoredFileData
{
    return new StoredFileData(
        id: 1,
        uuid: null,
        name: $name,
        mimeType: $mimeType,
        size: 100,
        version: 1,
        isCurrent: true,
        uploadedBy: null,
        compression: FileCompressionStatus::Pending,
        sync: FileSyncStatus::Local,
        createdAt: '2026-09-04T10:00:00+00:00',
        replacedAt: null,
    );
}
