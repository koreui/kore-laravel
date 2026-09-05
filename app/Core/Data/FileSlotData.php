<?php

declare(strict_types=1);

namespace App\Core\Data;

/**
 * El **slot** al que pertenece un archivo: el hueco que ocupa, no el archivo.
 *
 * Es la pieza que convierte «subir un fichero» en «poner la versión 3 de la
 * escritura del expediente 42». Un slot es un sitio con nombre dentro de un
 * modelo, y cada vez que alguien sube algo ahí nace una versión nueva y la
 * anterior deja de ser la vigente. Sin slot no hay versiones: hay ficheros
 * sueltos y un `delete()` que destruye el anterior.
 *
 * Tres datos, y sólo tres:
 *
 * - `collection` es la colección de media-library (`avatar`, `documentos`).
 * - `key` es lo que distingue dos slots **dentro** de la misma colección. Puede
 *   ir vacío —el avatar de un usuario es uno solo—, o llevar lo que haga falta:
 *   `['tipo' => 'escritura', 'anexo' => 2]`.
 * - `public` decide el disco: `files.public_disk` (servible por URL directa) o
 *   `files.disk` (privado, sólo por URL firmada). Por defecto, privado.
 *
 * **Lo que NO puede entrar en `key`**: la etiqueta que elige quien sube el
 * archivo. Es la cicatriz de Notarium: si el nombre visible contara como
 * identidad, reemplazar un documento cambiándole el nombre abriría un slot
 * nuevo en vez de crear la versión 2 del que ya había, y el historial se
 * partiría en dos sin que nadie lo notara.
 */
final class FileSlotData extends Data
{
    /**
     * @param array<string, scalar> $key identidad del slot dentro de la colección
     */
    public function __construct(
        public readonly string $collection,
        public readonly array $key = [],
        public readonly bool $public = false,
    ) {}

    /**
     * Huella estable del slot, para buscarlo en la base con un `where`.
     *
     * Notarium filtraba las versiones en PHP, propiedad a propiedad: traía toda
     * la colección del modelo y descartaba lo que no casaba. Funcionaba con
     * cinco documentos y no con quinientos. La huella permite un
     * `where('custom_properties->slot_fingerprint', …)` que el motor resuelve
     * con la tabla, no con memoria.
     *
     * `ksort()` antes de serializar es lo que la hace estable: `['a' => 1,
     * 'b' => 2]` y `['b' => 2, 'a' => 1]` son el mismo slot y tienen que dar la
     * misma huella. El hash va detrás para que el valor guardado sea de longitud
     * fija y sin comillas ni acentos, que es lo que hace que la comparación se
     * comporte igual en SQLite, MySQL y Postgres.
     *
     * **`xxh128` y no un hash criptográfico**: esto es un identificador, no un
     * secreto ni una firma. Nadie tiene que probar que conoce la `key` a partir
     * de la huella —la `key` se guarda al lado, en claro, para poder leer la
     * fila a ojo—; lo único que hace falta es que dos slots distintos no choquen
     * y que la misma entrada dé siempre lo mismo. 128 bits sobran para eso, y el
     * preset de seguridad de Pest tiene razón en no dejar pasar `sha1` sin
     * mirar.
     *
     * El prefijo con la colección no aporta unicidad —ya la da el hash— sino
     * legibilidad: al mirar la fila en la base se ve de qué slot es.
     */
    public function fingerprint(): string
    {
        $key = $this->key;
        ksort($key);

        return $this->collection.':'.hash('xxh128', (string) json_encode([
            'collection' => $this->collection,
            'key' => $key,
            'public' => $this->public,
        ]));
    }
}
