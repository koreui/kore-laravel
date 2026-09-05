<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Una imagen del disco convertida a `data:` URI, para pintarla en una hoja que
 * va a acabar en PDF.
 *
 * **Por qué embebida y no enlazada.** Quien convierte la hoja es Gotenberg, en
 * su propio contenedor: cuando pide `http://127.0.0.1/img/logo.png` se lo pide
 * a sí mismo, no a la aplicación, y la imagen sale rota **en silencio** —el PDF
 * se genera igual y lo que Chromium dibuja en su lugar es el icono de imagen
 * rota—. En producción, con la dirección pública, sí funcionaría, y ésa es
 * justamente la trampa: el fallo aparece sólo en local, o sólo en producción.
 *
 * La segunda razón vale también en producción: los archivos que suben los
 * usuarios viven en disco privado y se sirven por URL firmada y temporal.
 * Embebida, la hoja no depende de que el convertidor alcance la aplicación ni
 * de que la firma siga viva cuando pase a buscarla.
 *
 * Y sale igual en las dos: lo que se revisa en la vista previa del navegador es
 * exactamente lo que se imprime.
 *
 * Vive en `Core` porque la hoja de un PDF la puede escribir cualquier módulo y
 * ninguno puede importar del otro (R5). El caso particular del logo de la
 * aplicación lo resuelve `App\Modules\Pdf\Support\PdfLogo`, que sí lee la
 * configuración del módulo.
 *
 * Ver `docs/modules/pdf.md`.
 */
final class PdfImage
{
    /*
     * Sin caché estática a propósito: guardarla haría que un cambio de
     * configuración —lo que hace cualquier prueba del encabezado— no tuviera
     * efecto hasta el siguiente proceso. Leer un archivo pequeño una vez por
     * hoja no es lo que hay que optimizar aquí.
     */

    /**
     * El `data:` URI de la imagen, o `null` si no hay archivo que leer.
     *
     * Nunca lanza: una hoja sin logo es mejor que una excepción o que una
     * imagen rota delante del cliente. Quien la llama decide si pinta el hueco.
     */
    public static function embedded(?string $path): ?string
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return 'data:'.self::mimeType($path).';base64,'.base64_encode($contents);
    }

    /**
     * El tipo MIME deducido de la extensión.
     *
     * De la extensión y no de `finfo`: `finfo` no distingue un SVG de
     * cualquier otro XML —lo daría por `text/xml` y el visor no lo pintaría— y
     * la extensión `ext-fileinfo` no está garantizada en toda instalación de
     * PHP.
     */
    private static function mimeType(string $path): string
    {
        return match (mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
