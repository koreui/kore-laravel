<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| kore:arch:check
|--------------------------------------------------------------------------
|
| Cada check tiene su par: un árbol de fixtures que lo viola y otro que lo
| cumple. Los fixtures viven en `storage/framework/testing/arch-check/` y se
| revisan con `--root`, así que el linter nunca mira el repositorio real
| durante estos tests (que, con `pest --parallel`, correrían a la vez que los
| arch tests de verdad).
|
| Regla que se está probando aquí: R44 y compañía de
| docs/architecture/rules.md.
|
*/

/**
 * Crea un árbol de fixtures y devuelve su raíz.
 *
 * Siempre incluye un `docs/architecture/rules.md` mínimo, porque el catálogo
 * es lo que hace que una válvula o una cita se consideren válidas.
 *
 * @param array<string, string> $files ruta relativa => contenido
 */
function archFixture(array $files, string $catalog = "### R11 · toggles\n### R23 · authorize\n### R24 · locked\n### R29 · down\n### R30 · blade\n### R37 · testid\n### R38 · timeout\n### R40 · docs\n### R44 · valvulas\n### R45 · baseline\n"): string
{
    $root = storage_path('framework/testing/arch-check/'.uniqid('case_', true));

    File::ensureDirectoryExists($root.'/docs/architecture');
    File::put($root.'/docs/architecture/rules.md', "# Reglas\n\n".$catalog);

    foreach ($files as $path => $contents) {
        $absolute = $root.'/'.$path;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $contents);
    }

    return $root;
}

/**
 * Corre el comando contra un árbol de fixtures y devuelve [exitCode, salida].
 *
 * @param array<string, string> $extra opciones adicionales
 * @return array{0: int, 1: string}
 */
function archCheck(string $root, ?string $rule = null, array $extra = []): array
{
    $options = ['--root' => $root, ...$extra];

    if ($rule !== null) {
        $options['--rule'] = $rule;
    }

    $exit = Artisan::call('kore:arch:check', $options);

    return [$exit, Artisan::output()];
}

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/arch-check'));
});

/*
|--------------------------------------------------------------------------
| R29 · toda migración define down()
|--------------------------------------------------------------------------
*/

it('R29 · falla cuando una migración no define down()', function (): void {
    $root = archFixture([
        'database/migrations/2026_01_01_000000_create_things_table.php' => "<?php\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n",
    ]);

    [$exit, $output] = archCheck($root, 'R29');

    expect($exit)->toBe(1)
        ->and($output)->toContain('R29')
        ->and($output)->toContain('no define down()');
});

it('R29 · pasa cuando la migración define down() o lleva una válvula', function (): void {
    $root = archFixture([
        'database/migrations/2026_01_01_000000_create_things_table.php' => "<?php\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n\n    public function down(): void {}\n};\n",
        'database/migrations/2026_01_02_000000_backfill_things.php' => "<?php\n\n// arch-accepted: R29 · backfill de datos, no hay vuelta atrás · @cesar\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n",
    ]);

    [$exit, $output] = archCheck($root, 'R29');

    expect($exit)->toBe(0)->and($output)->toContain('sin violaciones');
});

/*
|--------------------------------------------------------------------------
| R24 · #[Locked] en las propiedades identificadoras
|--------------------------------------------------------------------------
*/

it('R24 · falla cuando una propiedad identificadora no lleva #[Locked]', function (): void {
    $root = archFixture([
        'app/Modules/Demo/Forms/DemoForm.php' => "<?php\n\nfinal class DemoForm extends Form\n{\n    public ?int \$id = null;\n\n    public string \$name = '';\n}\n",
    ]);

    [$exit, $output] = archCheck($root, 'R24');

    expect($exit)->toBe(1)
        ->and($output)->toContain('R24')
        ->and($output)->toContain('DemoForm.php:5');
});

it('R24 · pasa cuando la propiedad lleva #[Locked]', function (): void {
    $root = archFixture([
        'app/Modules/Demo/Forms/DemoForm.php' => "<?php\n\nfinal class DemoForm extends Form\n{\n    #[Locked]\n    public ?int \$id = null;\n}\n",
        'app/Modules/Demo/Http/Livewire/DemoTable.php' => "<?php\n\nfinal class DemoTable extends Component\n{\n    /** El modelo que se edita. */\n    #[Locked]\n    public ?User \$model = null;\n\n    public string \$search = '';\n}\n",
    ]);

    [$exit] = archCheck($root, 'R24');

    expect($exit)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R23 · autorización dentro del componente Livewire
|--------------------------------------------------------------------------
*/

it('R23 · falla cuando un método de escritura no autoriza', function (): void {
    $root = archFixture([
        'app/Modules/Demo/Http/Livewire/DemoForm.php' => "<?php\n\nfinal class DemoForm extends Component\n{\n    public function save(): void\n    {\n        \$this->form->validate();\n    }\n}\n",
    ]);

    [$exit, $output] = archCheck($root, 'R23');

    expect($exit)->toBe(1)
        ->and($output)->toContain('R23')
        ->and($output)->toContain('save() escribe sin autorizar');
});

it('R23 · pasa cuando el método autoriza y no confunde métodos de lectura', function (): void {
    $root = archFixture([
        'app/Modules/Demo/Http/Livewire/DemoForm.php' => "<?php\n\nfinal class DemoForm extends Component\n{\n    public function save(): void\n    {\n        \$this->authorize('create', User::class);\n    }\n\n    public function confirmDelete(int \$id): void\n    {\n        Gate::authorize('delete', \$id);\n    }\n\n    public function render(): mixed\n    {\n        return view('demo::form');\n    }\n}\n",
    ]);

    [$exit] = archCheck($root, 'R23');

    expect($exit)->toBe(0);
});

it('R23 · cubre el vocabulario de escritura más allá del CRUD literal', function (string $method): void {
    $root = archFixture([
        'app/Modules/Demo/Http/Livewire/DemoForm.php' => "<?php\n\nfinal class DemoForm extends Component\n{\n    public function {$method}(): void\n    {\n        \$this->form->validate();\n    }\n}\n",
    ]);

    [$exit, $output] = archCheck($root, 'R23');

    expect($exit)->toBe(1)
        ->and($output)->toContain("{$method}() escribe sin autorizar");
})->with([
    'save', 'store', 'createInvoice', 'updateProfile', 'deleteRow', 'destroyAll',
    'removeMember', 'confirmDelete', 'toggleActive', 'addMember', 'sendCode',
    'syncRoles', 'assignOwner', 'approveRequest', 'importCsv',
]);

it('R23 · no marca los métodos que sólo leen o pintan', function (string $method): void {
    $root = archFixture([
        'app/Modules/Demo/Http/Livewire/DemoForm.php' => "<?php\n\nfinal class DemoForm extends Component\n{\n    public function {$method}(): mixed\n    {\n        return view('demo::form');\n    }\n}\n",
    ]);

    [$exit] = archCheck($root, 'R23');

    expect($exit)->toBe(0);
})->with(['render', 'mount', 'hydrate', 'columns', 'filters', 'query', 'title', 'stats', 'authenticate']);

it('R23 · acepta la válvula de un flujo de invitado', function (): void {
    $root = archFixture([
        'app/Modules/Demo/Http/Livewire/GuestForm.php' => "<?php\n\nfinal class GuestForm extends Component\n{\n    // arch-accepted: R23 · flujo de invitado, protegido por rate limit · @cesar\n    public function sendCode(): void\n    {\n        RateLimiter::hit(\$this->throttleKey());\n    }\n}\n",
    ]);

    [$exit] = archCheck($root, 'R23');

    expect($exit)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R44 · gramática y caducidad de las válvulas
|--------------------------------------------------------------------------
*/

it('R44 · falla cuando la válvula está mal formada, caducada o cita una regla inexistente', function (): void {
    $root = archFixture([
        'app/Demo/Malformed.php' => "<?php\n\n// arch-exception: R29, sin owner ni fecha\n",
        'app/Demo/Expired.php' => "<?php\n\n// arch-exception: R30 · deuda vieja · @cesar · 2020-01-01\n",
        'app/Demo/Unknown.php' => "<?php\n\n// arch-accepted: R99 · regla que no existe · @cesar\n",
    ]);

    [$exit, $output] = archCheck($root, 'R44');

    expect($exit)->toBe(1)
        ->and($output)->toContain('válvula mal formada')
        ->and($output)->toContain('venció el 2020-01-01')
        ->and($output)->toContain('R99, que no existe');
});

it('R44 · pasa con las dos formas bien escritas y una fecha futura', function (): void {
    $root = archFixture([
        'app/Demo/Ok.php' => "<?php\n\n// arch-exception: R30 · migración pendiente del dashboard · @cesar · 2099-12-31\n// arch-accepted: R24 · la propiedad no identifica un modelo · @cesar\n",
    ]);

    [$exit] = archCheck($root, 'R44');

    expect($exit)->toBe(0);
});

/*
 * La forma de la válvula se valida contra el `> Escape:` que la regla declara
 * en el catálogo. Estos casos usan un catálogo propio para poder declarar cada
 * variante sin depender del de verdad.
 */
$catalogoConEscapes = "### R80 · sólo accepted\n> Escape: `arch-accepted`\n\n"
    ."### R81 · sólo exception\n> Escape: `arch-exception`\n\n"
    ."### R82 · ninguna\n> Escape: ninguna\n\n"
    ."### R83 · las dos\n> Escape: `arch-accepted` o `arch-exception`\n\n"
    ."### R84 · sin escape declarado\n";

it('R44 · rechaza la forma de válvula que la regla no declara', function (string $valvula, string $esperado) use ($catalogoConEscapes): void {
    $root = archFixture(['app/Demo/Forma.php' => "<?php\n\n// {$valvula}\n"], $catalogoConEscapes);

    [$exit, $output] = archCheck($root, 'R44');

    expect($exit)->toBe(1)->and($output)->toContain($esperado);
})->with([
    'exception sobre una regla de sólo accepted' => [
        'arch-exception: R80 · deuda · @cesar · 2099-12-31',
        'R80 sólo admite arch-accepted',
    ],
    'accepted sobre una regla de sólo exception' => [
        'arch-accepted: R81 · decisión · @cesar',
        'R81 sólo admite arch-exception',
    ],
    'accepted sobre una regla sin válvula' => [
        'arch-accepted: R82 · decisión · @cesar',
        'R82 no admite válvula',
    ],
    'exception sobre una regla sin válvula' => [
        'arch-exception: R82 · deuda · @cesar · 2099-12-31',
        'R82 no admite válvula',
    ],
]);

it('R44 · acepta la forma que la regla sí declara', function (string $valvula) use ($catalogoConEscapes): void {
    $root = archFixture(['app/Demo/Forma.php' => "<?php\n\n// {$valvula}\n"], $catalogoConEscapes);

    [$exit] = archCheck($root, 'R44');

    expect($exit)->toBe(0);
})->with([
    'accepted donde toca' => 'arch-accepted: R80 · decisión revisada · @cesar',
    'exception donde toca' => 'arch-exception: R81 · deuda con fecha · @cesar · 2099-12-31',
    'accepted en una regla que admite las dos' => 'arch-accepted: R83 · decisión · @cesar',
    'exception en una regla que admite las dos' => 'arch-exception: R83 · deuda · @cesar · 2099-12-31',
    'accepted en una regla que no declara escape' => 'arch-accepted: R84 · el catálogo no restringe · @cesar',
    'exception en una regla que no declara escape' => 'arch-exception: R84 · el catálogo no restringe · @cesar · 2099-12-31',
]);

it('R44 · una válvula de la forma equivocada tampoco exime a su check', function () use ($catalogoConEscapes): void {
    // R29 admite `arch-accepted`; aquí se usa `arch-exception`, así que la
    // migración sigue sin down() a efectos de R29.
    $catalogo = $catalogoConEscapes."\n### R29 · down\n> Escape: `arch-accepted`\n";

    $root = archFixture([
        'database/migrations/2026_01_01_000000_sin_down.php' => "<?php\n\n// arch-exception: R29 · deuda · @cesar · 2099-12-31\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n",
    ], $catalogo);

    [$exit, $output] = archCheck($root, 'R29');

    expect($exit)->toBe(1)->and($output)->toContain('no define down()');
});

/*
|--------------------------------------------------------------------------
| R30 · sin Eloquent en Blade · R37 · sin data-testid
|--------------------------------------------------------------------------
*/

it('R30 y R37 · fallan ante Eloquent o data-testid en una Blade', function (): void {
    $root = archFixture([
        'resources/views/demo.blade.php' => "@php\n    \$total = User::count();\n@endphp\n<div data-testid=\"total\">{{ \$total }}</div>\n",
    ]);

    [$exitEloquent, $outputEloquent] = archCheck($root, 'R30');
    [$exitTestId, $outputTestId] = archCheck($root, 'R37');

    expect($exitEloquent)->toBe(1)
        ->and($outputEloquent)->toContain('Eloquent dentro de una Blade')
        ->and($exitTestId)->toBe(1)
        ->and($outputTestId)->toContain('data-testid');
});

it('R30 y R37 · pasan con una Blade que sólo pinta datos', function (): void {
    $root = archFixture([
        'resources/views/demo.blade.php' => "<div>{{ \$total }}</div>\n",
        'app/Modules/Demo/Resources/views/card.blade.php' => "<x-kore::card>{{ \$stat->label }}</x-kore::card>\n",
    ]);

    [$exitEloquent] = archCheck($root, 'R30');
    [$exitTestId] = archCheck($root, 'R37');

    expect($exitEloquent)->toBe(0)->and($exitTestId)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R38 · sin waitForTimeout en los E2E
|--------------------------------------------------------------------------
*/

it('R38 · falla ante waitForTimeout() pero ignora los comentarios que lo explican', function (): void {
    $bad = archFixture([
        'tests/e2e/specs/demo.spec.ts' => "test('demo', async ({ page }) => {\n    await page.waitForTimeout(500);\n});\n",
    ]);

    $good = archFixture([
        'tests/e2e/support/livewire.ts' => "// Nunca `page.waitForTimeout()`: aquí se espera a una respuesta HTTP real.\nexport const wait = 1;\n",
    ]);

    [$exitBad, $outputBad] = archCheck($bad, 'R38');
    [$exitGood] = archCheck($good, 'R38');

    expect($exitBad)->toBe(1)
        ->and($outputBad)->toContain('waitForTimeout')
        ->and($exitGood)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R11 · un toggle sólo existe si alguien lo lee
|--------------------------------------------------------------------------
*/

it('R11 · falla cuando un toggle no lo lee nadie', function (): void {
    $root = archFixture([
        'config/kore-app.php' => "<?php\n\nreturn ['ghost' => ['enabled' => true]];\n",
        'app/Demo/Reader.php' => "<?php\n\nreturn 1;\n",
    ]);

    [$exit, $output] = archCheck($root, 'R11');

    expect($exit)->toBe(1)
        ->and($output)->toContain('kore-app.ghost.enabled');
});

it('R11 · pasa cuando alguien lee el toggle', function (): void {
    $root = archFixture([
        'config/kore-app.php' => "<?php\n\nreturn ['demo' => ['enabled' => true]];\n",
        'app/Demo/Reader.php' => "<?php\n\nreturn (bool) config('kore-app.demo.enabled');\n",
    ]);

    [$exit] = archCheck($root, 'R11');

    expect($exit)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R40 · índice de docs y citas de reglas
|--------------------------------------------------------------------------
*/

it('R40 · falla cuando un doc no está en el índice o se cita una regla inexistente', function (): void {
    $root = archFixture([
        'docs/README.md' => "# Docs\n\n- nada\n",
        'docs/guides/huerfano.md' => "# Huérfano\n",
        'app/Demo/Citador.php' => "<?php\n\n// R98: esta regla no existe\n",
    ]);

    [$exit, $output] = archCheck($root, 'R40');

    expect($exit)->toBe(1)
        ->and($output)->toContain('guides/huerfano.md')
        ->and($output)->toContain('R98, que no existe');
});

it('R40 · pasa cuando el índice está completo y las citas existen', function (): void {
    $root = archFixture([
        'docs/README.md' => "# Docs\n\n- [`architecture/rules.md`](architecture/rules.md)\n- [`guides/huerfano.md`](guides/huerfano.md)\n",
        'docs/guides/huerfano.md' => "# Ya no\n",
        'app/Demo/Citador.php' => "<?php\n\n// R29: toda migración define down()\n",
    ]);

    [$exit] = archCheck($root, 'R40');

    expect($exit)->toBe(0);
});

it('R40 · ve las citas que no van seguidas de dos puntos ni de punto medio', function (string $cita): void {
    $root = archFixture([
        'docs/README.md' => "# Docs\n\n- [`architecture/rules.md`](architecture/rules.md)\n",
        'app/Demo/Citador.php' => "<?php\n\n// {$cita}\n",
    ]);

    [$exit, $output] = archCheck($root, 'R40');

    expect($exit)->toBe(1)
        ->and($output)->toContain('R98, que no existe');
})->with([
    'a secas' => 'R98',
    'en un comentario de código' => 'R98 el enunciado',
    'en negrita de markdown' => '**R98** · el enunciado',
    'entre paréntesis' => 'ver (R98)',
    'al final de una frase' => 'esto lo exige R98.',
    'seguida de coma' => 'aplican R98, y ya',
    'con los dos puntos de siempre' => 'R98: el enunciado',
    'con el punto medio de siempre' => 'R98 · el enunciado',
]);

it('R40 · no confunde con una cita lo que sólo se le parece', function (string $texto): void {
    $root = archFixture([
        'docs/README.md' => "# Docs\n\n- [`architecture/rules.md`](architecture/rules.md)\n",
        'app/Demo/Citador.php' => "<?php\n\n// {$texto}\n",
    ]);

    [$exit] = archCheck($root, 'R40');

    expect($exit)->toBe(0);
})->with([
    'un robot' => 'R2D2 no es una regla',
    'una variable' => '$R98 tampoco',
    'una ruta' => 'ver docs/R98/algo.md',
    'un sufijo alfanumérico' => 'el commit R98abc',
    'una versión' => 'la release R98.4',
    'pegada a una palabra' => 'PHPatR98',
    'con guion delante' => 'el ticket ABC-R98',
    'en minúscula' => 'el identificador r98',
    'con más de tres dígitos' => 'R9812 no cabe',
]);

/*
|--------------------------------------------------------------------------
| R45 · el baseline caduca
|--------------------------------------------------------------------------
*/

it('R45 · falla cuando el baseline no tiene cabecera o ya venció', function (): void {
    $sinCabecera = archFixture(['phpstan-baseline.neon' => "parameters:\n    ignoreErrors: []\n"]);
    $vencido = archFixture(['phpstan-baseline.neon' => "# arch-baseline: vence 2020-01-01\nparameters:\n"]);

    [$exitSinCabecera, $outputSinCabecera] = archCheck($sinCabecera, 'R45');
    [$exitVencido, $outputVencido] = archCheck($vencido, 'R45');

    expect($exitSinCabecera)->toBe(1)
        ->and($outputSinCabecera)->toContain('arch-baseline: vence')
        ->and($exitVencido)->toBe(1)
        ->and($outputVencido)->toContain('venció el 2020-01-01');
});

it('R45 · pasa con una fecha futura y cuando no hay baseline', function (): void {
    $vigente = archFixture(['phpstan-baseline.neon' => "# arch-baseline: vence 2099-12-31\nparameters:\n"]);
    $sinBaseline = archFixture([]);

    [$exitVigente] = archCheck($vigente, 'R45');
    [$exitSinBaseline] = archCheck($sinBaseline, 'R45');

    expect($exitVigente)->toBe(0)->and($exitSinBaseline)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R49 · los skills viven en .agents/skills y .claude/skills son enlaces
|--------------------------------------------------------------------------
*/

/** El SKILL.md mínimo que necesita un fixture de R49. */
function skillStub(string $name): string
{
    return "---\nname: {$name}\ndescription: Un skill de mentira.\n---\n\n# {$name}\n";
}

it('R49 · pasa cuando cada skill tiene su symlink relativo', function (): void {
    $root = archFixture(['.agents/skills/demo/SKILL.md' => skillStub('demo')]);

    File::ensureDirectoryExists($root.'/.claude/skills');
    symlink('../../.agents/skills/demo', $root.'/.claude/skills/demo');

    [$exit] = archCheck($root, 'R49');

    expect($exit)->toBe(0);
});

it('R49 · falla cuando el skill está copiado en .claude/skills en vez de enlazado', function (): void {
    $root = archFixture([
        '.agents/skills/demo/SKILL.md' => skillStub('demo'),
        '.claude/skills/demo/SKILL.md' => skillStub('demo'),
    ]);

    [$exit, $output] = archCheck($root, 'R49');

    expect($exit)->toBe(1)
        ->and($output)->toContain('R49')
        ->and($output)->toContain('es una copia y no un enlace');
});

it('R49 · falla cuando falta el enlace', function (): void {
    $root = archFixture(['.agents/skills/demo/SKILL.md' => skillStub('demo')]);

    [$exit, $output] = archCheck($root, 'R49');

    expect($exit)->toBe(1)->and($output)->toContain('falta el enlace');
});

it('R49 · falla cuando el enlace no apunta a la ruta relativa esperada', function (): void {
    $root = archFixture(['.agents/skills/demo/SKILL.md' => skillStub('demo')]);

    File::ensureDirectoryExists($root.'/.claude/skills');
    // Absoluto: funciona en esta máquina y en ninguna otra.
    symlink($root.'/.agents/skills/demo', $root.'/.claude/skills/demo');

    [$exit, $output] = archCheck($root, 'R49');

    expect($exit)->toBe(1)->and($output)->toContain('tiene que apuntar a «../../.agents/skills/demo»');
});

it('R49 · falla cuando .claude/skills tiene algo que no existe en .agents/skills', function (): void {
    $root = archFixture([
        '.agents/skills/demo/SKILL.md' => skillStub('demo'),
        '.claude/skills/huerfano/SKILL.md' => skillStub('huerfano'),
    ]);

    symlink('../../.agents/skills/demo', $root.'/.claude/skills/demo');

    [$exit, $output] = archCheck($root, 'R49');

    expect($exit)->toBe(1)->and($output)->toContain('«huerfano», que no existe en .agents/skills');
});

it('R49 · no dice nada cuando el proyecto no tiene skills', function (): void {
    [$exit] = archCheck(archFixture([]), 'R49');

    expect($exit)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R50 · AGENTS.md se genera desde CLAUDE.md
|--------------------------------------------------------------------------
*/

it('R50 · falla cuando AGENTS.md no es lo que generaría el comando', function (): void {
    $root = archFixture([
        'CLAUDE.md' => "# Proyecto\n",
        'AGENTS.md' => "# Proyecto\n",
    ]);

    [$exit, $output] = archCheck($root, 'R50');

    expect($exit)->toBe(1)
        ->and($output)->toContain('R50')
        ->and($output)->toContain('kore:agents:sync');
});

it('R50 · falla cuando AGENTS.md ni siquiera existe', function (): void {
    $root = archFixture(['CLAUDE.md' => "# Proyecto\n"]);

    [$exit] = archCheck($root, 'R50');

    expect($exit)->toBe(1);
});

it('R50 · pasa después de correr kore:agents:sync', function (): void {
    $root = archFixture(['CLAUDE.md' => "# Proyecto\n"]);

    Artisan::call('kore:agents:sync', ['--root' => $root]);

    [$exit] = archCheck($root, 'R50');

    expect($exit)->toBe(0);
});

it('R50 · no dice nada en un proyecto sin CLAUDE.md', function (): void {
    [$exit] = archCheck(archFixture([]), 'R50');

    expect($exit)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Opciones
|--------------------------------------------------------------------------
*/

it('--files limita la revisión a los archivos indicados', function (): void {
    $root = archFixture([
        'database/migrations/2026_01_01_000000_a.php' => "<?php\n\nreturn new class extends Migration { public function up(): void {} };\n",
        'database/migrations/2026_01_02_000000_b.php' => "<?php\n\nreturn new class extends Migration { public function up(): void {} public function down(): void {} };\n",
    ]);

    [$exitTodo, $outputTodo] = archCheck($root, 'R29');
    [$exitSano] = archCheck($root, 'R29', ['--files' => $root.'/database/migrations/2026_01_02_000000_b.php']);

    expect($exitTodo)->toBe(1)
        ->and($outputTodo)->toContain('2026_01_01_000000_a.php')
        ->and($exitSano)->toBe(0);
});

it('--rule desconocida devuelve error en vez de correr todo', function (): void {
    [$exit, $output] = archCheck(archFixture([]), 'R404');

    expect($exit)->toBe(1)->and($output)->toContain('No existe el check R404');
});

it('el repositorio real pasa todos los checks', function (): void {
    [$exit, $output] = archCheck(base_path());

    expect($exit)->toBe(0)->and($output)->toContain('sin violaciones');
});
