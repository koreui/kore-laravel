<?php

declare(strict_types=1);

use App\Core\Actions\Action;
use App\Core\Data\Data;
use App\Core\Http\Api\Concerns\HandlesCursorPagination;
use App\Core\Http\Api\Controllers\ApiController;
use App\Core\Http\Api\Exceptions\ApiExceptionRenderer;
use App\Core\Http\Api\Requests\BaseApiRequest;
use App\Core\Http\Api\Resources\BaseApiResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Resources\Json\JsonResource;

/*
|--------------------------------------------------------------------------
| Arch tests
|--------------------------------------------------------------------------
|
| Las reglas de `docs/architecture/rules.md` dejan de ser prosa y pasan a
| fallar el build. Estos tests son estáticos: no bootean la aplicación ni tocan
| la base de datos (ver la nota en tests/Pest.php).
|
| Convención: los namespaces usan comodín (`App\Modules\*\Actions`) para que un
| módulo nuevo quede cubierto sin tocar este archivo, y cada bloque cita la
| regla que implementa (`// R7`).
|
| Reparto de responsabilidades entre las tres herramientas estáticas:
|
|   - Pest arch (este archivo): namespaces y declaraciones (`final`, sufijos,
|     `toExtend`, `toBeInterfaces`) + los checks de estructura de carpetas.
|   - PHPat (`tests/Arch/PhpatArchitecture.php`): grafo de dependencias real.
|   - disallowed-calls (`phpstan-disallowed.neon`): llamadas concretas.
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
 * Se aplica ignorando `App\Modules`, `App\Core\Enums` y `App\Core\Console`:
 * sigue vigilando `App\Models`, `App\Providers`, el resto de `App\Core` y
 * `app/` en general, y las reglas equivalentes para los módulos se escriben
 * abajo a mano.
 *
 * `App\Core\Console` queda fuera por lo mismo que `App\Core\Enums`: el preset
 * exige que sólo `App\Console\Commands` extienda `Command`, y en el layout
 * modular esa carpeta no existe — los comandos de dominio viven en su módulo
 * (`App\Modules\{X}\Console\Commands`) y los transversales en Core.
 *
 * `App\Core\Http\Api` es el mismo caso una vez más (v2.2.0): el contrato de la
 * API son un `ApiController` abstracto y un `BaseApiRequest` que extiende
 * `FormRequest`, y el preset exige que eso viva en `App\Http\Controllers` y
 * `App\Http\Requests`. Son las clases base que **todos** los módulos heredan,
 * así que su sitio es el kernel; que lo hereden es justo lo que verifica R54,
 * unas líneas más abajo.
 */
arch()->preset()->laravel()->ignoring([
    'App\Modules',
    'App\Core\Enums',
    'App\Core\Console',
    'App\Core\Http\Api',
]);

/*
|--------------------------------------------------------------------------
| Reglas del boilerplate
|--------------------------------------------------------------------------
*/

// R13
arch('R13 · declare(strict_types=1) en todo App')
    ->expect('App')
    ->toUseStrictTypes();

// R17 · R18
arch('R17/R18 · sin helpers de debug ni env() fuera de config')
    ->expect(['dd', 'dump', 'dump_die', 'var_dump', 'ray', 'env'])
    ->not->toBeUsedIn('App');

/*
 * R1 · 1 Action = 1 caso de uso, `final`, sufijo `Action`, extendiendo la base
 * de Core. Que exponga un único `handle()` público lo verifica PHPat, que sí
 * ve la firma de los métodos.
 *
 * Ya no hay excepciones: desde la v1.1 los stubs de Fortify viven en
 * `App\Modules\Auth\Fortify` (son adaptadores del paquete, no casos de uso).
 */
// R1 · R14
arch('R1 · las Actions son final')
    ->expect('App\Modules\*\Actions')
    ->toBeFinal();

// R2
arch('R2 · las Actions llevan sufijo Action')
    ->expect('App\Modules\*\Actions')
    ->toHaveSuffix('Action');

// R1
arch('R1 · las Actions extienden App\Core\Actions\Action')
    ->expect('App\Modules\*\Actions')
    ->toExtend(Action::class);

// R14 · R25
arch('R25 · las Policies son final y llevan sufijo Policy')
    ->expect('App\Modules\*\Policies')
    ->toBeFinal()
    ->toHaveSuffix('Policy');

// R9 · R14
arch('R9 · los Providers de módulo llevan sufijo ServiceProvider')
    ->expect('App\Modules\*\Providers')
    ->toBeFinal()
    ->toHaveSuffix('ServiceProvider');

/*
 * R5 · sin imports cruzados entre módulos, salvo los eventos.
 *
 * Users habla con Auth por `App\Core\Contracts\AuthorizationCatalog` y por el
 * enum `App\Core\Enums\SystemRole`; Auth no conoce Users en absoluto (si
 * algún día necesita reaccionar, escucha los eventos de `Users\Events`).
 *
 * Tres namespaces quedan fuera:
 *
 *   - los `Tests/` del módulo que mira (montan el mundo real y no son código
 *     de producción),
 *   - los `Tests/` del módulo mirado,
 *   - y los `Events/` del módulo mirado, que son su frontera pública: un
 *     listener tiene que poder tipar el evento que escucha.
 *
 * PHPat genera esta misma regla para TODO par de módulos a partir de
 * `app/Modules/*`, así que un módulo nuevo queda cubierto sin tocar nada; los
 * dos `arch()` de abajo se quedan como red de seguridad legible, y
 * `PhpatArchitectureTest` comprueba la forma de la regla generada.
 */
// R5
arch('R5 · sin imports cruzados entre módulos')
    ->expect('App\Modules\Users')
    ->not->toUse('App\Modules\Auth')
    ->ignoring(['App\Modules\Users\Tests', 'App\Modules\Auth\Events']);

// R5
arch('R5 · sin imports cruzados entre módulos (inverso)')
    ->expect('App\Modules\Auth')
    ->not->toUse('App\Modules\Users')
    ->ignoring(['App\Modules\Auth\Tests', 'App\Modules\Users\Events']);

/*
 * R6 · Core es el kernel compartido: lo pueden usar todos los módulos, pero él
 * no puede depender de ninguno. En cuanto `App\Core` importe `App\Modules\X`,
 * el contrato deja de ser una frontera y pasa a ser decoración.
 */
// R6
arch('R6 · Core no depende de ningún módulo')
    ->expect('App\Core')
    ->not->toUse('App\Modules');

// R7
arch('R7 · los Contracts de Core son interfaces')
    ->expect('App\Core\Contracts')
    ->toBeInterfaces();

/*
 * R8 · DTOs en lugar de arrays asociativos entre capas.
 */
// R8
arch('R8 · los DTOs de módulo son final y extienden App\Core\Data\Data')
    ->expect('App\Modules\*\Data')
    ->toBeFinal()
    ->toExtend(Data::class);

// R8
arch('R8 · los DTOs de Core son final y extienden App\Core\Data\Data')
    ->expect('App\Core\Data\Authorization')
    ->toBeFinal()
    ->toExtend(Data::class);

/*
 * R8 · un DTO no conoce la petición HTTP: si necesita un `Request` es que la
 * traducción de input a datos se quedó a medias en la capa de entrega.
 */
// R8
arch('R8 · los DTOs no dependen de Illuminate\Http')
    ->expect('App\Modules\*\Data')
    ->not->toUse('Illuminate\Http');

/**
 * FQCN de todos los DTOs del proyecto, deducidos de la ruta del archivo.
 *
 * @return list<class-string>
 */
function koreDataClasses(): array
{
    $classes = [];

    $files = array_merge(
        glob(__DIR__.'/../../app/Modules/*/Data/*.php') ?: [],
        glob(__DIR__.'/../../app/Core/Data/*.php') ?: [],
        glob(__DIR__.'/../../app/Core/Data/*/*.php') ?: [],
    );

    foreach ($files as $file) {
        $relative = substr((string) realpath($file), strlen((string) realpath(__DIR__.'/../../app')) + 1);
        /** @var class-string $class */
        $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/*
 * R8 · un DTO es un dato, no un objeto con estado: sus propiedades son
 * `readonly`.
 *
 * Esto no se puede expresar con `arch()`. `toBeReadonly()` mira
 * `ReflectionClass::isReadOnly()`, es decir la clase readonly de PHP 8.2, y
 * nuestros DTOs son `final class` con propiedades promovidas `public readonly`
 * —que es lo que spatie/laravel-data soporta—. Y `toHaveProperties...` no llega
 * al modificador. Así que aquí va reflexión en un test normal.
 *
 * Sólo se miran las propiedades **declaradas por la propia clase**: la `Data` de
 * spatie añade `_additional` y `_dataContext`, que son suyas y no son readonly.
 */
// R8
test('R8 · las propiedades de los DTOs son readonly', function (): void {
    expect(koreDataClasses())->not->toBeEmpty();

    $mutables = [];

    foreach (koreDataClasses() as $class) {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            $mutables[] = $class.'::$'.$property->getName();
        }
    }

    expect($mutables)->toBe([], sprintf(
        "Estas propiedades de DTO no son readonly:\n  · %s\n\n".
        'Un DTO viaja entre capas: si lo que lo recibe puede modificarlo, deja de ser un dato. Ver R8 en docs/architecture/rules.md.',
        implode("\n  · ", $mutables),
    ));
});

/*
 * R5 · los eventos son el canal público de un módulo hacia los demás: se
 * publican, se serializan a una cola y los escucha código que no controlas.
 * `final readonly` es lo que hace que ese contrato no cambie por el camino.
 */
// R5 · R14
arch('R5 · los Events de módulo son final y readonly')
    ->expect('App\Modules\*\Events')
    ->toBeFinal()
    ->toBeReadonly();

/*
 * R14 · una Rule es una pieza de validación, no una clase base: `final` y
 * atada al contrato del framework para que el validador la reconozca.
 */
// R14
arch('R14 · las Rules de módulo son final e implementan ValidationRule')
    ->expect('App\Modules\*\Rules')
    ->toBeFinal()
    ->toImplement(ValidationRule::class);

/*
|--------------------------------------------------------------------------
| R3 · Lista cerrada de carpetas por módulo
|--------------------------------------------------------------------------
|
| Un módulo no puede inventarse capas. La lista de abajo es la estructura
| documentada en docs/architecture/module-pattern.md; cualquier carpeta fuera
| de ella falla el build, y ampliarla es una decisión de arquitectura que se
| toma en el doc (y aquí) antes que en el código.
|
| Se comprueban también las subcarpetas de `Database/`, `Http/` y `Resources/`,
| que son las tres que tienen estructura fija. Dentro de `Models/`, `Support/`
| o `Actions/` cada módulo se organiza como quiera.
|
*/

test('R3 · un módulo sólo tiene las carpetas permitidas', function (): void {
    $allowed = [
        // Dominio
        'Actions', 'Data', 'Enums', 'Events', 'Listeners', 'Models', 'Policies', 'Rules', 'Support',
        // Entrega
        'Forms', 'Http', 'Routes', 'Resources',
        // Salida hacia fuera de la aplicación: Excel, CSV, PDF. Es una capa de
        // presentación, no de dominio, y por eso no cabe en Data/ ni en Support/.
        'Exports',
        // Infraestructura del módulo
        'Console', 'Database', 'Providers', 'Tests',
        // Adaptadores de paquete cuyo contrato (nombre y firma) lo fija un
        // tercero y por eso no puede vivir en Actions/. Ver app/Modules/Auth/Fortify.
        'Fortify',
    ];

    $nested = [
        'Database' => ['Migrations', 'Factories', 'Seeders'],
        'Http' => ['Controllers', 'Livewire', 'Requests', 'Middleware', 'Resources'],
        'Resources' => ['views', 'lang'],
    ];

    $offenders = [];
    $root = dirname(__DIR__, 2);

    foreach ((array) glob($root.'/app/Modules/*', GLOB_ONLYDIR) as $module) {
        $moduleName = basename((string) $module);

        foreach ((array) glob($module.'/*', GLOB_ONLYDIR) as $folder) {
            $name = basename((string) $folder);

            if (! in_array($name, $allowed, true)) {
                $offenders[] = "R3: carpeta {$moduleName}/{$name} no permitida; ver rules.md";

                continue;
            }

            if (! isset($nested[$name])) {
                continue;
            }

            foreach ((array) glob($folder.'/*', GLOB_ONLYDIR) as $child) {
                $childName = basename((string) $child);

                if (! in_array($childName, $nested[$name], true)) {
                    $offenders[] = "R3: carpeta {$moduleName}/{$name}/{$childName} no permitida; ver rules.md";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * FQCN de las clases que hay en una carpeta de módulo, deducidos de la ruta.
 *
 * Se miran dos niveles (`Enums/*.php` y `Enums/Loquesea/*.php`): dentro de una
 * carpeta permitida cada módulo se organiza como quiera.
 *
 * @param string $folder ruta relativa dentro del módulo, p. ej. `Http/Resources`
 * @return list<string>
 */
function koreModuleClassesIn(string $folder): array
{
    $classes = [];

    $files = array_merge(
        (array) glob(__DIR__.'/../../app/Modules/*/'.$folder.'/*.php'),
        (array) glob(__DIR__.'/../../app/Modules/*/'.$folder.'/*/*.php'),
    );

    foreach ($files as $file) {
        $relative = substr((string) realpath((string) $file), strlen((string) realpath(__DIR__.'/../../app')) + 1);
        $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));

        if (class_exists($class) || enum_exists($class) || interface_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/*
|--------------------------------------------------------------------------
| R3 · las carpetas nuevas de la lista, con su forma
|--------------------------------------------------------------------------
|
| `Enums/`, `Http/Resources/` y `Exports/` entraron en la lista de R3 en la
| v2.1.0 porque asper-server —hijo del boilerplate— las había creado igual, con
| la regla delante. Ampliar la lista sin decir qué puede vivir en ellas la
| convertiría en el `Services/` que R3 existe para evitar, así que las dos que
| tienen forma verificable la tienen verificada aquí. `Exports/` no: lo que hay
| dentro depende del paquete de exportación que instale el proyecto, y R3 sólo
| garantiza que la carpeta es una de las permitidas.
|
| Los dos tests son tolerantes a que hoy no exista ninguna clase: el
| boilerplate todavía no usa ninguna de las tres. Son la red que se tensa el
| día que un derivado —o este mismo repo— cree la primera.
|
*/

// R3 · R14
test('R3 · los Enums de módulo son enums backed', function (): void {
    $offenders = [];

    foreach (koreModuleClassesIn('Enums') as $class) {
        if (! enum_exists($class)) {
            $offenders[] = "{$class} está en Enums/ y no es un enum";

            continue;
        }

        if (! new ReflectionEnum($class)->getBackingType() instanceof ReflectionNamedType) {
            $offenders[] = "{$class} es un enum puro; usa uno backed (string o int)";
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        ...$offenders,
        'Un enum sin tipo de respaldo no se puede persistir ni serializar: en cuanto viaja a la base o a un JSON alguien acaba mapeándolo a mano. Ver R3 en docs/architecture/rules.md.',
    ]));
});

// R3
test('R3 · los Http/Resources de módulo extienden JsonResource', function (): void {
    $offenders = [];

    foreach (koreModuleClassesIn('Http/Resources') as $class) {
        if (! is_subclass_of($class, JsonResource::class)) {
            $offenders[] = "{$class} está en Http/Resources/ y no extiende ".JsonResource::class;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        ...$offenders,
        'Http/Resources/ es la carpeta de los API Resources de Laravel, no un cajón de sastre de la capa Http. Ver R3 en docs/architecture/rules.md.',
    ]));
});

/*
|--------------------------------------------------------------------------
| R54 · toda respuesta de la API pasa por el contrato de Core
|--------------------------------------------------------------------------
|
| Los controllers, resources y requests de la API de un módulo heredan del
| contrato de `App\Core\Http\Api`. Sin esto, cada módulo reinventa el envelope
| —que fue exactamente lo que pasó en Notarium y en asper— y el cliente acaba
| con dos formas de leer un error según el endpoint.
|
| Van como `test()` con glob y no como `arch()->expect(...)->toExtend(...)`
| porque hoy dos de los tres namespaces están vacíos: el boilerplate sólo
| publica un endpoint. `arch()` sobre un namespace sin clases falla por «no
| existe», que no es lo que la regla dice; el glob es tolerante y se tensa solo
| cuando un módulo crea su primera clase. Es la misma decisión que se tomó con
| `Enums/` y `Http/Resources/` en la v2.1.0.
|
| El barrido llega a `Api/V1/...`, tres niveles por debajo de la carpeta, que es
| donde viven de verdad (`Http/Controllers/Api/V1/UserController.php`).
|
*/

/**
 * FQCN de las clases bajo `{Módulo}/{$folder}/Api`, a cualquier profundidad.
 *
 * @return list<class-string>
 */
function koreModuleApiClassesIn(string $folder): array
{
    $classes = [];
    $root = dirname(__DIR__, 2).'/app';

    foreach ((array) glob($root.'/Modules/*/'.$folder.'/Api', GLOB_ONLYDIR) as $directory) {
        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator((string) $directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr((string) realpath($file->getPathname()), strlen((string) realpath($root)) + 1);
            /** @var class-string $class */
            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
    }

    sort($classes);

    return $classes;
}

// R54
test('R54 · los controllers de la API extienden ApiController', function (): void {
    $offenders = [];

    foreach (koreModuleApiClassesIn('Http/Controllers') as $class) {
        if (! is_subclass_of($class, ApiController::class)) {
            $offenders[] = "{$class} no extiende ".ApiController::class;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        ...$offenders,
        'Un controller de la API que no hereda el contrato construye su propio envelope, y el cliente acaba con dos formas de leer la misma API. Ver R54 en docs/architecture/rules.md.',
    ]));
});

// R54
test('R54 · los resources de la API extienden BaseApiResource', function (): void {
    $offenders = [];

    foreach (koreModuleApiClassesIn('Http/Resources') as $class) {
        if (! is_subclass_of($class, BaseApiResource::class)) {
            $offenders[] = "{$class} no extiende ".BaseApiResource::class;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        ...$offenders,
        'BaseApiResource es lo que fija `$wrap = data`: un resource fuera del contrato publica su representación sin sobre. Ver R54 en docs/architecture/rules.md.',
    ]));
});

// R54
test('R54 · los requests de la API extienden BaseApiRequest', function (): void {
    $offenders = [];

    foreach (koreModuleApiClassesIn('Http/Requests') as $class) {
        if (! is_subclass_of($class, BaseApiRequest::class)) {
            $offenders[] = "{$class} no extiende ".BaseApiRequest::class;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        ...$offenders,
        'Un FormRequest de la API que no hereda el contrato devuelve un redirect 302 en vez del 422 con details cuando falla la validación. Ver R54 en docs/architecture/rules.md.',
    ]));
});

// R54
test('R54 · el contrato de la API vive entero en App\Core\Http\Api', function (): void {
    expect(class_exists(ApiController::class))->toBeTrue()
        ->and(class_exists(BaseApiResource::class))->toBeTrue()
        ->and(class_exists(BaseApiRequest::class))->toBeTrue()
        ->and(class_exists(ApiExceptionRenderer::class))->toBeTrue()
        ->and(trait_exists(HandlesCursorPagination::class))->toBeTrue();
});
