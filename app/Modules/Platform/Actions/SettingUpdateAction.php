<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Core\Actions\Action;
use App\Modules\Platform\Data\SettingsFormData;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\EditableSettings;
use App\Modules\Platform\Support\SettingsCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Guarda ajustes de la instalación.
 *
 * Es la **única** escritura de alta y modificación de la tabla `settings`:
 * `DatabaseSettings::set()` la compone, y la pantalla la llama directamente. Sin
 * ese único punto, invalidar la caché sería una línea que hay que acordarse de
 * repetir en cada sitio que escriba.
 *
 * Cuatro cosas, en este orden y por una razón:
 *
 * 1. **Se comprueban todas las claves antes de escribir ninguna.** Si la mitad
 *    del formulario trae una clave que no está declarada como editable, no se
 *    guarda tampoco la otra mitad: media configuración aplicada es peor que
 *    ninguna, porque nadie sabe qué quedó puesto.
 * 2. **Y también los VALORES**, contra las mismas reglas que deriva
 *    `SettingsForm` —las dos las saca de `EditableSettings`, que es el único
 *    sitio donde se decide qué admite cada `type`—. La pantalla ya validó, pero
 *    esta Action también sirve desde `Settings::set()`, desde un comando o
 *    desde un seeder, y ahí no hay formulario: sin esto,
 *    `Settings::set('organization.email', 'no-es-un-correo')` guardaría eso
 *    mismo y el error saldría meses después, en el pie de un PDF. Falla con
 *    `ValidationException`, que la pantalla ya sabe rendir.
 * 3. **Se escriben dentro de una transacción**, por lo mismo del punto 1.
 * 4. **Y sólo entonces se invalida la caché.** Al revés —invalidar primero— una
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
        $this->validate($data);

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

    /**
     * Claves declaradas y valores admisibles, o nada se guarda.
     *
     * El payload se arma por `slug` y no por clave porque el punto es el
     * separador de niveles del validador igual que el de Livewire: una regla
     * sobre `organization.name` se leería como «la clave `name` dentro del
     * array `organization`», que no es lo que hay. Es la misma traducción que
     * hace `SettingsForm`, y por eso el mensaje de error sale con el mismo
     * nombre de campo en las dos capas.
     *
     * @throws InvalidArgumentException si una clave no está declarada como editable
     * @throws ValidationException si un valor no pasa las reglas de su tipo
     */
    private function validate(SettingsFormData $data): void
    {
        $payload = [];
        $rules = [];
        $attributes = [];

        foreach ($data->values as $key => $value) {
            // Lanza si la clave no está declarada en kore-settings.editable.
            $definition = $this->editable->get($key);
            $slug = $definition['slug'];

            $payload[$slug] = $value;
            $rules[$slug] = $definition['rules'];
            $attributes[$slug] = mb_strtolower($definition['label']);
        }

        Validator::make($payload, $rules, [], $attributes)->validate();
    }
}
