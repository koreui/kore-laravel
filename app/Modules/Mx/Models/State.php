<?php

declare(strict_types=1);

namespace App\Modules\Mx\Models;

use App\Modules\Mx\Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una entidad federativa mexicana.
 *
 * Es un catálogo cerrado: son 32, las siembra `MxStatesSeeder` y no las escribe
 * nadie más. Por eso no lleva `timestamps` —la fecha de alta de «Jalisco» no
 * responde ninguna pregunta— ni identidad pública: no sale por la API como
 * recurso propio, sino incrustada en la respuesta de un código postal.
 *
 * @property int $id
 * @property string $code clave SAT/INEGI, '01'..'32'
 * @property string $name
 * @property string $abbreviation
 */
#[Fillable([
    'code',
    'name',
    'abbreviation',
])]
#[Table(name: 'mx_states')]
#[WithoutTimestamps]
final class State extends Model
{
    /** @use HasFactory<StateFactory> */
    use HasFactory;

    /**
     * Los asentamientos de la entidad.
     *
     * La FK es `state_code` contra `code`, no contra el id: la clave del SAT es
     * la identidad natural del catálogo y es la que viene en el CSV.
     *
     * @return HasMany<PostalCode, $this>
     */
    public function postalCodes(): HasMany
    {
        return $this->hasMany(PostalCode::class, 'state_code', 'code');
    }
}
