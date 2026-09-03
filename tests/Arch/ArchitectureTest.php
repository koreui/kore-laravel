<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Arch tests
|--------------------------------------------------------------------------
|
| Las "reglas de oro" de CLAUDE.md dejan de ser prosa y pasan a fallar el
| build. Estos tests son estáticos: no bootean la aplicación ni tocan la base
| de datos (ver la nota en tests/Pest.php).
|
| Convención: los namespaces usan comodín (`App\Modules\*\Actions`) para que un
| módulo nuevo quede cubierto sin tocar este archivo.
|
*/

/*
|--------------------------------------------------------------------------
| Presets
|--------------------------------------------------------------------------
*/

arch()->preset()->php();

arch()->preset()->security();

/*
 * El preset `laravel` asume el layout plano del framework: exige que sólo
 * `App\Http\Controllers` tenga clases con sufijo `Controller`, sólo
 * `App\Providers` con sufijo `ServiceProvider`, sólo `App\Models` extienda
 * `Model`, etc. En un modular monolith todo eso vive en `App\Modules\{X}\...`,
 * así que el preset completo falla por diseño, no por bugs.
 *
 * Se aplica ignorando `App\Modules`: sigue vigilando `App\Core`, `App\Models`,
 * `App\Providers` y `app/` en general, y las reglas equivalentes para los
 * módulos se escriben abajo a mano.
 */
arch()->preset()->laravel()->ignoring('App\Modules');

/*
|--------------------------------------------------------------------------
| Reglas del boilerplate
|--------------------------------------------------------------------------
*/

arch('regla 5 · declare(strict_types=1) en todo App')
    ->expect('App')
    ->toUseStrictTypes();

arch('sin helpers de debug ni env() fuera de config')
    ->expect(['dd', 'dump', 'dump_die', 'var_dump', 'ray', 'env'])
    ->not->toBeUsedIn('App');

/*
 * Regla 1 · 1 Action = 1 caso de uso, `final`, sufijo `Action`.
 *
 * `App\Modules\Auth\Actions\Fortify` queda fuera: son los stubs que publica
 * Fortify (`CreateNewUser`, `PasswordValidationRules`...), cuyos nombres los
 * fija el paquete y a los que no podemos poner sufijo. v1.1 los revisará al
 * refactorizar Users al Action Pattern de verdad.
 */
arch('regla 1 · las Actions son final')
    ->expect('App\Modules\*\Actions')
    ->toBeFinal()
    ->ignoring('App\Modules\Auth\Actions\Fortify');

arch('regla 1 · las Actions llevan sufijo Action')
    ->expect('App\Modules\*\Actions')
    ->toHaveSuffix('Action')
    ->ignoring('App\Modules\Auth\Actions\Fortify');

arch('regla 6 · las Policies son final y llevan sufijo Policy')
    ->expect('App\Modules\*\Policies')
    ->toBeFinal()
    ->toHaveSuffix('Policy');

arch('los Providers de módulo llevan sufijo ServiceProvider')
    ->expect('App\Modules\*\Providers')
    ->toBeFinal()
    ->toHaveSuffix('ServiceProvider');

/*
 * TODO v1.1 · Regla 3 · sin imports cruzados entre módulos.
 *
 * Hoy FALLA: Users importa App\Modules\Auth\Models\{Role, Module} en cinco
 * archivos. La corrección (mover Role/Module a Core o exponer un contrato en
 * App\Core\Contracts) está planificada para la v1.1 junto con el refactor de
 * Users al Action Pattern. Se deja escrita para que activarla sea borrar los
 * comentarios, no redescubrir la regla.
 *
 * arch('regla 3 · sin imports cruzados entre módulos')
 *     ->expect('App\Modules\Users')
 *     ->not->toUse('App\Modules\Auth');
 *
 * arch('regla 3 · sin imports cruzados entre módulos (inverso)')
 *     ->expect('App\Modules\Auth')
 *     ->not->toUse('App\Modules\Users');
 */
