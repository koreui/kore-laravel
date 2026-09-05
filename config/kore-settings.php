<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ajustes de la instalación — módulo Platform
    |--------------------------------------------------------------------------
    |
    | Los valores que el cliente cambia desde `/settings` sin tocar el `.env` ni
    | pedir un deploy, y el defecto con el que arranca una instalación que
    | todavía no ha guardado ninguno. Los lee `App\Core\Contracts\Settings`
    | (implementado por `App\Modules\Platform\Support\DatabaseSettings`).
    |
    | Este archivo NO es `config/kore-app.php` y no declara ningún toggle:
    | Platform está siempre encendido. Ver `docs/modules/platform.md` y
    | `docs/architecture/toggles.md` §«Tres capas».
    |
    | Recordatorio de R12: un `config/*.php` no puede leer otro (se cargan en
    | orden alfabético), así que aquí no aparece ningún `config('…')`. `env()`
    | sí, que es lo único que se permite dentro de `config/` (R17).
    |
    */

    /*
     * Clave de caché del mapa completo de ajustes.
     *
     * Es una sola entrada con todas las filas dentro, y no una por ajuste, por
     * dos razones: el layout pinta la organización entera en cada petición (una
     * lectura, no siete), y así invalidar es olvidar una clave en vez de
     * recorrerlas. La invalida cada escritura (`SettingUpdateAction`,
     * `SettingResetAction`).
     */
    'cache_key' => 'kore.settings',

    /*
     * Segundos que vive la caché. Una hora: los ajustes cambian dos veces al
     * año, y el TTL sólo existe como red por si una escritura fuera de la
     * aplicación (una migración de datos, un `UPDATE` a mano) no invalidó nada.
     *
     * `0` desactiva la caché y va a la base en cada lectura. Es lo que quiere
     * un derivado que edite `settings` desde fuera de la aplicación.
     */
    'cache_ttl' => (int) env('SETTINGS_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Valores por defecto
    |--------------------------------------------------------------------------
    |
    | El segundo escalón de la cascada: fila en `settings` → esto → el `$default`
    | de quien llama. Una instalación recién clonada no tiene ninguna fila y aun
    | así responde a todas las claves.
    |
    | Leer un ajuste NUNCA escribe una fila. Es la diferencia con el
    | `NotariaConfiguracion::instancia()` de Notarium, donde el primer acceso
    | insertaba: allí una petición GET podía acabar en un INSERT.
    |
    */

    'defaults' => [
        'organization.name' => env('APP_NAME', 'Kore'),
        'organization.legal_name' => null,
        'organization.tax_id' => null,
        'organization.address' => null,
        'organization.phone' => null,
        'organization.email' => null,
        'organization.logo_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Claves editables desde la pantalla
    |--------------------------------------------------------------------------
    |
    | Lista CERRADA de lo que se puede cambiar desde dentro de la aplicación.
    | `App\Modules\Platform\Forms\SettingsForm` construye sus reglas de
    | validación a partir de `type` y pinta el control que le corresponde;
    | `SettingUpdateAction` rechaza cualquier clave que no esté aquí, para que un
    | `set()` con la clave mal escrita falle en vez de crear un ajuste fantasma
    | que nadie lee nunca.
    |
    | `type` elige control y reglas:
    |
    |   string · <x-kore::input>     · nullable|string|max:255
    |   text   · <x-kore::textarea>  · nullable|string|max:2000
    |   email  · <x-kore::input type=email> · nullable|email|max:255
    |   bool   · <x-kore::toggle>    · boolean
    |   int    · <x-kore::input type=number> · nullable|integer
    |
    | `required` (opcional) cambia el `nullable` por `required`.
    |
    | `label` va en español, que es el idioma fuente (R33); su traducción vive en
    | `app/Modules/Platform/Resources/lang/en.json`.
    |
    */

    'editable' => [
        'organization.name' => [
            'type' => 'string',
            'label' => 'Nombre de la organización',
            'required' => true,
        ],
        'organization.legal_name' => [
            'type' => 'string',
            'label' => 'Razón social',
        ],
        'organization.tax_id' => [
            'type' => 'string',
            'label' => 'RFC o identificación fiscal',
        ],
        'organization.address' => [
            'type' => 'text',
            'label' => 'Dirección',
        ],
        'organization.phone' => [
            'type' => 'string',
            'label' => 'Teléfono',
        ],
        'organization.email' => [
            'type' => 'email',
            'label' => 'Correo de contacto',
        ],
        'organization.logo_path' => [
            'type' => 'string',
            'label' => 'Ruta del logotipo',
        ],
    ],

];
