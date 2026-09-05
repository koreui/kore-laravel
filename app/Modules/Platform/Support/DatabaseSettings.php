<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Core\Contracts\Settings;
use App\Modules\Platform\Actions\SettingResetAction;
use App\Modules\Platform\Actions\SettingUpdateAction;
use App\Modules\Platform\Data\SettingsFormData;
use App\Modules\Platform\Models\Setting;
use Illuminate\Database\QueryException;

/**
 * Implementación de `App\Core\Contracts\Settings` sobre la tabla `settings`.
 *
 * Es el único sitio del boilerplate que traduce esas filas a valores; el resto
 * de módulos habla sólo con el contrato (R5). Se bindea como singleton en
 * `PlatformModuleServiceProvider::register()`, y siempre: Platform no tiene
 * toggle.
 *
 * ## La lectura no escribe
 *
 * `get()` sobre una clave sin fila devuelve el defecto de
 * `config/kore-settings.php` y **no** crea nada. Es la diferencia con
 * `NotariaConfiguracion::instancia()` de Notarium, que insertaba la fila la
 * primera vez que alguien leía: allí una petición GET podía acabar en un
 * INSERT, y dos peticiones simultáneas sobre una instalación nueva, en dos.
 *
 * ## Una base sin migrar no revienta la pantalla
 *
 * El layout pide la organización en cada petición, así que este objeto se
 * ejecuta antes que casi nada. En una instalación a medio migrar —el `migrate`
 * todavía corriendo, un derivado que estrena el módulo— la tabla puede no
 * existir; ahí devolver los defaults es más útil que un 500 en todas las
 * pantallas a la vez, incluida la que diría qué está pasando. Sólo se captura
 * `QueryException`, y sólo en la lectura: una escritura que falle tiene que
 * fallar.
 */
final class DatabaseSettings implements Settings
{
    /**
     * Memoria de la petición, por encima de la caché.
     *
     * La caché evita ir a la base; esto evita ir a la caché. En una pantalla que
     * pregunta por seis ajustes son seis lecturas del driver de caché —que con
     * `database` o `redis` son seis viajes— para responder siempre lo mismo.
     *
     * @var array<string, mixed>|null
     */
    private ?array $resolved = null;

    public function __construct(
        private readonly SettingsCache $cache,
        private readonly SettingUpdateAction $update,
        private readonly SettingResetAction $reset,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return array_key_exists($key, $this->defaults())
            ? $this->defaults()[$key]
            : $default;
    }

    public function set(string $key, mixed $value, int $changedBy): void
    {
        $this->update->handle(new SettingsFormData([$key => $value]), $changedBy);

        $this->resolved = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [...$this->defaults(), ...$this->stored()];
    }

    public function forget(string $key, int $changedBy): void
    {
        $this->reset->handle($key, $changedBy);

        $this->resolved = null;
    }

    /**
     * Los valores por defecto, leídos **enteros** y no clave a clave.
     *
     * `config('kore-settings.defaults.organization.name')` devuelve `null`, y
     * no es un despiste: el punto es el separador de niveles de `Arr::get`, así
     * que esa llamada busca `defaults → organization → name` mientras que la
     * clave del archivo es literalmente `'organization.name'`, plana. Las
     * claves de un ajuste llevan punto a propósito —agrupan por tema— así que
     * lo que se lee es el array completo y se indexa a mano.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('kore-settings.defaults', []);

        return $defaults;
    }

    /**
     * Lo que hay en la tabla, cacheado.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        try {
            $this->resolved = $this->cache->remember(
                static fn (): array => Setting::query()
                    ->get(['key', 'value'])
                    ->mapWithKeys(static fn (Setting $setting): array => [$setting->key => $setting->value])
                    ->all(),
            );
        } catch (QueryException) {
            // Base sin migrar: los defaults del archivo de configuración. Ver
            // el docblock de la clase. NO se memoiza, para que la petición
            // siguiente lo vuelva a intentar en cuanto la tabla exista.
            return [];
        }

        return $this->resolved;
    }
}
