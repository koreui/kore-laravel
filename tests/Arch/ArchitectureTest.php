<?php

declare(strict_types=1);

use App\Core\Actions\Action;
use App\Core\Data\Data;

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
 * `Model`, sólo `App\Enums` contenga enums, etc. En un modular monolith todo
 * eso vive en `App\Modules\{X}\...` (y el kernel compartido en `App\Core\...`),
 * así que el preset completo falla por diseño, no por bugs.
 *
 * Se aplica ignorando `App\Modules` y `App\Core\Enums`: sigue vigilando
 * `App\Models`, `App\Providers`, el resto de `App\Core` y `app/` en general,
 * y las reglas equivalentes para los módulos se escriben abajo a mano.
 */
arch()->preset()->laravel()->ignoring(['App\Modules', 'App\Core\Enums']);

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
 * Regla 1 · 1 Action = 1 caso de uso, `final`, sufijo `Action`, extendiendo la
 * base de Core.
 *
 * Ya no hay excepciones: desde la v1.1 los stubs de Fortify viven en
 * `App\Modules\Auth\Fortify` (son adaptadores del paquete, no casos de uso).
 */
arch('regla 1 · las Actions son final')
    ->expect('App\Modules\*\Actions')
    ->toBeFinal();

arch('regla 1 · las Actions llevan sufijo Action')
    ->expect('App\Modules\*\Actions')
    ->toHaveSuffix('Action');

arch('regla 1 · las Actions extienden App\Core\Actions\Action')
    ->expect('App\Modules\*\Actions')
    ->toExtend(Action::class);

arch('regla 6 · las Policies son final y llevan sufijo Policy')
    ->expect('App\Modules\*\Policies')
    ->toBeFinal()
    ->toHaveSuffix('Policy');

arch('los Providers de módulo llevan sufijo ServiceProvider')
    ->expect('App\Modules\*\Providers')
    ->toBeFinal()
    ->toHaveSuffix('ServiceProvider');

/*
 * Regla 3 · sin imports cruzados entre módulos.
 *
 * Users habla con Auth por `App\Core\Contracts\AuthorizationCatalog` y por el
 * enum `App\Core\Enums\SystemRole`; Auth no conoce Users en absoluto (si
 * algún día necesita reaccionar, escucha los eventos de `Users\Events`).
 *
 * Los tests SÍ pueden cruzar módulos: montan el mundo real (seeders, roles) y
 * no son código de producción.
 */
arch('regla 3 · sin imports cruzados entre módulos')
    ->expect('App\Modules\Users')
    ->not->toUse('App\Modules\Auth')
    ->ignoring('App\Modules\Users\Tests');

arch('regla 3 · sin imports cruzados entre módulos (inverso)')
    ->expect('App\Modules\Auth')
    ->not->toUse('App\Modules\Users')
    ->ignoring('App\Modules\Auth\Tests');

/*
 * Core es el kernel compartido: lo pueden usar todos los módulos, pero él no
 * puede depender de ninguno. En cuanto `App\Core` importe `App\Modules\X`, el
 * contrato deja de ser una frontera y pasa a ser decoración.
 */
arch('Core no depende de ningún módulo')
    ->expect('App\Core')
    ->not->toUse('App\Modules');

arch('los Contracts de Core son interfaces')
    ->expect('App\Core\Contracts')
    ->toBeInterfaces();

/*
 * Regla 4 · DTOs en lugar de arrays asociativos entre capas.
 */
arch('regla 4 · los DTOs de módulo son final y extienden App\Core\Data\Data')
    ->expect('App\Modules\*\Data')
    ->toBeFinal()
    ->toExtend(Data::class);

arch('regla 4 · los DTOs de Core son final y extienden App\Core\Data\Data')
    ->expect('App\Core\Data\Authorization')
    ->toBeFinal()
    ->toExtend(Data::class);
