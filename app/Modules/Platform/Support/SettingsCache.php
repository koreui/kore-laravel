<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * La caché de los ajustes: una entrada con el mapa entero dentro.
 *
 * Una sola clave y no una por ajuste, por dos razones. El layout pinta la
 * organización completa en cada petición, así que lo natural es una lectura y
 * no siete. Y invalidar pasa a ser olvidar **una** clave en vez de recorrer una
 * lista que crece cada vez que un derivado añade un ajuste suyo — que es
 * exactamente la lista que alguien se olvida de actualizar.
 *
 * No usa tags: los drivers `file`, `database` y `array` no los soportan, y el
 * boilerplate tiene que funcionar igual con los cuatro.
 *
 * Vive aquí y no dentro de `DatabaseSettings` porque quien invalida no es quien
 * lee: las escrituras pasan por `SettingUpdateAction` y `SettingResetAction`, y
 * las Actions no pueden depender del implementador del contrato sin dar una
 * vuelta completa.
 */
final class SettingsCache
{
    /**
     * Recuerda el mapa de ajustes durante `kore-settings.cache_ttl` segundos.
     *
     * Con el TTL a `0` la caché se salta entera: es lo que quiere un derivado
     * que escriba en la tabla `settings` desde fuera de la aplicación (una
     * migración de datos, un panel central) y no pueda invalidar nada.
     *
     * @param Closure(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    public function remember(Closure $callback): array
    {
        $ttl = $this->ttl();

        if ($ttl <= 0) {
            return $callback();
        }

        /** @var array<string, mixed> $values */
        $values = Cache::remember($this->key(), $ttl, $callback);

        return $values;
    }

    /**
     * La tira. La llama toda escritura, antes de que nadie vuelva a leer.
     */
    public function flush(): void
    {
        Cache::forget($this->key());
    }

    public function key(): string
    {
        return (string) config('kore-settings.cache_key', 'kore.settings');
    }

    private function ttl(): int
    {
        return (int) config('kore-settings.cache_ttl', 3600);
    }
}
