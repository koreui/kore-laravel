<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Core\Actions\Action;
use App\Modules\Platform\Data\SettingsFormData;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\EditableSettings;
use App\Modules\Platform\Support\SettingsCache;
use Illuminate\Support\Facades\DB;

/**
 * Guarda ajustes de la instalación.
 *
 * Es la **única** escritura de alta y modificación de la tabla `settings`:
 * `DatabaseSettings::set()` la compone, y la pantalla la llama directamente. Sin
 * ese único punto, invalidar la caché sería una línea que hay que acordarse de
 * repetir en cada sitio que escriba.
 *
 * Tres cosas, en este orden y por una razón:
 *
 * 1. **Se comprueban todas las claves antes de escribir ninguna.** Si la mitad
 *    del formulario trae una clave que no está declarada como editable, no se
 *    guarda tampoco la otra mitad: media configuración aplicada es peor que
 *    ninguna, porque nadie sabe qué quedó puesto.
 * 2. **Se escriben dentro de una transacción**, por lo mismo.
 * 3. **Y sólo entonces se invalida la caché.** Al revés —invalidar primero— una
 *    lectura concurrente entre el `forget` y el `commit` volvería a cachear los
 *    valores viejos, y se quedarían ahí hasta que expirase el TTL.
 *
 * `$changedBy` llega por parámetro: la Action no mira la sesión (R19), porque
 * tiene que servir igual desde un comando artisan o un seeder.
 */
final class SettingUpdateAction extends Action
{
    public function __construct(
        private readonly EditableSettings $editable,
        private readonly SettingsCache $cache,
    ) {}

    public function handle(SettingsFormData $data, int $changedBy): void
    {
        foreach (array_keys($data->values) as $key) {
            // Lanza si la clave no está declarada en kore-settings.editable.
            $this->editable->get($key);
        }

        DB::transaction(function () use ($data, $changedBy): void {
            foreach ($data->values as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'changed_by' => $changedBy],
                );
            }
        });

        $this->cache->flush();

        /*
         * El registro de auditoría lleva las CLAVES y no los valores. Los
         * ajustes son configuración del cliente y algunos son datos de
         * contacto; el log de actividad se consulta con más manga ancha que la
         * pantalla que los edita, así que aquí queda quién tocó qué y cuándo,
         * y el valor se mira donde vive.
         */
        activity('settings')
            ->causedBy($changedBy)
            ->withProperties(['keys' => array_keys($data->values)])
            ->log('settings.updated');
    }
}
