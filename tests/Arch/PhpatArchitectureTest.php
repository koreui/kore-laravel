<?php

declare(strict_types=1);

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\AuthorizationCatalog;
use Illuminate\Support\Collection;
use PHPat\Selector\ClassNamespace;
use PHPat\Test\Builder\Rule;
use Tests\Arch\PhpatArchitecture;

/*
|--------------------------------------------------------------------------
| La forma de las reglas que genera PHPat
|--------------------------------------------------------------------------
|
| `PhpatArchitecture::testModulosNoSeImportanEntreSi()` no escribe una regla:
| escribe un generador que produce una por cada par de módulos. Quien lo
| ejecuta es PHPStan, así que un fallo en la generación —una exclusión que se
| pierde al añadir otra, un módulo que se queda fuera— no aparece como test
| rojo: aparece como una regla que deja de vigilar, que es la peor forma de
| romperse.
|
| Este test mira lo que PHPStan recibiría. La coincidencia de namespaces
| replica la de `PHPat\Selector\ClassNamespace::matches()` —prefijo sobre el
| namespace de la clase, con separador final para que `Auth` no capture
| `Authentication`— porque el original necesita una `ClassReflection` de
| PHPStan y aquí no hay análisis que la produzca.
|
| Regla verificada: R5 de docs/architecture/rules.md.
|
*/

/**
 * ¿Este selector de namespace captura esta clase?
 *
 * Mismo criterio que `ClassNamespace::matches()`: se compara el **namespace**
 * de la clase (no su FQCN) contra el del selector, con `\` final en los dos.
 */
function phpatNamespaceMatches(ClassNamespace $selector, string $class): bool
{
    $position = strrpos($class, '\\');
    $namespace = $position === false ? '' : substr($class, 0, $position);

    return str_starts_with(trim($namespace, '\\').'\\', trim($selector->getName(), '\\').'\\');
}

/**
 * ¿La regla marcaría como violación que el sujeto dependa de esta clase?
 *
 * Una dependencia es violación cuando algún selector de `targets` la captura y
 * **ninguno** de `targetExcludes` la rescata.
 */
function phpatRuleFlags(Rule $rule, string $class): bool
{
    $relation = $rule();

    $isTarget = array_any(
        $relation->getTargets(),
        fn (object $selector): bool => $selector instanceof ClassNamespace && phpatNamespaceMatches($selector, $class),
    );

    if (! $isTarget) {
        return false;
    }

    return ! array_any(
        $relation->getTargetExcludes(),
        fn (object $selector): bool => $selector instanceof ClassNamespace && phpatNamespaceMatches($selector, $class),
    );
}

/**
 * La regla generada para un módulo sujeto.
 */
function phpatRuleForModule(string $module): Rule
{
    /** @var iterable<string, Rule> $rules */
    $rules = new PhpatArchitecture()->testModulosNoSeImportanEntreSi();

    foreach ($rules as $subject => $rule) {
        if ($subject === $module) {
            return $rule;
        }
    }

    throw new RuntimeException("PHPat no generó regla para el módulo {$module}.");
}

/*
 * La cicatriz de asper-server: `NotificationsModuleServiceProvider` importa
 * once eventos de Payments, Personnel, Studies y Auth para cablear sus
 * listeners. Con la regla como estaba —sólo `Tests` excluido— PHPat marcaba en
 * rojo el patrón que el propio `module-pattern.md` recomienda.
 */
it('R5 · deja que un módulo escuche los eventos de otro', function (): void {
    expect(phpatRuleFlags(phpatRuleForModule('Users'), 'App\Modules\Auth\Events\UserRegistered'))->toBeFalse();
});

it('R5 · sigue prohibiendo el resto del módulo vecino', function (string $class): void {
    expect(phpatRuleFlags(phpatRuleForModule('Users'), $class))->toBeTrue();
})->with([
    'un modelo' => Role::class,
    'una Action' => 'App\Modules\Auth\Actions\SomethingAction',
    'un Support' => AuthorizationCatalog::class,
    'un listener' => 'App\Modules\Auth\Listeners\SomeListener',
    'el módulo a secas' => 'App\Modules\Auth\Whatever',
]);

it('R5 · sigue dejando pasar los Tests del módulo vecino', function (): void {
    expect(phpatRuleFlags(phpatRuleForModule('Users'), 'App\Modules\Auth\Tests\Feature\SomeTest'))->toBeFalse();
});

it('R5 · no se inventa dependencias fuera de los módulos', function (string $class): void {
    expect(phpatRuleFlags(phpatRuleForModule('Users'), $class))->toBeFalse();
})->with([
    'Core' => App\Core\Contracts\AuthorizationCatalog::class,
    'el propio módulo' => 'App\Modules\Users\Models\Something',
    'el framework' => Collection::class,
]);

/*
 * La exclusión se genera por par de módulos, así que tiene que existir para
 * todos y no sólo para el que se probó a mano.
 */
it('R5 · genera la excepción de Events para cada par de módulos', function (): void {
    $modules = array_map(basename(...), (array) glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR));

    expect($modules)->not->toBeEmpty();

    foreach ($modules as $subject) {
        $rule = phpatRuleForModule((string) $subject);

        foreach ($modules as $target) {
            if ($target === $subject) {
                continue;
            }

            expect(phpatRuleFlags($rule, "App\\Modules\\{$target}\\Events\\Algo"))
                ->toBeFalse("{$subject} no puede escuchar los eventos de {$target}");
        }
    }
});
