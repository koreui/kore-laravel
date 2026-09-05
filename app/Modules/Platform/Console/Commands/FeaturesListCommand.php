<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console\Commands;

use App\Core\Contracts\InstallationFeatures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Qué módulos incluye esta instalación.
 *
 * Es la respuesta rápida a «el cliente dice que no ve Informes»: antes de mirar
 * roles, permisos o Pennant, se mira si el módulo está en la licencia. Son
 * cuatro capas de «no» distintas y ésta es la única que se contesta sin abrir la
 * base (ver `docs/architecture/toggles.md` §«Tres capas»).
 */
#[Description('Lista los features de esta instalación (config/features.php) con su variable de entorno')]
#[Signature('features:list')]
final class FeaturesListCommand extends Command
{
    public function handle(InstallationFeatures $features): int
    {
        $all = $features->all();

        if ($all === []) {
            $this->components->warn('No hay ningún feature declarado en config/features.php.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($all as $feature => $enabled) {
            $rows[] = [
                $feature,
                $enabled ? 'sí' : 'no',
                'FEATURE_'.mb_strtoupper(str_replace(['.', '-'], '_', $feature)),
            ];
        }

        $this->table(['Feature', 'Incluido', 'Variable de entorno'], $rows);

        return self::SUCCESS;
    }
}
