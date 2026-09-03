<?php

declare(strict_types=1);

namespace App\Modules\E2E\Providers;

use App\Modules\E2E\Support\HarnessGuard;
use Illuminate\Support\ServiceProvider;

/**
 * Module Service Provider del harness de pruebas E2E.
 *
 * Registra las rutas `/__e2e__/*` con las que la suite de Playwright siembra
 * usuarios, entra como un rol y lee el buzón de correo. Es un **backdoor
 * deliberado**: evita que un test que valida la última pantalla tenga que
 * recorrer las cinco anteriores para llegar a ella.
 *
 * Como todo módulo opt-in del boilerplate (R10), con el toggle apagado el
 * provider **no registra nada** — ni rutas, ni middleware, ni vistas, ni
 * traducciones—. Aquí ni siquiera hace falta la excepción del namespace de
 * vistas: el módulo no tiene ninguna, sólo responde JSON.
 *
 * Quien decide es {@see HarnessGuard::allows()}, que exige las tres
 * condiciones a la vez: el flag `E2E_HARNESS`, un entorno permitido y una base
 * de datos de pruebas. No hay `register()`: el controlador no necesita
 * binding, el contenedor lo resuelve solo, y así tampoco existe nada del
 * módulo cuando el harness está apagado.
 */
final class E2EModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! HarnessGuard::allows()) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    }
}
