<?php

declare(strict_types=1);

namespace App\Modules\Mx\Support;

use App\Modules\Mx\Data\PostalCodeData;
use App\Modules\Mx\Models\PostalCode;
use Illuminate\Support\Facades\Cache;

/**
 * El catálogo de SEPOMEX, leído: código postal → colonias, municipio y entidad.
 *
 * ```php
 * $cp = (new PostalCodes)->lookup('01000');
 * $cp?->municipality;  // 'Álvaro Obregón'
 * $cp?->settlements;   // [['name' => 'San Ángel', 'type' => 'Colonia'], ...]
 * ```
 *
 * Es la segunda pieza pública del módulo. A diferencia de `MontoEnLetras` sí
 * toca la base y la caché, así que se resuelve del contenedor (el componente
 * Livewire y el controller la reciben por inyección).
 *
 * ## La caché guarda también los fallos
 *
 * Un formulario de dirección consulta el CP en cuanto el usuario escribe el
 * quinto dígito, y un bot que prueba códigos inventados haría una consulta por
 * intento. Por eso un CP que no existe se guarda igual —como `false`, que es lo
 * que distingue «no está en el catálogo» de «no está en la caché»— con el mismo
 * TTL: el catálogo cambia cuatro veces al año, así que un código postal que hoy
 * no existe no va a existir mañana.
 *
 * `Cache::remember()` no sirve para esto tal cual: trata `null` como fallo de
 * caché y volvería a consultar en cada llamada. De ahí el centinela.
 *
 * ## Fuera de un módulo
 *
 * Otro módulo **no** puede importar esta clase (R5). El camino para un derivado
 * que la necesite desde su propio módulo es declarar un contrato en
 * `App\Core\Contracts` y vincularlo aquí; está escrito con el ejemplo entero en
 * `docs/modules/mx.md`.
 */
final class PostalCodes
{
    /**
     * Las colonias de un código postal, o `null` si no está en el catálogo.
     *
     * Acepta el CP con espacios alrededor porque llega de un `<input>`, pero no
     * lo rellena con ceros: '1000' no es un código postal al que le falte un
     * cero, es un dato mal copiado, y adivinar convertiría un error del cliente
     * en una respuesta plausible.
     */
    public function lookup(string $postalCode): ?PostalCodeData
    {
        $normalized = trim($postalCode);

        if (preg_match('/^\d{5}$/', $normalized) !== 1) {
            return null;
        }

        $cached = Cache::store($this->store())->remember(
            $this->key($normalized),
            $this->ttl(),
            fn (): PostalCodeData|false => $this->query($normalized) ?? false,
        );

        return $cached === false ? null : $cached;
    }

    /**
     * La consulta de verdad, sin caché por delante.
     *
     * `with('state')` y no un join: son dos consultas por código postal —el
     * catálogo y su entidad— y a cambio el nombre de la entidad no depende de
     * cómo se llamen las columnas al aplanar el join.
     */
    private function query(string $postalCode): ?PostalCodeData
    {
        $rows = PostalCode::query()
            ->with('state')
            ->where('postal_code', $postalCode)
            ->orderBy('settlement')
            ->get();

        $first = $rows->first();

        if (! $first instanceof PostalCode) {
            return null;
        }

        return new PostalCodeData(
            postalCode: $postalCode,
            stateCode: $first->state_code,
            // El nombre sale de `mx_states`, que es donde está el oficial; el
            // CSV trae el suyo por fila y no siempre coincide en acentos.
            stateName: (string) $first->state?->name,
            municipality: $first->municipality,
            city: $first->city,
            settlements: array_values(array_map(
                static fn (PostalCode $row): array => [
                    'name' => $row->settlement,
                    'type' => $row->settlement_type,
                ],
                $rows->all(),
            )),
        );
    }

    private function key(string $postalCode): string
    {
        return config('mx.cache.prefix', 'mx:postal-code:').$postalCode;
    }

    private function ttl(): int
    {
        return max(0, (int) config('mx.cache.ttl', 86400));
    }

    private function store(): ?string
    {
        $store = config('mx.cache.store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
