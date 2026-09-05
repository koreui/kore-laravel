<?php

declare(strict_types=1);

namespace App\Modules\Files\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve el fichero de un archivo privado.
 *
 * ## Por qué no lleva `auth`
 *
 * **La firma ES la autorización.** La URL la emite `FileStore::url()`, y
 * emitirla es afirmar que quien la pidió ya pasó por la policy del dueño del
 * archivo: por eso se construye desde las superficies —el componente que ya
 * autorizó, el resource de la API que ya autorizó— y nunca desde una vista
 * suelta. Añadir aquí `auth` no sería más seguro y sí rompería los tres casos
 * para los que existe una URL firmada y caduca: el `<img src>` de un correo, el
 * PDF que un convertidor externo descarga, el enlace que se le da a alguien que
 * no tiene cuenta.
 *
 * Lo que la protege es lo de siempre: caduca (`files.url_ttl_minutes`), no se
 * puede modificar sin invalidarla —ni siquiera el `v`— y está limitada por IP
 * (`files.throttle`), que es lo que impide que una URL filtrada se convierta en
 * una manguera durante la media hora que le queda.
 *
 * ## Dos respuestas según dónde esté el fichero
 *
 * - **Disco local** (`local`, o el de staging mientras espera a subir): se sirve
 *   por stream desde aquí. `fpassthru` no carga el fichero en memoria.
 * - **Disco remoto** (S3, R2): se redirige a la URL temporal del propio bucket.
 *   Proxyear 40 MB a través de PHP para volver a mandarlos al navegador es
 *   pagar dos veces el ancho de banda y ocupar un worker todo el rato.
 *
 * La decisión se toma por el **driver del disco**, no por `sync_status`: el
 * estado describe el pipeline, y lo que hay que saber aquí es si el fichero está
 * en esta máquina.
 */
final class FileServeController
{
    public function __invoke(Media $file): StreamedResponse|RedirectResponse
    {
        $disk = Storage::disk($file->disk);
        $path = $file->getPathRelativeToRoot();

        abort_unless($disk->exists($path), 404);

        if (config("filesystems.disks.{$file->disk}.driver") !== 'local') {
            return redirect()->away($disk->temporaryUrl(
                $path,
                CarbonImmutable::now()->addMinutes((int) config('files.url_ttl_minutes', 30)),
            ));
        }

        return response()->stream(
            function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if (! is_resource($stream)) {
                    return;
                }

                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $file->mime_type ?? 'application/octet-stream',
                'Content-Length' => (string) $disk->size($path),
                /*
                 * `inline` para que una imagen o un PDF se vean en el navegador
                 * en vez de descargarse. `makeDisposition` escapa las comillas
                 * y añade `filename*` para los nombres no ASCII: el saneador
                 * del paquete quita los caracteres de control, pero no `"`, y
                 * un nombre con comillas rompería el entrecomillado a mano.
                 */
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_INLINE,
                    $file->file_name,
                    $this->asciiFallback($file->file_name),
                ),
                /*
                 * La URL ya lleva el `v` del fichero dentro de la firma, así que
                 * dos versiones distintas son dos URLs distintas y el navegador
                 * puede quedarse la respuesta todo lo que dure la firma.
                 * `private` porque el fichero no es público aunque la URL lo
                 * alcance sin sesión.
                 */
                'Cache-Control' => 'private, max-age='.(60 * (int) config('files.url_ttl_minutes', 30)),
            ],
        );
    }

    /**
     * El nombre en ASCII para el `filename=` clásico. Con un nombre ya ASCII
     * es el mismo y la cabecera sale con un solo parámetro; con acentos o
     * emoji, `makeDisposition` añade el `filename*` en UTF-8 y los navegadores
     * modernos usan ése. `%`, `/` y `\` no caben en el fallback por contrato.
     */
    private function asciiFallback(string $name): string
    {
        return (string) preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $name);
    }
}
