<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console\Commands;

use App\Core\Contracts\Settings;
use App\Modules\Platform\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Los ajustes de la instalación, con su valor efectivo y de dónde sale.
 *
 * La tercera columna es la razón de que este comando exista. «El nombre de la
 * organización es Kore» no dice nada; «es Kore **porque nadie lo ha cambiado**»
 * y «es Kore **porque alguien lo guardó así**» son dos situaciones distintas, y
 * la diferencia entre ellas es la primera pregunta de cualquier soporte que
 * empiece por «¿por qué sale esto en el PDF?».
 */
#[Description('Lista los ajustes de la instalación con su valor efectivo y si viene de la base o de la configuración')]
#[Signature('settings:show')]
final class SettingsShowCommand extends Command
{
    public function handle(Settings $settings): int
    {
        /*
         * En una instalación sin migrar la tabla no existe, y este comando es
         * justo el que alguien corre para entender qué pasa. Se degrada a «todo
         * viene de config», que es la verdad, en vez de reventar con un stack
         * trace del driver. Es la misma decisión que toma `DatabaseSettings` al
         * leer.
         */
        try {
            /** @var array<int, string> $stored */
            $stored = Setting::query()->pluck('key')->all();
        } catch (QueryException) {
            $stored = [];
            $this->components->warn('La tabla `settings` no responde: se listan los valores de configuración. ¿Falta migrar?');
        }

        $rows = [];

        foreach ($settings->all() as $key => $value) {
            $rows[] = [
                $key,
                $this->readable($value),
                in_array($key, $stored, true) ? 'base de datos' : 'config',
            ];
        }

        if ($rows === []) {
            $this->components->warn('No hay ningún ajuste declarado en config/kore-settings.php.');

            return self::SUCCESS;
        }

        $this->table(['Clave', 'Valor efectivo', 'Origen'], $rows);

        return self::SUCCESS;
    }

    /**
     * El valor tal y como se puede leer en una terminal.
     *
     * `null` se imprime como `—` y no como cadena vacía porque son dos cosas
     * distintas: la primera es «sin valor», la segunda es «guardado y en
     * blanco», y en una columna de anchura fija se ven igual.
     */
    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
