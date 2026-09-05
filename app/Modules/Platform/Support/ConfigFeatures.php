<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Core\Contracts\InstallationFeatures;

/**
 * Implementación de `App\Core\Contracts\InstallationFeatures` sobre
 * `config/features.php`.
 *
 * El archivo de configuración y no una tabla, y es una decisión, no una
 * simplificación: lo que esta capa responde es qué **compró** el cliente, y eso
 * no lo cambia nadie desde dentro de la aplicación. Con los flags en la base,
 * cualquiera con acceso a la pantalla de ajustes —o a un `UPDATE`— se licencia
 * a sí mismo el módulo que quiera. En un archivo que sólo se toca desplegando,
 * el flag vale lo mismo que el `.env`, y además sobrevive a `config:cache`.
 *
 * Se bindea como singleton en `PlatformModuleServiceProvider::register()`.
 */
final class ConfigFeatures implements InstallationFeatures
{
    public function enabled(string $feature): bool
    {
        return (bool) config("features.{$feature}", false);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $flags */
        $flags = (array) config('features', []);

        return array_map(static fn (mixed $value): bool => (bool) $value, $flags);
    }
}
