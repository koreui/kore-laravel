<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Support\AgentsFile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\Finder\Finder;

/**
 * Checks textuales de arquitectura — la tercera pata del enforcement.
 *
 * Pest arch mira namespaces y declaraciones; PHPat mira el grafo de
 * dependencias; disallowed-calls mira llamadas concretas. Ninguna de las tres
 * ve lo que este comando ve: un atributo `#[Locked]` que falta, una migración
 * sin `down()`, un `authorize()` ausente, una válvula de escape caducada, un
 * `data-testid` en una blade, un toggle que no lee nadie o una pantalla que no
 * entró en el mapa de acceso de los E2E.
 *
 * Todo por lectura de archivos: no toca la base de datos ni bootea rutas, así
 * que corre en milisegundos y cabe en un pre-commit.
 *
 * Uso:
 *   php artisan kore:arch:check                       # todo el repositorio
 *   php artisan kore:arch:check --files=a.php,b.blade.php
 *   php artisan kore:arch:check --rule=R29
 *
 * Cada check cita la regla de docs/architecture/rules.md que implementa.
 */
#[Description('Checks textuales de las reglas de docs/architecture/rules.md')]
#[Signature('kore:arch:check
        {--files= : Lista de archivos separada por comas (la usa el hook de pre-commit). Por defecto se revisa todo el repositorio.}
        {--rule= : Corre sólo una regla, por ejemplo --rule=R29.}
        {--root= : Raíz del proyecto a revisar. Por defecto la de la aplicación; los tests la usan para apuntar a un árbol de fixtures.}')]
final class ArchCheckCommand extends Command
{
    /**
     * Archivos que hablan de las reglas y de las válvulas en vez de usarlas
     * (el propio linter y sus tests, cuyos fixtures son válvulas rotas y
     * reglas inexistentes a propósito): el linter no se lintea a sí mismo.
     */
    private const array SELF_REFERENTIAL = [
        'app/Core/Console/ArchCheckCommand.php',
        'tests/Feature/ArchCheckCommandTest.php',
    ];

    /**
     * R23 · prefijos de método que denotan una escritura y que, por tanto, tienen que
     * autorizar.
     *
     * La lista original sólo cubría el CRUD literal (`save`, `store`,
     * `update`, `delete`, `destroy`, `confirm`, `remove`, `toggle`) y dejaba
     * pasar el vocabulario con el que se nombra media aplicación real:
     * `createInvoice()`, `addMember()`, `sendCode()`, `syncRoles()`,
     * `assignOwner()`, `approveRequest()`, `importCsv()`. Todos escriben o
     * disparan un efecto, y todos viajan por `/livewire/update`.
     *
     * Es deliberadamente generosa: un falso positivo se cierra con una válvula
     * de una línea que deja escrito por qué; un falso negativo es la
     * vulnerabilidad que R23 existe para evitar.
     */
    private const string WRITE_VERBS = 'save|store|create|update|delete|destroy|remove|confirm|toggle|add|send|sync|assign|approve|import';

    /**
     * Cita de una regla: una `R` mayúscula seguida de 1-3 dígitos que no forma
     * parte de un token mayor.
     *
     * Antes se exigía que fuera seguida de `:` o `·`, lo que dejaba fuera dos
     * tercios de las citas reales del repositorio (`// R13`, `**R37** ·`,
     * `(R31)`, `R5 ·` al final de una frase…). Ahora se acepta cualquier
     * aparición suelta, con estos cortes para no inventarse citas:
     *
     *   - `(?<![A-Za-z0-9_$\/-])` — descarta `$R1`, `foo/R5`, `abc-R5` y
     *     cualquier `R{n}` pegada a una palabra o a una ruta.
     *   - `(?![A-Za-z0-9_\/-])`  — descarta `R2D2`, `R5x`, `R12/algo`.
     *   - `(?!\.\d)`             — descarta versiones tipo `R12.5`; un punto
     *     final de frase (`… ver R12.`) sí cuenta como cita.
     */
    private const string RULE_CITATION = '/(?<![A-Za-z0-9_$\/-])R\d{1,3}(?![A-Za-z0-9_\/-])(?!\.\d)/u';

    /**
     * R55 · llamadas que construyen a mano la URL de un archivo.
     *
     * Las tres primeras son del sistema de archivos (facade `Storage` y disco
     * de Flysystem) y las tres últimas de spatie/laravel-medialibrary. La
     * clave es el texto que se busca; el valor, el nombre que sale en el
     * mensaje.
     *
     * @var array<string, string>
     */
    private const array FILE_URL_CALLS = [
        'Storage::temporaryUrl(' => 'Storage::temporaryUrl()',
        'Storage::url(' => 'Storage::url()',
        '->getTemporaryUrl(' => 'getTemporaryUrl()',
        '->getFullUrl(' => 'getFullUrl()',
        '->temporaryUrl(' => 'temporaryUrl()',
        '->getUrl(' => 'getUrl()',
    ];

    /**
     * R57 · hojas que acaban en PDF: las del módulo Pdf y las que un módulo
     * guarda bajo un `pdf/` dentro de sus vistas.
     */
    private const string PDF_SHEET = '#^app/Modules/(?:Pdf/Resources/views/|[^/]+/Resources/views/(?:.*/)?pdf/)#';

    /**
     * R57 · formas de traer un recurso de fuera en vez de embeberlo.
     *
     * @var array<string, string>
     */
    private const array PDF_LINKED_ASSETS = [
        '@vite' => '@vite',
        '<link rel="stylesheet"' => '<link rel="stylesheet">',
        'src="http' => 'src="http…"',
        'src="//' => 'src="//…"',
        'asset(' => 'asset()',
    ];

    /** @var array<int, array{rule: string, file: string, line: int, message: string}> */
    private array $violations = [];

    /** @var array<int, string>|null Rutas absolutas del --files, o null si no se pasó. */
    private ?array $scope = null;

    /** @var array<string, list<string>>|null Caché de allowedValves(). */
    private ?array $allowedValves = null;

    private string $root = '';

    public function handle(): int
    {
        // El kernel de Artisan reutiliza la instancia del comando entre
        // llamadas dentro del mismo proceso (los tests hacen varias): sin este
        // reset, las violaciones de una corrida contaminarían la siguiente. La
        // caché del catálogo se tira por lo mismo: cada corrida puede traer
        // otro `--root`.
        $this->violations = [];
        $this->allowedValves = null;

        $root = $this->option('root');
        $this->root = is_string($root) && $root !== '' ? rtrim($root, '/') : base_path();
        $this->scope = $this->parseScope();

        $only = $this->option('rule');
        $only = is_string($only) && $only !== '' ? mb_strtoupper($only) : null;

        /** @var array<string, string> $checks */
        $checks = [
            'R11' => 'checkTogglesAreRead',
            'R23' => 'checkLivewireAuthorization',
            'R24' => 'checkLockedProperties',
            'R29' => 'checkMigrationsHaveDown',
            'R30' => 'checkBladeHasNoEloquent',
            'R37' => 'checkBladeHasNoTestIds',
            'R38' => 'checkE2eHasNoWaitForTimeout',
            'R40' => 'checkDocs',
            'R44' => 'checkEscapeValves',
            'R45' => 'checkBaselineExpiry',
            'R49' => 'checkSkillsAreLinked',
            'R50' => 'checkAgentsFileIsGenerated',
            'R52' => 'checkRoutesAreInAccessMap',
            'R55' => 'checkFileUrlsComeFromStore',
            'R57' => 'checkPdfSheetsAreSelfContained',
        ];

        if ($only !== null && ! isset($checks[$only])) {
            $this->components->error("No existe el check {$only}. Disponibles: ".implode(', ', array_keys($checks)));

            return self::FAILURE;
        }

        foreach ($checks as $rule => $method) {
            if ($only !== null && $only !== $rule) {
                continue;
            }

            $this->{$method}();
        }

        return $this->report();
    }

    /**
     * Rutas absolutas de `--files`, o null cuando no se pasó la opción.
     *
     * @return array<int, string>|null
     */
    private function parseScope(): ?array
    {
        $option = $this->option('files');

        if (! is_string($option) || trim($option) === '') {
            return null;
        }

        $files = array_filter(array_map(trim(...), explode(',', $option)));

        return array_values(array_map(
            fn (string $file): string => str_starts_with($file, '/') ? $file : $this->root.'/'.$file,
            $files,
        ));
    }

    /**
     * ¿Hay que revisar este archivo? Con `--files` sólo entran los listados.
     */
    private function inScope(string $absolutePath): bool
    {
        if ($this->scope === null) {
            return true;
        }

        return in_array($absolutePath, $this->scope, true);
    }

    private function relative(string $absolutePath): string
    {
        return str_starts_with($absolutePath, $this->root.'/')
            ? mb_substr($absolutePath, mb_strlen($this->root) + 1)
            : $absolutePath;
    }

    /**
     * Archivos que existen y entran en el scope, a partir de patrones glob
     * relativos a la raíz del proyecto.
     *
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    private function globFiles(array $patterns): array
    {
        $found = [];

        foreach ($patterns as $pattern) {
            foreach ((array) glob($this->root.'/'.$pattern) as $path) {
                $path = (string) $path;

                if (is_file($path) && $this->inScope($path)) {
                    $found[] = $path;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * Archivos bajo una lista de directorios, recursivo.
     *
     * @param array<int, string> $directories relativos a la raíz
     * @param array<int, string> $names patrones de nombre (`*.blade.php`)
     * @param bool $respectScope false para los checks que necesitan mirar
     *                           TODO el repositorio aunque venga `--files`
     *                           (si no, un pre-commit que sólo toca
     *                           `config/kore-app.php` no vería a los
     *                           lectores del toggle y lo daría por muerto).
     * @return array<int, string>
     */
    private function findFiles(array $directories, array $names, bool $respectScope = true): array
    {
        $existing = array_values(array_filter(
            array_map(fn (string $directory): string => $this->root.'/'.$directory, $directories),
            is_dir(...),
        ));

        if ($existing === []) {
            return [];
        }

        $finder = (new Finder)->files()->in($existing)->name($names)->sortByName();

        $found = [];

        foreach ($finder as $file) {
            $path = $file->getRealPath();

            if ($path !== false && (! $respectScope || $this->inScope($path))) {
                $found[] = $path;
            }
        }

        return $found;
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $path): array
    {
        return explode("\n", $this->contents($path));
    }

    private function violation(string $rule, string $path, int $line, string $message): void
    {
        $this->violations[] = [
            'rule' => $rule,
            'file' => $this->relative($path),
            'line' => $line,
            'message' => $message,
        ];
    }

    /**
     * ¿El archivo lleva una válvula **de la forma correcta** que exime a esta
     * regla?
     *
     * Se acepta a nivel de archivo (no de línea) a propósito: estos checks son
     * textuales y no siempre pueden señalar la línea exacta. Lo que sí se
     * comprueba es la forma: una `arch-exception` sobre una regla que en el
     * catálogo sólo admite `arch-accepted` no exime nada (y R44 la reporta).
     */
    private function hasValve(string $path, string $rule): bool
    {
        $matched = preg_match_all(
            '/arch-(exception|accepted):\s*'.preg_quote($rule, '/').'\b/',
            $this->contents($path),
            $matches,
        );

        if ($matched === false || $matched === 0) {
            return false;
        }

        return array_any($matches[1], fn ($kind): bool => $this->valveFormIsAllowed($rule, (string) $kind));
    }

    /**
     * ¿Admite esta regla esta forma de válvula, según su `> Escape:`?
     *
     * Una regla que no declara `> Escape:` en absoluto (catálogo parcial, o
     * adaptado por un proyecto derivado) se trata como permisiva: el checker no
     * inventa restricciones que el doc no dice.
     */
    private function valveFormIsAllowed(string $rule, string $kind): bool
    {
        $declared = $this->allowedValves();

        if (! array_key_exists($rule, $declared)) {
            return true;
        }

        return in_array($kind, $declared[$rule], true);
    }

    /**
     * Formas de válvula que admite cada regla, leídas del `> Escape:` del
     * catálogo.
     *
     * Sólo aparecen las reglas que **declaran** un `> Escape:`. El valor es la
     * lista de formas admitidas: `['accepted']`, `['exception']`, las dos, o
     * vacía cuando la regla dice «ninguna» / «no aplica».
     *
     * @return array<string, list<string>>
     */
    private function allowedValves(): array
    {
        if ($this->allowedValves !== null) {
            return $this->allowedValves;
        }

        $catalog = $this->root.'/docs/architecture/rules.md';

        if (! is_file($catalog)) {
            return $this->allowedValves = [];
        }

        $allowed = [];
        $current = null;

        foreach ($this->lines($catalog) as $line) {
            if (preg_match('/^###\s+(R\d{1,3})\s+·/', $line, $heading) === 1) {
                $current = $heading[1];

                continue;
            }

            if ($current === null || preg_match('/^>\s*Escape:/', $line) !== 1) {
                continue;
            }

            $forms = [];

            if (str_contains($line, 'arch-accepted')) {
                $forms[] = 'accepted';
            }

            if (str_contains($line, 'arch-exception')) {
                $forms[] = 'exception';
            }

            $allowed[$current] = $forms;
            $current = null;
        }

        return $this->allowedValves = $allowed;
    }

    /*
    |----------------------------------------------------------------------
    | R29 · toda migración define down()
    |----------------------------------------------------------------------
    */
    private function checkMigrationsHaveDown(): void
    {
        $migrations = $this->globFiles([
            'database/migrations/*.php',
            'app/Modules/*/Database/Migrations/*.php',
        ]);

        foreach ($migrations as $path) {
            if (str_contains($this->contents($path), 'function down(')) {
                continue;
            }

            if ($this->hasValve($path, 'R29')) {
                continue;
            }

            $this->violation('R29', $path, 1, 'la migración no define down(); una migración que no se puede revertir convierte cualquier rollback en un dump manual');
        }
    }

    /*
    |----------------------------------------------------------------------
    | R24 · #[Locked] en las propiedades públicas que identifican un modelo
    |----------------------------------------------------------------------
    */
    private function checkLockedProperties(): void
    {
        $files = $this->globFiles([
            'app/Modules/*/Forms/*.php',
            'app/Modules/*/Http/Livewire/*.php',
        ]);

        foreach ($files as $path) {
            $lines = $this->lines($path);

            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*public\s+(?:readonly\s+)?[^;=(]*\$(id|model|[a-z][A-Za-z0-9]*Id)\s*(?:=|;)/', $line) !== 1) {
                    continue;
                }

                if ($this->hasLockedAttributeAbove($lines, $index)) {
                    continue;
                }

                if ($this->hasValve($path, 'R24')) {
                    continue;
                }

                $this->violation(
                    'R24',
                    $path,
                    $index + 1,
                    'propiedad pública identificadora sin #[Locked]: el cliente puede reescribirla por /livewire/update y apuntar la operación a otro registro',
                );
            }
        }
    }

    /**
     * ¿Hay un `#[Locked]` en los atributos justo encima de esta propiedad?
     *
     * @param array<int, string> $lines
     */
    private function hasLockedAttributeAbove(array $lines, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $previous = trim($lines[$i]);

            if ($previous === '') {
                continue;
            }

            if (str_contains($previous, '#[Locked]')) {
                return true;
            }

            // Otros atributos y comentarios no cortan la búsqueda; cualquier
            // otra cosa (una llave, otra propiedad) sí.
            if (str_starts_with($previous, '#[') || str_starts_with($previous, '*') || str_starts_with($previous, '/*') || str_starts_with($previous, '//')) {
                continue;
            }

            return false;
        }

        return false;
    }

    /*
    |----------------------------------------------------------------------
    | R23 · la autorización vive dentro del componente Livewire
    |----------------------------------------------------------------------
    */
    private function checkLivewireAuthorization(): void
    {
        $files = $this->globFiles(['app/Modules/*/Http/Livewire/*.php']);

        foreach ($files as $path) {
            $contents = $this->contents($path);

            $matched = preg_match_all(
                '/public function ((?:'.self::WRITE_VERBS.')[A-Za-z0-9_]*)\s*\(/',
                $contents,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            if ($matched === false || $matched === 0) {
                continue;
            }

            foreach ($matches[1] as $match) {
                $name = (string) $match[0];
                $offset = (int) $match[1];
                $body = $this->methodBody($contents, $offset);

                if (preg_match('/authorize\(|->can\(|Gate::|denyAs|denyWithStatus/', $body) === 1) {
                    continue;
                }

                if ($this->hasValve($path, 'R23')) {
                    continue;
                }

                $this->violation(
                    'R23',
                    $path,
                    $this->lineAt($contents, $offset),
                    "{$name}() escribe sin autorizar: la llamada viaja por /livewire/update, donde el middleware permission: de la ruta no corre",
                );
            }
        }
    }

    /**
     * Cuerpo de un método a partir del offset de su nombre, por conteo de
     * llaves. Aproximación textual: una llave dentro de un string cuenta. Basta
     * para decidir si dentro hay una llamada de autorización.
     */
    private function methodBody(string $contents, int $offset): string
    {
        $open = strpos($contents, '{', $offset);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($contents);

        for ($i = $open; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($contents, $open, $i - $open + 1);
                }
            }
        }

        return substr($contents, $open);
    }

    private function lineAt(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    /*
    |----------------------------------------------------------------------
    | R44 · gramática y caducidad de las válvulas de escape
    |----------------------------------------------------------------------
    */
    private function checkEscapeValves(): void
    {
        $known = $this->knownRules();
        $today = CarbonImmutable::today();

        $files = $this->findFiles(
            ['app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'tests'],
            ['*.php', '*.ts', '*.js', '*.blade.php'],
        );

        foreach ($files as $path) {
            if (in_array($this->relative($path), self::SELF_REFERENTIAL, true)) {
                continue;
            }

            foreach ($this->lines($path) as $index => $line) {
                if (preg_match('#(?://|\#|\*|<!--)\s*arch-(exception|accepted):#', $line, $kind) !== 1) {
                    continue;
                }

                $number = $index + 1;

                if ($kind[1] === 'accepted') {
                    $this->validateAccepted($path, $number, $line, $known);

                    continue;
                }

                $this->validateException($path, $number, $line, $known, $today);
            }
        }
    }

    /**
     * @param array<int, string> $known
     */
    private function validateAccepted(string $path, int $number, string $line, array $known): void
    {
        $pattern = '/arch-accepted:\s*(R\d{1,3})\s*·\s*(?:[^·]+?)\s*·\s*(@[^\s·]+)\s*$/u';

        if (preg_match($pattern, rtrim($line), $matches) !== 1) {
            $this->violation('R44', $path, $number, 'válvula mal formada; la gramática es «arch-accepted: R20 · razón breve · @owner»');

            return;
        }

        $this->assertRuleExists($path, $number, $matches[1], $known);
        $this->assertValveFormAllowed($path, $number, $matches[1], 'accepted');
    }

    /**
     * @param array<int, string> $known
     */
    private function validateException(string $path, int $number, string $line, array $known, CarbonImmutable $today): void
    {
        $pattern = '/arch-exception:\s*(R\d{1,3})\s*·\s*(?:[^·]+?)\s*·\s*(@[^\s·]+)\s*·\s*(\d{4}-\d{2}-\d{2})\s*$/u';

        if (preg_match($pattern, rtrim($line), $matches) !== 1) {
            $this->violation('R44', $path, $number, 'válvula mal formada; la gramática es «arch-exception: R12 · razón breve · @owner · 2026-12-31»');

            return;
        }

        $this->assertRuleExists($path, $number, $matches[1], $known);
        $this->assertValveFormAllowed($path, $number, $matches[1], 'exception');

        $expiry = CarbonImmutable::createFromFormat('Y-m-d', $matches[3]);

        if (! $expiry instanceof CarbonImmutable) {
            $this->violation('R44', $path, $number, "fecha inválida en la válvula: {$matches[3]}");

            return;
        }

        if ($expiry->startOfDay()->lessThan($today)) {
            $this->violation('R44', $path, $number, "la válvula venció el {$matches[3]}: renuévala con el dueño o arregla el código");
        }
    }

    /**
     * @param array<int, string> $known
     */
    private function assertRuleExists(string $path, int $number, string $rule, array $known): void
    {
        if (! in_array($rule, $known, true)) {
            $this->violation('R44', $path, $number, "la válvula cita {$rule}, que no existe en docs/architecture/rules.md");
        }
    }

    /**
     * La forma de la válvula tiene que ser una de las que la regla declara en
     * su `> Escape:`.
     *
     * Las dos formas no son intercambiables: `arch-exception` es deuda con
     * fecha y `arch-accepted` es una decisión permanente. Poner la que no toca
     * convierte una deuda en decisión (y se queda para siempre) o una decisión
     * en deuda (y caduca sin que nadie tenga nada que arreglar).
     */
    private function assertValveFormAllowed(string $path, int $number, string $rule, string $kind): void
    {
        $declared = $this->allowedValves();

        if (! array_key_exists($rule, $declared) || in_array($kind, $declared[$rule], true)) {
            return;
        }

        $used = "arch-{$kind}";

        if ($declared[$rule] === []) {
            $this->violation('R44', $path, $number, "{$rule} no admite válvula («> Escape: ninguna» en docs/architecture/rules.md), y aquí lleva una {$used}");

            return;
        }

        $admitted = implode(' o ', array_map(static fn (string $form): string => "arch-{$form}", $declared[$rule]));

        $this->violation('R44', $path, $number, "{$rule} sólo admite {$admitted} según docs/architecture/rules.md, y aquí se usó {$used}");
    }

    /*
    |----------------------------------------------------------------------
    | R30 · sin Eloquent en Blade · R37 · sin data-testid
    |----------------------------------------------------------------------
    */
    private function checkBladeHasNoEloquent(): void
    {
        foreach ($this->bladeFiles() as $path) {
            foreach ($this->lines($path) as $index => $line) {
                if (preg_match('/::(query|where|count|all|find)\(|\\\\App\\\\Models/', $line) !== 1) {
                    continue;
                }

                if ($this->hasValve($path, 'R30')) {
                    continue;
                }

                $this->violation('R30', $path, $index + 1, 'Eloquent dentro de una Blade: la consulta se esconde de los tests y del profiler; pasa DTOs desde el componente');
            }
        }
    }

    private function checkBladeHasNoTestIds(): void
    {
        foreach ($this->bladeFiles() as $path) {
            foreach ($this->lines($path) as $index => $line) {
                if (! str_contains($line, 'data-testid')) {
                    continue;
                }

                if ($this->hasValve($path, 'R37')) {
                    continue;
                }

                $this->violation('R37', $path, $index + 1, 'data-testid en una Blade: si el elemento no se puede localizar por rol o por etiqueta, el problema es de accesibilidad, no del test');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function bladeFiles(): array
    {
        return $this->findFiles(['resources/views', 'app/Modules'], ['*.blade.php']);
    }

    /*
    |----------------------------------------------------------------------
    | R38 · sin waitForTimeout en los E2E
    |----------------------------------------------------------------------
    */
    private function checkE2eHasNoWaitForTimeout(): void
    {
        foreach ($this->findFiles(['tests/e2e'], ['*.ts']) as $path) {
            foreach ($this->lines($path) as $index => $line) {
                if (! str_contains($line, 'waitForTimeout(')) {
                    continue;
                }

                // Los comentarios que EXPLICAN por qué no se usa no son usos.
                if (preg_match('#^\s*(//|\*|/\*)#', $line) === 1) {
                    continue;
                }

                if ($this->hasValve($path, 'R38')) {
                    continue;
                }

                $this->violation('R38', $path, $index + 1, 'waitForTimeout(): espera a un cambio observable (toast, fila, URL, toHaveCount), no al reloj');
            }
        }
    }

    /*
    |----------------------------------------------------------------------
    | R11 · un toggle sólo existe si alguien lo lee
    |----------------------------------------------------------------------
    */
    private function checkTogglesAreRead(): void
    {
        $config = $this->root.'/config/kore-app.php';

        if (! is_file($config) || ! $this->inScope($config)) {
            return;
        }

        // Se lee el archivo, no `config()`: así el check depende de la raíz que
        // se le pase (`--root`) y no del estado de la aplicación que lo corre.
        /** @var array<string, mixed> $toggles */
        $toggles = (array) require $config;

        $readers = $this->findFiles(
            ['app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'tests'],
            ['*.php', '*.blade.php'],
            respectScope: false,
        );

        $haystack = '';

        foreach ($readers as $path) {
            if ($path === $config) {
                continue;
            }

            $haystack .= $this->contents($path);
        }

        foreach (array_keys(Arr::dot($toggles)) as $key) {
            if (str_contains($haystack, 'kore-app.'.$key)) {
                continue;
            }

            if ($this->hasValve($config, 'R11')) {
                continue;
            }

            $this->violation('R11', $config, 1, "el toggle kore-app.{$key} no lo lee nadie: un toggle fantasma miente sobre lo que hace el boilerplate");
        }
    }

    /*
    |----------------------------------------------------------------------
    | R40 · el índice de docs está completo y las citas apuntan a algo
    |----------------------------------------------------------------------
    */
    private function checkDocs(): void
    {
        $this->checkDocsIndex();
        $this->checkCitedRulesExist();
    }

    private function checkDocsIndex(): void
    {
        $index = $this->root.'/docs/README.md';

        if (! is_file($index)) {
            return;
        }

        $indexContents = $this->contents($index);

        foreach ($this->findFiles(['docs'], ['*.md']) as $path) {
            $relative = $this->relative($path);

            if ($relative === 'docs/README.md') {
                continue;
            }

            $inDocs = mb_substr($relative, mb_strlen('docs/'));

            if (str_contains($indexContents, $inDocs)) {
                continue;
            }

            $this->violation('R40', $path, 1, "el doc no está enlazado desde docs/README.md: un doc que no aparece en el índice no lo lee nadie (esperado «{$inDocs}»)");
        }
    }

    /**
     * Toda `R{n}` citada desde el código o desde CLAUDE/AGENTS existe en
     * rules.md. Se deja fuera `docs/`, porque `docs/audit/` cita las reglas de
     * OTRO proyecto y ésas no tienen por qué existir aquí.
     *
     * De los skills se barre sólo `.agents/skills`: desde la v1.4.0
     * `.claude/skills/{nombre}` es un symlink a esa carpeta (R49), y barrer las
     * dos sería leer cada archivo dos veces y reportar cada cita dos veces.
     */
    private function checkCitedRulesExist(): void
    {
        $known = $this->knownRules();

        $files = array_merge(
            $this->findFiles(['app', 'tests', '.agents/skills'], ['*.php', '*.md', '*.ts']),
            $this->globFiles(['*.neon', 'CLAUDE.md', 'AGENTS.md']),
        );

        foreach ($files as $path) {
            if (in_array($this->relative($path), self::SELF_REFERENTIAL, true)) {
                continue;
            }

            foreach ($this->lines($path) as $index => $line) {
                if (preg_match_all(self::RULE_CITATION, $line, $matches) < 1) {
                    continue;
                }

                foreach ($matches[0] as $rule) {
                    if (in_array($rule, $known, true)) {
                        continue;
                    }

                    $this->violation('R40', $path, $index + 1, "se cita {$rule}, que no existe en docs/architecture/rules.md");
                }
            }
        }
    }

    /**
     * Reglas declaradas en el catálogo (`### R7 · ...`).
     *
     * @return array<int, string>
     */
    private function knownRules(): array
    {
        $catalog = $this->root.'/docs/architecture/rules.md';

        if (! is_file($catalog)) {
            return [];
        }

        preg_match_all('/^###\s+(R\d{1,3})\s+·/m', $this->contents($catalog), $matches);

        return array_values(array_unique($matches[1]));
    }

    /*
    |----------------------------------------------------------------------
    | R45 · un baseline caduca
    |----------------------------------------------------------------------
    */
    private function checkBaselineExpiry(): void
    {
        $baseline = $this->root.'/phpstan-baseline.neon';

        if (! is_file($baseline) || ! $this->inScope($baseline)) {
            return;
        }

        $first = trim($this->lines($baseline)[0] ?? '');

        if (preg_match('/^#\s*arch-baseline:\s*vence\s*(\d{4}-\d{2}-\d{2})$/', $first, $matches) !== 1) {
            $this->violation('R45', $baseline, 1, 'la primera línea del baseline debe ser «# arch-baseline: vence YYYY-MM-DD»: un baseline sin fecha es deuda perpetua');

            return;
        }

        $expiry = CarbonImmutable::createFromFormat('Y-m-d', $matches[1]);

        if (! $expiry instanceof CarbonImmutable || $expiry->startOfDay()->lessThan(CarbonImmutable::today())) {
            $this->violation('R45', $baseline, 1, "el baseline venció el {$matches[1]}: vacíalo o renueva la fecha con el equipo");
        }
    }

    /*
    |----------------------------------------------------------------------
    | R49 · los skills viven en .agents/skills y .claude/skills son enlaces
    |----------------------------------------------------------------------
    */
    private function checkSkillsAreLinked(): void
    {
        $canonical = $this->root.'/.agents/skills';
        $mirror = $this->root.'/.claude/skills';

        if (! is_dir($canonical) || ! $this->skillsInScope()) {
            return;
        }

        foreach ($this->skillNames($canonical) as $name) {
            $this->assertSkillIsLinked($mirror.'/'.$name, $name);
        }

        if (! is_dir($mirror)) {
            return;
        }

        foreach ($this->skillNames($mirror) as $name) {
            if (is_dir($canonical.'/'.$name)) {
                continue;
            }

            $this->violation('R49', $mirror.'/'.$name, 1, "en .claude/skills hay «{$name}», que no existe en .agents/skills: el original vive allí y aquí sólo van enlaces");
        }
    }

    /**
     * `.claude/skills/{nombre}` tiene que ser exactamente el symlink relativo
     * `../../.agents/skills/{nombre}`.
     *
     * La ruta relativa importa: una absoluta rompe el repositorio para
     * cualquier otro clon, y una copia real vuelve a la deriva que R49 vino a
     * cerrar.
     */
    private function assertSkillIsLinked(string $link, string $name): void
    {
        $expected = '../../.agents/skills/'.$name;

        if (! is_link($link)) {
            $message = file_exists($link)
                ? "es una copia y no un enlace: bórralo y crea el symlink «ln -s {$expected} .claude/skills/{$name}»"
                : "falta el enlace en .claude/skills: créalo con «ln -s {$expected} .claude/skills/{$name}»";

            $this->violation('R49', $link, 1, $message);

            return;
        }

        $target = readlink($link);

        if ($target !== $expected) {
            $this->violation('R49', $link, 1, sprintf(
                'el enlace apunta a «%s» y tiene que apuntar a «%s»',
                $target === false ? '?' : $target,
                $expected,
            ));
        }
    }

    /**
     * Nombres de los skills de una carpeta: cada entrada que sea un directorio
     * o un enlace, sin los `.` de sistema.
     *
     * @return array<int, string>
     */
    private function skillNames(string $directory): array
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $names = array_values(array_filter(
            $entries,
            fn (string $entry): bool => ! str_starts_with($entry, '.')
                && (is_dir($directory.'/'.$entry) || is_link($directory.'/'.$entry)),
        ));

        sort($names);

        return $names;
    }

    /**
     * Con `--files`, R49 sólo corre si el commit toca alguno de los dos sets:
     * es un check de estructura de carpetas, no de contenido de archivo.
     */
    private function skillsInScope(): bool
    {
        if ($this->scope === null) {
            return true;
        }

        return array_any($this->scope, fn (string $path): bool => str_starts_with($path, $this->root.'/.agents/skills') || str_starts_with($path, $this->root.'/.claude/skills'));
    }

    /*
    |----------------------------------------------------------------------
    | R50 · AGENTS.md se genera desde CLAUDE.md
    |----------------------------------------------------------------------
    */
    private function checkAgentsFileIsGenerated(): void
    {
        $agents = new AgentsFile($this->root);

        if (! $agents->sourceExists()) {
            return;
        }

        if (! $this->inScope($agents->sourcePath()) && ! $this->inScope($agents->generatedPath())) {
            return;
        }

        if ($agents->isInSync()) {
            return;
        }

        // El check no regenera nada: un hook que escribe archivos deja el
        // commit distinto de lo que el desarrollador revisó.
        $this->violation(
            'R50',
            $agents->generatedPath(),
            1,
            AgentsFile::GENERATED.' no coincide con '.AgentsFile::SOURCE
            .': corre `php artisan kore:agents:sync` y vuelve a añadir '.AgentsFile::GENERATED.' al commit',
        );
    }

    /*
    |----------------------------------------------------------------------
    | R52 · toda pantalla nueva entra en el mapa de acceso de los E2E
    |----------------------------------------------------------------------
    */

    /**
     * Cada ruta `GET` con nombre tiene su entrada en
     * `tests/e2e/fixtures/access-map.ts`.
     *
     * El mapa es la tabla «quién puede ver qué» de la suite E2E: por cada
     * pantalla, qué roles entran y cuáles se llevan un 403. Una pantalla que no
     * está en el mapa no es que falle su test: es que **no tiene** test de
     * autorización, y eso no se ve en ningún sitio.
     *
     * Se lee el texto de los archivos de rutas y no `Route::getRoutes()` a
     * propósito: así el check corre con `--root` sobre un árbol de fixtures y
     * en un pre-commit, sin bootear la aplicación ni depender de qué toggles
     * estén encendidos en la máquina que lo corre.
     *
     * Límites, escritos porque son los que hay que conocer antes de fiarse:
     *
     *   - Sólo `GET` con `->name(...)`: lo que un navegador puede abrir. Un
     *     `POST` no es una pantalla.
     *   - Las rutas con parámetro (`/users/{user}/edit`) quedan fuera: su
     *     `path` depende de un id y no se puede escribir literal en el mapa.
     *   - Las del harness (`/__e2e__/...`) también: existen sólo para la suite.
     */
    private function checkRoutesAreInAccessMap(): void
    {
        $files = $this->globFiles(['routes/web.php', 'app/Modules/*/Routes/web.php']);

        if ($files === []) {
            return;
        }

        $map = $this->root.'/tests/e2e/fixtures/access-map.ts';
        $mapContents = is_file($map) ? $this->contents($map) : null;

        $routes = [];

        foreach ($files as $path) {
            foreach ($this->namedGetRoutes($path) as $route) {
                $routes[] = [...$route, 'file' => $path];
            }
        }

        if ($routes === []) {
            return;
        }

        // El mapa lo aporta la suite E2E y puede no existir todavía (un
        // proyecto derivado que aún no la tiene, o el commit que la introduce).
        // Se avisa una vez y no se falla: R52 exige que la pantalla esté en el
        // mapa, no que el proyecto tenga E2E.
        if ($mapContents === null) {
            $this->components->warn(
                'R52: no hay tests/e2e/fixtures/access-map.ts, así que las '
                .count($routes).' ruta(s) GET con nombre no se comprueban. Ver docs/architecture/rules.md',
            );

            return;
        }

        foreach ($routes as $route) {
            foreach (["path: '{$route['path']}'", "path: \"{$route['path']}\"", "path: `{$route['path']}`"] as $needle) {
                if (str_contains($mapContents, $needle)) {
                    continue 2;
                }
            }

            if ($this->hasValveOnLine($route['file'], $route['line'], 'R52')) {
                continue;
            }

            $this->violation(
                'R52',
                $route['file'],
                $route['line'],
                "la ruta {$route['path']} no está en tests/e2e/fixtures/access-map.ts: una pantalla fuera del mapa no tiene test de autorización por rol, y eso no lo delata nada",
            );
        }
    }

    /**
     * Rutas `GET` con nombre declaradas en un archivo de rutas, con el prefijo
     * de sus grupos ya compuesto.
     *
     * @return array<int, array{path: string, line: int}>
     */
    private function namedGetRoutes(string $path): array
    {
        $lines = $this->lines($path);

        $routes = [];
        /** @var list<string> $prefixes pila de prefijos, uno por nivel de grupo abierto */
        $prefixes = [];
        $pending = null;
        $inBlockComment = false;

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);

            if ($inBlockComment) {
                $inBlockComment = ! str_contains($line, '*/');

                continue;
            }

            if (str_starts_with($trimmed, '/*')) {
                $inBlockComment = ! str_contains($line, '*/');

                continue;
            }

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            if (preg_match("/->prefix\(\s*'([^']*)'/", $line, $matched) === 1) {
                $pending = trim($matched[1], '/');
            }

            if (preg_match("/(?:Route::|->)get\(\s*'([^']*)'/", $line, $matched) === 1
                && ! str_contains($matched[1], '{')
                && str_contains($this->statementFrom($lines, $index), '->name(')) {
                $composed = $this->composeRoutePath($prefixes, $matched[1]);

                if (! str_starts_with($composed, '/__e2e__')) {
                    $routes[] = ['path' => $composed, 'line' => $index + 1];
                }
            }

            // Profundidad de llaves, sin contar las que van dentro de un
            // literal (`'/{user}/edit'`) ni las de un comentario de línea.
            $code = (string) preg_replace(['/\'[^\']*\'|"[^"]*"/', '#//.*$#'], ['', ''], $line);
            $delta = substr_count($code, '{') - substr_count($code, '}');

            for ($opened = 0; $opened < $delta; $opened++) {
                $prefixes[] = $pending ?? '';
                $pending = null;
            }

            for ($closed = 0; $closed > $delta; $closed--) {
                array_pop($prefixes);
            }
        }

        return $routes;
    }

    /**
     * La sentencia que empieza en esta línea, hasta el `;` que la cierra.
     *
     * Se corta a diez líneas: una cadena de ruta más larga que eso no existe, y
     * el tope evita recorrer el archivo entero si alguien olvida el punto y
     * coma.
     *
     * @param array<int, string> $lines
     */
    private function statementFrom(array $lines, int $index): string
    {
        $statement = '';

        for ($i = $index; $i < min($index + 10, count($lines)); $i++) {
            $statement .= $lines[$i];

            if (str_contains($lines[$i], ';')) {
                break;
            }
        }

        return $statement;
    }

    /**
     * Junta los prefijos de los grupos abiertos con la ruta declarada.
     *
     * @param list<string> $prefixes
     */
    private function composeRoutePath(array $prefixes, string $route): string
    {
        $segments = [...$prefixes, trim($route, '/')];

        $path = implode('/', array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        return '/'.$path;
    }

    /**
     * ¿Hay una válvula para esta regla en esta línea o en el comentario que la
     * precede?
     *
     * A diferencia de `hasValve()`, que exime el archivo entero, aquí la
     * válvula es de línea: un archivo de rutas declara muchas pantallas y una
     * excepción para una no puede tapar a las demás.
     */
    private function hasValveOnLine(string $path, int $number, string $rule): bool
    {
        $lines = $this->lines($path);

        for ($i = $number - 1; $i >= 0; $i--) {
            $line = trim($lines[$i] ?? '');

            // Sólo se mira la línea de la ruta y los comentarios pegados
            // encima. El corte va **antes** de buscar la válvula: si no, la
            // válvula al final de la ruta anterior eximiría también a ésta.
            if ($i !== $number - 1 && ! str_starts_with($line, '//') && ! str_starts_with($line, '*') && ! str_starts_with($line, '/*')) {
                return false;
            }

            if (preg_match('/arch-(exception|accepted):\s*'.preg_quote($rule, '/').'\b/', $line, $kind) === 1) {
                return $this->valveFormIsAllowed($rule, $kind[1]);
            }
        }

        return false;
    }

    /*
    |----------------------------------------------------------------------
    | R55 · toda URL de un archivo privado sale de FileStore::url()
    |----------------------------------------------------------------------
    */

    /**
     * Nadie fuera de `app/Modules/Files` construye la URL de un archivo.
     *
     * Emitir la URL de un archivo privado es afirmar dos cosas a la vez: que
     * quien la va a usar ya pasó por la policy del dueño, y que la firma lleva
     * dentro el `v` que invalida la caché del navegador cuando el archivo
     * cambia. Las dos las cumple `App\Core\Contracts\FileStore::url()`, y
     * ninguna la cumple un `Storage::temporaryUrl()` escrito a mano.
     *
     * Límites, escritos porque son los que hay que conocer antes de fiarse:
     *
     *   - `public_path(` en la línea la exime: un logo del propio proyecto no
     *     es un archivo de usuario y no hay nada que firmar.
     *   - `getUrl()` sólo cuenta en un archivo que hable de **media**. Es el
     *     nombre que usa media-library, pero también el que usa cualquier nodo
     *     de un parser de Markdown (`League\CommonMark\Node\Inline\Link`), y un
     *     archivo que no menciona media en ninguna parte no puede tener un
     *     `Media` en la mano. Los otros cinco patrones no son ambiguos y se
     *     miran siempre.
     */
    private function checkFileUrlsComeFromStore(): void
    {
        foreach ($this->findFiles(['app'], ['*.php']) as $path) {
            $relative = $this->relative($path);

            // El módulo Files es quien las emite: es exactamente su trabajo.
            if (str_starts_with($relative, 'app/Modules/Files/') || in_array($relative, self::SELF_REFERENTIAL, true)) {
                continue;
            }

            $touchesMedia = mb_stripos($this->contents($path), 'media') !== false;

            foreach ($this->lines($path) as $index => $line) {
                foreach (self::FILE_URL_CALLS as $needle => $name) {
                    if (! str_contains($line, $needle)) {
                        continue;
                    }

                    if ($needle === '->getUrl(' && ! $touchesMedia) {
                        continue;
                    }

                    if (str_contains($line, 'public_path(')) {
                        continue 2;
                    }

                    if ($this->hasValve($path, 'R55')) {
                        continue 3;
                    }

                    $this->violation(
                        'R55',
                        $path,
                        $index + 1,
                        "{$name} construye la URL de un archivo fuera de App\\Core\\Contracts\\FileStore::url(): la firma es la autorización y el «v» que invalida la caché va dentro de ella",
                    );

                    continue 2;
                }
            }
        }
    }

    /*
    |----------------------------------------------------------------------
    | R57 · las hojas de PDF se bastan a sí mismas
    |----------------------------------------------------------------------
    */

    /**
     * Ni CSS enlazado ni imágenes remotas en una plantilla que acaba en PDF.
     *
     * Quien convierte la hoja es Gotenberg, desde **otro contenedor**: un
     * `@vite`, un `<link rel="stylesheet">`, un `asset()` o un `src="http…"`
     * se los pide a sí mismo, no a la aplicación. Y no revienta: el PDF se
     * genera igual, sin maquetar o con el icono de imagen rota donde iba el
     * logo. El CSS va en un `<style>` en línea y las imágenes por
     * `App\Core\Support\PdfImage::embedded()`, que devuelve un `data:` URI.
     */
    private function checkPdfSheetsAreSelfContained(): void
    {
        foreach ($this->findFiles(['app/Modules'], ['*.blade.php']) as $path) {
            if (preg_match(self::PDF_SHEET, $this->relative($path)) !== 1) {
                continue;
            }

            foreach ($this->lines($path) as $index => $line) {
                foreach (self::PDF_LINKED_ASSETS as $needle => $name) {
                    if (! str_contains($line, $needle)) {
                        continue;
                    }

                    $this->violation(
                        'R57',
                        $path,
                        $index + 1,
                        "{$name} enlaza un recurso que Gotenberg no alcanza: convierte desde otro contenedor y lo enlazado sale roto en silencio. El CSS va en línea y las imágenes como data: URI con PdfImage::embedded()",
                    );

                    continue 2;
                }
            }
        }
    }

    /*
    |----------------------------------------------------------------------
    | Salida
    |----------------------------------------------------------------------
    */
    private function report(): int
    {
        if ($this->violations === []) {
            $this->components->info('kore:arch:check — sin violaciones.');

            return self::SUCCESS;
        }

        usort(
            $this->violations,
            fn (array $a, array $b): int => [$a['rule'], $a['file'], $a['line']] <=> [$b['rule'], $b['file'], $b['line']],
        );

        foreach ($this->violations as $violation) {
            $this->line(sprintf(
                '<fg=red>%s</> %s:%d  %s',
                $violation['rule'],
                $violation['file'],
                $violation['line'],
                $violation['message'],
            ));
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%d violación(es) de arquitectura. Cada regla está explicada en docs/architecture/rules.md.',
            count($this->violations),
        ));

        return self::FAILURE;
    }
}
