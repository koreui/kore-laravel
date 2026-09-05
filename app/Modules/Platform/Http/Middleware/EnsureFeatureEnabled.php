<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Core\Contracts\InstallationFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra una ruta cuyo módulo no está incluido en esta instalación.
 *
 * ```php
 * Route::get('/reports', ...)->middleware('feature:reports');
 * ```
 *
 * Devuelve **403 y no 404**, aunque un 404 escondería mejor qué módulos
 * existen: el cliente que no tiene contratado un módulo tiene que poder
 * distinguir «esto no lo tienes» de «esta dirección no existe», porque lo
 * primero se resuelve llamando a comercial y lo segundo es un enlace roto. Es
 * la misma razón por la que el mensaje dice qué pasa en vez de callarse.
 *
 * El alias `feature` lo registra `PlatformModuleServiceProvider`. Y no
 * sustituye al permiso: `feature:` dice si el módulo está en esta instalación,
 * `permission:` si este usuario puede usarlo. Una ruta de un módulo opcional
 * lleva los dos. Ver `docs/modules/platform.md`.
 */
final readonly class EnsureFeatureEnabled
{
    public function __construct(private InstallationFeatures $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(
            $this->features->enabled($feature),
            403,
            __('Este módulo no está disponible en esta instalación.'),
        );

        return $next($request);
    }
}
