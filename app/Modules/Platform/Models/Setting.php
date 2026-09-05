<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Un ajuste de la instalación: una clave y su valor.
 *
 * La tabla es el **primer** escalón de la cascada de
 * `App\Core\Contracts\Settings`; el segundo es
 * `config('kore-settings.defaults.…')`. Una clave sin fila no es un error: es
 * una clave que todavía vale lo que dice el archivo de configuración.
 *
 * El modelo es interno del módulo. Nadie fuera de `App\Modules\Platform` lo
 * toca: quien lee un ajuste habla con el contrato (R5), y lo que llega a una
 * Blade es un DTO (R30).
 *
 * `value` es una columna JSON porque un ajuste no siempre es texto —hay
 * booleanos y enteros—, y guardarlo todo como `varchar` obligaría a cada lector
 * a saber qué casteo le toca. Con JSON, el `true` que se guardó vuelve como
 * `true`.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $changed_by
 */
#[Fillable(['key', 'value', 'changed_by'])]
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
