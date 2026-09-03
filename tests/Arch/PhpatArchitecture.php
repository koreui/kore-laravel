<?php

declare(strict_types=1);

namespace Tests\Arch;

use Illuminate\Http\Request;
use PHPat\Selector\ClassNamespace;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Reglas de arquitectura verificadas por PHPat dentro de PHPStan.
 *
 * Por qué aquí y no en `tests/Arch/ArchitectureTest.php`: los arch tests de
 * Pest miran namespaces y declaraciones (`final`, sufijos, `toExtend`), pero no
 * ven el grafo de dependencias completo (parámetros, retornos, `new`, `catch`,
 * atributos, docblocks). PHPat sí, porque corre sobre el análisis de PHPStan.
 * Resultado: el mismo `composer analyse` que ya bloqueaba el build por tipos lo
 * bloquea también por dependencias prohibidas.
 *
 * Registro: `phpstan.neon` declara esta clase como servicio con el tag
 * `phpat.test` y añade el archivo a `paths`. Cada método público que empiece
 * por `test` aporta una regla (o un iterable de reglas).
 *
 * Cada método anota la regla de `docs/architecture/rules.md` que implementa.
 */
final class PhpatArchitecture
{
    /**
     * R6 · Core es el kernel compartido: todos pueden usarlo, él no puede
     * depender de nadie. En cuanto `App\Core` importe `App\Modules\X`, el
     * contrato deja de ser una frontera y pasa a ser decoración.
     */
    public function testCoreNoDependeDeNingunModulo(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Core'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App\Modules'))
            ->because('R6: Core no depende de ningún módulo (docs/architecture/rules.md)');
    }

    /**
     * R5 · Sin imports cruzados entre módulos, en todos los sentidos.
     *
     * Las reglas se generan a partir de las carpetas reales de `app/Modules`,
     * así que un módulo nuevo queda cubierto sin tocar este archivo. Se ignora
     * `Tests`: los tests montan el mundo real (seeders, roles de otro módulo)
     * y no son código de producción.
     *
     * @return iterable<string, Rule>
     */
    public function testModulosNoSeImportanEntreSi(): iterable
    {
        $modules = $this->modules();

        foreach ($modules as $subject) {
            $targets = array_values(array_filter(
                $modules,
                static fn (string $module): bool => $module !== $subject,
            ));

            if ($targets === []) {
                continue;
            }

            yield $subject => PHPat::rule()
                ->classes(Selector::inNamespace('App\Modules\\'.$subject))
                ->excluding(Selector::inNamespace('App\Modules\\'.$subject.'\Tests'))
                ->shouldNot()
                ->dependOn()
                ->classes(...array_map(
                    static fn (string $module): ClassNamespace => Selector::inNamespace('App\Modules\\'.$module),
                    $targets,
                ))
                ->excluding(...array_map(
                    static fn (string $module): ClassNamespace => Selector::inNamespace('App\Modules\\'.$module.'\Tests'),
                    $targets,
                ))
                ->because('R5: '.$subject.' habla con los demás módulos por App\Core\Contracts, eventos o DTOs de Core (docs/architecture/rules.md)');
        }
    }

    /**
     * R4 y R19 · El núcleo del dominio no conoce la capa de entrega.
     *
     * Una Action, un DTO, una Rule o un Model que dependan de Livewire o de
     * `Illuminate\Http\Request` sólo sirven dentro de una petición; el
     * boilerplate los quiere ejecutables desde un job, un comando o un seeder.
     */
    public function testElDominioNoDependeDeLaCapaDeEntrega(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('/^App\\\\Modules\\\\[^\\\\]+\\\\(Actions|Data|Rules|Models)(\\\\|$)/', true),
                Selector::inNamespace('App\Core\Actions'),
                Selector::inNamespace('App\Core\Data'),
            )
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Livewire'),
                Selector::classname(Request::class),
                Selector::inNamespace('/^App\\\\Modules\\\\[^\\\\]+\\\\(Http|Forms)(\\\\|$)/', true),
            )
            ->because('R4/R19: el dominio no depende de la capa de entrega (docs/architecture/rules.md)');
    }

    /**
     * R8 · Un DTO es una estructura de datos, no una fachada.
     *
     * `canOnly()->dependOn()` invierte la carga de la prueba: en vez de listar
     * lo prohibido, se lista lo permitido, así que cualquier dependencia nueva
     * (un modelo, un facade, un servicio) falla el build por defecto.
     */
    public function testLosDtosSoloDependenDeDatos(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('/^App\\\\Modules\\\\[^\\\\]+\\\\Data(\\\\|$)/', true),
                Selector::inNamespace('App\Core\Data'),
            )
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\Core\Data'),
                Selector::inNamespace('App\Core\Enums'),
                Selector::inNamespace('Spatie\LaravelData'),
                Selector::isEnum(),
            )
            ->because('R8: un DTO transporta datos ya validados; si necesita colaboradores, el caso de uso es una Action (docs/architecture/rules.md)');
    }

    /**
     * R7 · Los contratos son interfaces.
     *
     * Una clase concreta en `Core\Contracts` deja de ser una frontera: acopla
     * al implementador con la implementación de referencia.
     */
    public function testLosContractsDeCoreSonInterfaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Core\Contracts'))
            ->should()
            ->beInterface()
            ->because('R7: los contratos de Core son interfaces (docs/architecture/rules.md)');
    }

    /**
     * R1 · 1 Action = 1 caso de uso.
     *
     * Pest arch vigila el `final`, el sufijo y la clase base; esto vigila lo
     * que le falta: que la clase exponga exactamente un método público y que
     * se llame `handle`. Sin ello una Action se convierte en el `Service` con
     * quince métodos que el patrón vino a evitar.
     */
    public function testLasActionsExponenUnSoloHandle(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('/^App\\\\Modules\\\\[^\\\\]+\\\\Actions(\\\\|$)/', true))
            ->should()
            ->haveOnlyOnePublicMethodNamed('handle')
            ->because('R1: 1 Action = 1 caso de uso con un único método público handle() (docs/architecture/rules.md)');
    }

    /**
     * Nombres de los módulos presentes en `app/Modules`.
     *
     * @return array<int, string>
     */
    private function modules(): array
    {
        $directories = glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR);

        if ($directories === false) {
            return [];
        }

        return array_values(array_map(
            basename(...),
            $directories,
        ));
    }
}
