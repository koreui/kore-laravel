<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Identidad pública estable en una columna `uuid`, con la llave primaria intacta.
 *
 * **Opt-in**: hoy ningún modelo del boilerplate lo usa. Para estrenarlo hacen
 * falta dos cosas —el trait y una columna—:
 *
 * ```php
 * // migración
 * $table->uuid('uuid')->nullable()->unique()->after('id');
 *
 * // modelo
 * final class Invoice extends Model
 * {
 *     use HasPublicUuid;
 *
 *     // Opcional: /invoices/{uuid} en vez de /invoices/{id}.
 *     public const bool ROUTE_BY_UUID = true;
 * }
 * ```
 *
 * **Por qué no `HasUuids` de Laravel.** El de serie convierte la **llave
 * primaria** en un uuid, y con eso cambia todo lo demás: cada foreign key pasa
 * de 8 bytes a 36, los índices crecen y las inserciones dejan de ser
 * secuenciales. Aquí el `id` sigue siendo un entero —barato para las relaciones
 * y para los joins— y el uuid es sólo la identidad **hacia afuera**: la que
 * viaja en una URL, en un webhook o en un export sin revelar cuántos registros
 * hay, y la que sobrevive a que los datos de varias instalaciones acaben en la
 * misma tabla de un panel central.
 *
 * El uuid se rellena en `creating`, así que un `create()`, un `firstOrCreate()`
 * o un seeder lo obtienen sin acordarse; si alguien lo trae puesto (una
 * importación que conserva el uuid de origen), se respeta.
 *
 * @phpstan-require-extends Model
 */
trait HasPublicUuid
{
    /** Columna donde vive la identidad pública. */
    public const string UUID_COLUMN = 'uuid';

    /**
     * Rellena el uuid al crear, si no viene puesto.
     */
    public static function bootHasPublicUuid(): void
    {
        static::creating(static function (Model $model): void {
            if (blank($model->getAttribute(self::UUID_COLUMN))) {
                $model->setAttribute(self::UUID_COLUMN, Str::uuid()->toString());
            }
        });
    }

    /**
     * El uuid como llave de ruta sólo si el modelo lo pide.
     *
     * Es una decisión por modelo y no del trait: un modelo puede querer la
     * identidad pública sin cambiar sus URLs (por ejemplo porque ya hay enlaces
     * publicados con el id).
     */
    public function getRouteKeyName(): string
    {
        return $this->resolvesRouteByUuid() ? self::UUID_COLUMN : $this->getKeyName();
    }

    /**
     * ¿El modelo declaró `public const bool ROUTE_BY_UUID = true;`?
     *
     * Se lee con `defined()` en vez de con una constante del propio trait
     * porque PHP no deja que la clase que usa un trait redefina una constante
     * suya con otro valor.
     */
    protected function resolvesRouteByUuid(): bool
    {
        $constant = static::class.'::ROUTE_BY_UUID';

        return defined($constant) && constant($constant) === true;
    }
}
