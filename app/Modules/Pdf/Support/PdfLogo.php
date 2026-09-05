<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Support;

use App\Core\Support\PdfImage;

/**
 * Resuelve los logos que se imprimen en el encabezado de los PDF.
 *
 * Vive en el módulo y no en `Core` porque lee `config/kore-pdf.php`, que es
 * configuración del módulo Pdf. Lo genérico —convertir un archivo del disco en
 * un `data:` URI, y por qué— es {@see PdfImage}, que sí está en `Core`.
 *
 * El archivo se configura como ruta relativa a `public/`, así que cambiar el
 * logo es reemplazar el archivo, sin tocar código ni desplegar. Si no existe
 * devuelve `null` y el encabezado no pinta nada: más vale un PDF sin logo que
 * uno con una imagen rota delante del cliente.
 */
final class PdfLogo
{
    /**
     * El logo principal, embebido, o `null` si no hay ninguno configurado.
     */
    public static function embedded(): ?string
    {
        return self::fromConfig('kore-pdf.logo');
    }

    /**
     * El segundo logo (cliente, marca blanca, sello), embebido, o `null`.
     */
    public static function secondaryEmbedded(): ?string
    {
        return self::fromConfig('kore-pdf.secondary_logo');
    }

    /**
     * La ruta relativa que guarda una clave de config, ya embebida.
     *
     * `trim(..., '/')` para que `/img/logo.png` e `img/logo.png` sean lo mismo:
     * es el error de copia más fácil de cometer en un `.env` y no vale la pena
     * que cueste un PDF sin logo.
     */
    private static function fromConfig(string $key): ?string
    {
        $relative = trim((string) config($key, ''), '/');

        if ($relative === '') {
            return null;
        }

        return PdfImage::embedded(public_path($relative));
    }
}
