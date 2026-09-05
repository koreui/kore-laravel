<?php

declare(strict_types=1);

use App\Core\Contracts\FileStore;
use App\Core\Data\FileSlotData;
use App\Core\Data\StoredFileData;
use App\Core\Enums\FileCompressionStatus;
use App\Core\Enums\FileSyncStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| MediaFileStore — el contrato visto desde fuera
|--------------------------------------------------------------------------
|
| Aquí no se toca `Media` ni una vez: se habla sólo el idioma de
| `App\Core\Contracts\FileStore` y sus DTOs, que es exactamente lo que ve un
| módulo cliente. Si estos tests siguen pasando, cambiar el paquete de por debajo
| no rompe a nadie.
|
| El toggle tiene que estar encendido: sin él no hay binding.
|
*/

it('guarda, devuelve el vigente y ordena el historial de más nuevo a más viejo', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $slot = new FileSlotData(collection: 'avatar');
        $store = resolve(FileStore::class);

        $primera = $store->store($owner, UploadedFile::fake()->image('uno.png'), $slot, $owner->id);
        $segunda = $store->store($owner, UploadedFile::fake()->image('dos.png'), $slot, $owner->id);

        expect($primera)->toBeInstanceOf(StoredFileData::class)
            ->and($primera->version)->toBe(1)
            ->and($primera->isImage())->toBeTrue()
            ->and($primera->uploadedBy)->toBe($owner->id)
            ->and($primera->compression)->toBe(FileCompressionStatus::Pending)
            ->and($primera->sync)->toBe(FileSyncStatus::Synced)
            ->and($primera->createdAt)->toBeString();

        $vigente = $store->current($owner->fresh(), $slot);

        expect($vigente?->id)->toBe($segunda->id)
            ->and($vigente?->version)->toBe(2)
            ->and($vigente?->isCurrent)->toBeTrue();

        $historial = $store->history($owner->fresh(), $slot);

        expect($historial)->toHaveCount(2)
            ->and($historial->first()?->version)->toBe(2)
            ->and($historial->last()?->version)->toBe(1)
            ->and($historial->last()?->isCurrent)->toBeFalse()
            ->and($historial->last()?->replacedAt)->toBeString();
    });
});

it('archivar deja el slot sin vigente sin tocar el historial', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $slot = new FileSlotData(collection: 'avatar');
        $store = resolve(FileStore::class);

        $archivo = $store->store($owner, UploadedFile::fake()->image('a.png'), $slot, $owner->id);
        $store->archive($archivo->id);

        expect($store->current($owner->fresh(), $slot))->toBeNull()
            ->and($store->history($owner->fresh(), $slot))->toHaveCount(1);
    });
});

it('borrar sí saca el archivo del historial', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $slot = new FileSlotData(collection: 'avatar');
        $store = resolve(FileStore::class);

        $archivo = $store->store($owner, UploadedFile::fake()->image('a.png'), $slot, $owner->id);
        $store->delete($archivo->id);

        expect($store->history($owner->fresh(), $slot))->toBeEmpty();
    });
});

it('la URL lleva firma y el v del fichero dentro de lo firmado', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $store = resolve(FileStore::class);
        $archivo = $store->store($owner, UploadedFile::fake()->image('a.png'), new FileSlotData(collection: 'avatar'), $owner->id);

        $url = $store->url($archivo->id);

        expect($url)->toContain('/files/'.$archivo->id)
            ->toContain('v=')
            ->toContain('signature=')
            ->toContain('expires=');

        // El `v` va DENTRO de la firma: si se pudiera cambiar a mano, la URL
        // seguiría valiendo y el navegador seguiría enseñando la copia vieja.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $manipulada = str_replace('v='.$query['v'], 'v=0', $url);

        $this->actingAs($owner)->get($manipulada)->assertForbidden();
    });
});

it('con la relación media cargada no consulta la base para resolver el slot', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $slot = new FileSlotData(collection: 'avatar');
        $store = resolve(FileStore::class);
        $store->store($owner, UploadedFile::fake()->image('a.png'), $slot, $owner->id);

        // Así es como lo usa una tabla: `->with('media')` una vez y después una
        // llamada por fila. Es lo que evita el N+1 de veinticinco avatares.
        $conMedia = User::query()->with('media')->findOrFail($owner->id);

        DB::enableQueryLog();
        $vigente = $store->current($conMedia, $slot);
        $url = $store->url((int) $vigente?->id);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        expect($vigente)->not->toBeNull()
            ->and($url)->toContain('v=')
            ->and($consultas)->toBeEmpty();
    });
});
