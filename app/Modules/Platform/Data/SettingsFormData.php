<?php

declare(strict_types=1);

namespace App\Modules\Platform\Data;

use App\Core\Data\Data;

/**
 * Entrada de `SettingUpdateAction`: los ajustes a guardar, ya validados.
 *
 * Un solo DTO con un mapa dentro y no un DTO por ajuste, porque la pantalla
 * guarda **todos** los campos de una vez y porque las claves editables las
 * declara `config('kore-settings.editable')`: un derivado que añada un ajuste
 * suyo no debería tener que tocar este archivo.
 *
 * El mapa es `{clave => valor}` con las claves con puntos tal cual
 * (`organization.name`), no anidadas. Lo que llega aquí ya pasó por
 * `SettingsForm::rules()`; el DTO no valida nada, y la Action vuelve a
 * comprobar que cada clave esté declarada como editable —esa segunda
 * comprobación no es redundante: la Action también sirve desde un comando o un
 * seeder, donde no hubo formulario.
 */
final class SettingsFormData extends Data
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public readonly array $values,
    ) {}
}
