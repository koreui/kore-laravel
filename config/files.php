<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo Files — parámetros
    |--------------------------------------------------------------------------
    |
    | Cómo se comporta el almacenamiento de archivos cuando está encendido. Ver
    | `docs/modules/files.md`.
    |
    | Este archivo NO es `config/kore-app.php` y no duplica su toggle: quién
    | enciende el módulo sigue siendo `FILES_ENABLED`
    | (`kore-app.files.enabled`), que es lo que hace que
    | `FilesModuleServiceProvider` registre el binding de `FileStore`, la ruta
    | firmada, los listeners y el comando. Es el mismo reparto que
    | `kore-api.php` respecto de `API_ENABLED`, y que `devices.php` respecto de
    | `DEVICES_ENABLED`.
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético), así que aquí no aparece ningún `config('kore-app.…')`
    | — ni al revés: `config/media-library.php` lee `FILES_DISK` de `env()`
    | directamente, y no `config('files.disk')`.
    |
    */

    /*
     * Disco de los archivos privados: los que sólo se alcanzan por una URL
     * firmada. Es el destino por defecto de todo slot que no se declare
     * público.
     *
     * `local` apunta a `storage/app/private`, fuera del document root. Un
     * derivado con S3/R2 pone aquí el nombre de su disco y enciende
     * `FILES_SYNC` para que la subida no bloquee la petición.
     */
    'disk' => env('FILES_DISK', 'local'),

    /*
     * Disco de los archivos públicos (`FileSlotData::$public`), servibles por
     * URL directa sin firma. `public` apunta a `storage/app/public`, que
     * `php artisan storage:link` expone en `/storage`.
     */
    'public_disk' => env('FILES_PUBLIC_DISK', 'public'),

    /*
     * Disco de paso mientras el archivo espera a que un listener en cola lo
     * mueva al disco de destino. Sólo se usa con `sync.enabled` en true.
     *
     * Tiene que ser un disco de la propia máquina: la gracia de la escala es
     * que guardar sea E/S de disco y no de red, para que quien sube el archivo
     * no se quede esperando a S3.
     */
    'staging_disk' => env('FILES_STAGING_DISK', 'local'),

    /*
     * Minutos que vive una URL firmada de `FileStore::url()`.
     *
     * Media hora es el equilibrio de siempre: aguanta abrir un PDF, leerlo y
     * volver, y no sobrevive a que alguien pegue el enlace en un chat.
     */
    'url_ttl_minutes' => 30,

    /*
     * Peticiones por minuto que acepta la ruta `files.serve`, por IP.
     *
     * La firma ya es la autorización, así que esto no protege el contenido:
     * protege el ancho de banda de que alguien con una URL válida la use en
     * bucle durante la media hora que dura.
     */
    'throttle' => '60,1',

    /*
     * Tamaño máximo, en kilobytes, que las pantallas del boilerplate aceptan
     * como regla de validación por defecto. 50 MB.
     *
     * No sustituye a `post_max_size` / `upload_max_filesize` de PHP ni al
     * `client_max_body_size` de nginx: es la cifra que la aplicación puede
     * comprobar y explicar. Los otros tres cortan la petición antes de llegar.
     */
    'max_upload_kb' => 51200,

    'compression' => [

        /*
         * Comprimir cada archivo después de guardarlo, en cola.
         *
         * Apagado por defecto porque tiene requisitos fuera de PHP: los PDF
         * necesitan Ghostscript instalado y las imágenes un driver de imagen
         * (`gd` o `imagick`). Con esto en false todo archivo nace y se queda en
         * `pending`, que es la verdad: nadie ha intentado comprimirlo.
         */
        'enabled' => (bool) env('FILES_COMPRESSION', false),

        /*
         * Calidad de recompresión de imágenes (1–100). 85 es imperceptible en
         * una foto o un documento escaneado y suele quitar entre un 30 y un 50
         * por ciento del peso.
         */
        'image_quality' => 85,

        /*
         * Binario de Ghostscript para los PDF. En macOS `brew install
         * ghostscript`; en Debian/Ubuntu `apt-get install ghostscript`.
         *
         * Si no existe, la compresión de PDF se marca `skipped` y el archivo
         * original se queda tal cual: nunca se pierde el documento por no tener
         * la herramienta.
         */
        'ghostscript_binary' => 'gs',

        /*
         * Directorio de los temporales de la compresión. `null` usa el del
         * sistema. Se pone a mano cuando Ghostscript corre en otro contenedor y
         * hace falta un volumen compartido.
         */
        'tmp_dir' => null,
    ],

    'sync' => [

        /*
         * Mover el archivo del disco de staging al de destino en cola.
         *
         * Se enciende cuando `disk` es remoto. Con esto apagado —el caso por
         * defecto— el archivo se escribe directamente en su disco y nace ya
         * `synced` de facto: el listener no existe y no hay ventana entre
         * guardar y estar donde toca.
         */
        'enabled' => (bool) env('FILES_SYNC', false),
    ],

];
