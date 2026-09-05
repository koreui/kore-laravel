<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Core\Actions\Action;
use App\Core\Enums\FileCompressionStatus;
use App\Modules\Files\Support\MediaSlots;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Comprime el fichero de un archivo ya guardado, si se puede.
 *
 * Dos caminos y una regla común:
 *
 * - **PDF**: Ghostscript con `-dPDFSETTINGS=/ebook`, que reduce la resolución
 *   de las imágenes incrustadas a 150 ppp. Es el ajuste que deja legible un
 *   documento escaneado y le quita la mitad del peso.
 * - **Imágenes**: `Spatie\Image\Image` —que ya viene con media-library— a la
 *   calidad de `files.compression.image_quality`.
 *
 * Y la regla: **el resultado sólo sustituye al original si de verdad es más
 * pequeño**. Recomprimir un JPEG ya optimizado, o un PDF que sólo tiene texto,
 * suele engordarlo; sin esta comprobación la «compresión» costaría espacio y
 * calidad a la vez.
 *
 * ## Fallar aquí no cuesta el archivo
 *
 * Cualquier problema —Ghostscript no está instalado, el PDF está corrupto, la
 * imagen no la entiende el driver— acaba en `failed` o `skipped` y **deja el
 * fichero original intacto y servible**. Comprimir es una optimización, no un
 * paso del guardado: el archivo ya estaba a salvo antes de llegar aquí.
 *
 * La diferencia entre los dos estados: `skipped` es «no había nada que hacer»
 * (un `.zip`, un `.docx`, o Ghostscript ausente en una máquina que no lo
 * necesita) y `failed` es «había que hacerlo y no se pudo». Sólo el segundo
 * merece que alguien mire.
 */
final class FileCompressAction extends Action
{
    /** @var list<string> */
    private const array IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    public function handle(int $fileId): FileCompressionStatus
    {
        $media = Media::find($fileId);

        if (! $media instanceof Media) {
            return FileCompressionStatus::Skipped;
        }

        $mime = (string) $media->mime_type;

        $status = match (true) {
            $mime === 'application/pdf' => $this->ghostscript() === null
                // Sin Ghostscript no hay fallo que reportar: es una máquina que
                // no comprime PDF, y el original se queda tal cual.
                ? FileCompressionStatus::Skipped
                : $this->compressWith($media, $this->compressPdf(...), 'pdf'),
            in_array($mime, self::IMAGE_MIMES, true) => $this->compressWith(
                $media,
                $this->compressImage(...),
                pathinfo($media->file_name, PATHINFO_EXTENSION),
            ),
            default => FileCompressionStatus::Skipped,
        };

        $media->setCustomProperty(MediaSlots::COMPRESSION_STATUS, $status->value);
        $media->save();

        return $status;
    }

    /**
     * El esqueleto común: bajar el fichero a un temporal, dejar que el
     * compresor escriba otro al lado, quedarse con el más pequeño y limpiar.
     *
     * @param callable(string, string): void $compressor recibe (entrada, salida)
     */
    private function compressWith(Media $media, callable $compressor, string $extension): FileCompressionStatus
    {
        $directory = $this->temporaryDirectory();
        $input = $directory.'/input'.($extension !== '' ? '.'.$extension : '');
        $output = $directory.'/output'.($extension !== '' ? '.'.$extension : '');

        try {
            $disk = Storage::disk($media->disk);
            $path = $media->getPathRelativeToRoot();
            $contents = $disk->get($path);

            if ($contents === null) {
                return FileCompressionStatus::Failed;
            }

            file_put_contents($input, $contents);

            $compressor($input, $output);

            return $this->keepSmaller($media, $disk, $path, $input, $output);
        } catch (Throwable) {
            return FileCompressionStatus::Failed;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($directory);
        }
    }

    /**
     * Sustituye el fichero sólo si el comprimido pesa menos, y actualiza el
     * tamaño de la fila para que no mienta.
     */
    private function keepSmaller(Media $media, Filesystem $disk, string $path, string $input, string $output): FileCompressionStatus
    {
        $originalSize = (int) filesize($input);
        $compressedSize = is_file($output) ? (int) filesize($output) : 0;

        if ($compressedSize > 0 && $compressedSize < $originalSize) {
            $disk->put($path, (string) file_get_contents($output));
            $media->size = $compressedSize;
        }

        return FileCompressionStatus::Done;
    }

    /**
     * Ruta del binario de Ghostscript, o `null` si no está instalado.
     *
     * Se resuelve con `ExecutableFinder` y no se asume que `gs` esté en el
     * `PATH` del proceso: en un contenedor de PHP-FPM casi nunca lo está.
     */
    private function ghostscript(): ?string
    {
        return new ExecutableFinder()->find(
            (string) config('files.compression.ghostscript_binary', 'gs')
        );
    }

    /**
     * Ghostscript sobre el fichero temporal.
     *
     * `mustRun()` lanza si el proceso falla, y el `catch` de `compressWith()` lo
     * traduce a `failed`: el PDF que Ghostscript no entiende es un problema del
     * contenido, no de la máquina.
     */
    private function compressPdf(string $input, string $output): void
    {
        $process = new Process([
            (string) $this->ghostscript(),
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/ebook',
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-sOutputFile='.$output,
            $input,
        ]);

        $process->setTimeout(100);
        $process->mustRun();
    }

    private function compressImage(string $input, string $output): void
    {
        Image::load($input)
            ->quality((int) config('files.compression.image_quality', 85))
            ->save($output);
    }

    /**
     * Carpeta temporal propia por ejecución: dos jobs a la vez no pueden
     * pisarse el `input.pdf`.
     */
    private function temporaryDirectory(): string
    {
        $base = config('files.compression.tmp_dir');
        $base = is_string($base) && $base !== '' ? rtrim($base, '/') : sys_get_temp_dir();

        $directory = $base.'/kore-files-'.Str::uuid()->toString();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }
}
