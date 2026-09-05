<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Core\Actions\Action;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\EditableSettings;
use App\Modules\Platform\Support\SettingsCache;

/**
 * Devuelve un ajuste a su valor por defecto borrando su fila.
 *
 * Borrar la fila **no** es lo mismo que guardar `null`, y la diferencia importa:
 * sin fila, la clave vuelve a valer lo que dice `config/kore-settings.php` y
 * `settings:show` la marca como `config`; con una fila a `null`, la clave vale
 * `null` de verdad y el defecto queda tapado para siempre.
 *
 * Es idempotente: resetear un ajuste que ya estaba en su defecto no falla ni
 * escribe nada, pero sí deja su línea de auditoría — «alguien pulsó restablecer»
 * es información aunque no cambiara nada.
 */
final class SettingResetAction extends Action
{
    public function __construct(
        private readonly EditableSettings $editable,
        private readonly SettingsCache $cache,
    ) {}

    public function handle(string $key, int $changedBy): void
    {
        // Lanza si la clave no está declarada en kore-settings.editable: sólo
        // se restablece lo que se puede cambiar.
        $this->editable->get($key);

        Setting::query()->where('key', '=', $key)->delete();

        $this->cache->flush();

        activity('settings')
            ->causedBy($changedBy)
            ->withProperties(['keys' => [$key]])
            ->log('settings.reset');
    }
}
