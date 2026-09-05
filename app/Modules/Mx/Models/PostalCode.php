<?php

declare(strict_types=1);

namespace App\Modules\Mx\Models;

use App\Modules\Mx\Database\Factories\PostalCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un asentamiento (colonia) dentro de un código postal, tal y como lo publica
 * SEPOMEX.
 *
 * La fila **no** es «un código postal»: un CP tiene entre una y varias decenas
 * de colonias, todas con el mismo municipio y la misma entidad. Quien las
 * agrupa en una sola respuesta es `App\Modules\Mx\Support\PostalCodes`, y es la
 * única lectura del módulo.
 *
 * Sin `timestamps`: son datos de un tercero que se reemplazan enteros cada vez
 * que se importa el CSV, así que la fecha de alta de una fila no dice cuándo se
 * actualizó el catálogo —eso lo dice la corrida de `mx:sepomex:import`—.
 *
 * @property int $id
 * @property string $postal_code
 * @property string $settlement
 * @property string $settlement_type
 * @property string $municipality
 * @property string|null $city
 * @property string $state_code
 */
#[Fillable([
    'postal_code',
    'settlement',
    'settlement_type',
    'municipality',
    'city',
    'state_code',
])]
#[Table(name: 'mx_postal_codes')]
#[WithoutTimestamps]
final class PostalCode extends Model
{
    /** @use HasFactory<PostalCodeFactory> */
    use HasFactory;

    /**
     * La entidad federativa, por clave SAT y no por id (ver la migración).
     *
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_code', 'code');
    }
}
