<?php

declare(strict_types=1);

use App\Core\Data\FileSlotData;
use App\Models\User;
use App\Modules\Files\Actions\FileArchiveAction;
use App\Modules\Files\Actions\FileStoreAction;
use App\Modules\Files\Support\MediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
|--------------------------------------------------------------------------
| files:cleanup
|--------------------------------------------------------------------------
|
| Tres condiciones para borrar y las tres a la vez: archivado, con `replaced_at`
| y anterior al corte. La vigente no se toca nunca, tenga la edad que tenga.
|
*/

/**
 * Un archivo archivado hace `$days` días.
 */
function archivedDaysAgo(User $owner, int $days, string $collection): Media
{
    $media = resolve(FileStoreAction::class)->handle(
        $owner,
        UploadedFile::fake()->image('a.png'),
        new FileSlotData(collection: $collection),
        $owner->id,
    );

    resolve(FileArchiveAction::class)->handle((int) $media->getKey());

    $media->refresh();
    $media->setCustomProperty(MediaSlots::REPLACED_AT, CarbonImmutable::now()->subDays($days)->toIso8601String());
    $media->save();

    return $media;
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->owner = User::factory()->create();
});

it('borra las versiones archivadas hace más del plazo y deja las recientes', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $vieja = archivedDaysAgo($owner, 60, 'vieja');
        $reciente = archivedDaysAgo($owner, 5, 'reciente');

        $this->artisan('files:cleanup', ['--days' => 30])->assertSuccessful();

        expect(Media::find($vieja->getKey()))->toBeNull()
            ->and(Media::find($reciente->getKey()))->not->toBeNull()
            ->and(Storage::disk('local')->exists($vieja->getPathRelativeToRoot()))->toBeFalse();
    });
});

it('no toca la versión vigente por antigua que sea', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $vigente = resolve(FileStoreAction::class)->handle(
            $owner,
            UploadedFile::fake()->image('a.png'),
            new FileSlotData(collection: 'avatar'),
            $owner->id,
        );

        // Una fila con `replaced_at` puesto pero todavía vigente es un estado
        // incoherente que podría venir de una corrida a medias: ante la duda, se
        // conserva.
        $vigente->setCustomProperty(MediaSlots::REPLACED_AT, CarbonImmutable::now()->subYear()->toIso8601String());
        $vigente->save();

        $this->artisan('files:cleanup', ['--days' => 1])->assertSuccessful();

        expect(Media::find($vigente->getKey()))->not->toBeNull();
    });
});

it('el ensayo cuenta lo mismo y no borra nada', function (): void {
    withEnvironment(['FILES_ENABLED' => 'true'], function (): void {
        Storage::fake('local');

        $owner = User::factory()->create();
        $vieja = archivedDaysAgo($owner, 60, 'vieja');

        $this->artisan('files:cleanup', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Simulacro')
            ->assertSuccessful();

        expect(Media::find($vieja->getKey()))->not->toBeNull()
            ->and(Storage::disk('local')->exists($vieja->getPathRelativeToRoot()))->toBeTrue();
    });
});
