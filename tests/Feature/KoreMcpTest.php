<?php

declare(strict_types=1);

use App\Core\Mcp\KoreServer;
use App\Core\Mcp\Tools\ArchCheckTool;
use App\Core\Mcp\Tools\GetRuleTool;
use App\Core\Mcp\Tools\ListModulesTool;
use App\Core\Mcp\Tools\ListPermissionsTool;
use App\Core\Mcp\Tools\ListTogglesTool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Tool as McpTool;

/*
|--------------------------------------------------------------------------
| MCP server propio (`kore`)
|--------------------------------------------------------------------------
|
| Las tools se ejercitan con el helper de test de laravel/mcp
| (`KoreServer::tool()`, que resuelve la tool del contenedor y le manda un
| `tools/call` real por un transporte falso): así el test cubre también el
| nombre, el esquema y la serialización de la respuesta, que es lo que ve el
| agente. `mcpToolPayload()` (al final) es el atajo para los asserts que
| necesitan entrar en la estructura del JSON.
|
| No se arranca `php artisan mcp:start kore` desde aquí: ese comando se queda
| escuchando stdin para siempre. Que el servidor esté registrado se comprueba
| por el registrar, que es lo que ese comando consulta.
|
*/

test('el servidor kore está registrado en routes/ai.php', function (): void {
    expect(Mcp::getLocalServer('kore'))->not->toBeNull();
    expect(Mcp::getLocalServer('inexistente'))->toBeNull();
});

test('el servidor kore expone las cinco tools con su nombre público', function (): void {
    $names = array_map(
        fn (string $tool): string => resolve($tool)->name(),
        [
            ListModulesTool::class,
            ListTogglesTool::class,
            ListPermissionsTool::class,
            GetRuleTool::class,
            ArchCheckTool::class,
        ],
    );

    expect($names)->toBe([
        'kore-list-modules',
        'kore-list-toggles',
        'kore-list-permissions',
        'kore-get-rule',
        'kore-arch-check',
    ]);
});

/*
|--------------------------------------------------------------------------
| kore-list-modules
|--------------------------------------------------------------------------
*/

test('kore-list-modules lista los módulos con su provider registrado', function (): void {
    KoreServer::tool(ListModulesTool::class)
        ->assertOk()
        ->assertName('kore-list-modules')
        ->assertSee([
            'App\\\\Modules\\\\Auth\\\\Providers\\\\AuthModuleServiceProvider',
            'App\\\\Modules\\\\Users\\\\Providers\\\\UsersModuleServiceProvider',
            'App\\\\Modules\\\\Tenancy\\\\Providers\\\\TenancyModuleServiceProvider',
            'App\\\\Modules\\\\Docs\\\\Providers\\\\DocsModuleServiceProvider',
        ]);
});

test('kore-list-modules cuenta carpetas, Actions, Livewire, rutas y tests', function (): void {
    $modules = mcpToolPayload(ListModulesTool::class);

    $users = collect(Arr::get($modules, 'modulos'))->firstWhere('nombre', 'Users');

    expect($users)->not->toBeNull()
        ->and($users['registrado_en_bootstrap'])->toBeTrue()
        ->and($users['carpetas'])->toContain('Actions', 'Policies', 'Http')
        ->and($users['carpetas_no_permitidas'])->toBe([])
        ->and($users['actions'])->toBeGreaterThan(0)
        ->and($users['componentes_livewire'])->toBeGreaterThan(0)
        ->and($users['rutas']['web'])->toBeTrue()
        ->and($users['tests'])->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| kore-list-toggles
|--------------------------------------------------------------------------
*/

test('kore-list-toggles devuelve las quince claves de kore-app con su variable de .env y un lector', function (): void {
    $payload = mcpToolPayload(ListTogglesTool::class);

    $toggles = collect(Arr::get($payload, 'toggles'))->keyBy('clave');

    expect($toggles)->toHaveCount(17)
        ->and($toggles->keys()->all())->toBe([
            'kore-app.api.enabled',
            'kore-app.tenancy.enabled',
            'kore-app.backup.enabled',
            'kore-app.docs.enabled',
            'kore-app.devices.enabled',
            'kore-app.pdf.enabled',
            'kore-app.files.enabled',
            'kore-app.notifications.enabled',
            'kore-app.mx.enabled',
            'kore-app.socialite.google',
            'kore-app.socialite.github',
            'kore-app.auth.two_factor',
            'kore-app.auth.magic_links',
            'kore-app.auth.social_login',
            'kore-app.auth.passkeys',
            'kore-app.auth.invitations',
            'kore-app.e2e.harness',
        ]);

    expect($toggles->get('kore-app.api.enabled')['env'])->toBe('API_ENABLED')
        ->and($toggles->get('kore-app.tenancy.enabled')['env'])->toBe('TENANCY_ENABLED')
        ->and($toggles->get('kore-app.backup.enabled')['env'])->toBe('BACKUP_ENABLED')
        ->and($toggles->get('kore-app.docs.enabled')['env'])->toBe('DOCS_ENABLED')
        ->and($toggles->get('kore-app.devices.enabled')['env'])->toBe('DEVICES_ENABLED')
        ->and($toggles->get('kore-app.pdf.enabled')['env'])->toBe('PDF_ENABLED')
        ->and($toggles->get('kore-app.files.enabled')['env'])->toBe('FILES_ENABLED')
        ->and($toggles->get('kore-app.notifications.enabled')['env'])->toBe('NOTIFICATIONS_ENABLED')
        ->and($toggles->get('kore-app.mx.enabled')['env'])->toBe('MX_ENABLED')
        ->and($toggles->get('kore-app.socialite.google')['env'])->toBe('SOCIAL_GOOGLE')
        ->and($toggles->get('kore-app.socialite.github')['env'])->toBe('SOCIAL_GITHUB')
        ->and($toggles->get('kore-app.auth.two_factor')['env'])->toBe('AUTH_2FA_ENABLED')
        ->and($toggles->get('kore-app.auth.magic_links')['env'])->toBe('AUTH_MAGIC_LINKS')
        ->and($toggles->get('kore-app.auth.social_login')['env'])->toBe('AUTH_SOCIAL_LOGIN')
        ->and($toggles->get('kore-app.auth.passkeys')['env'])->toBe('AUTH_PASSKEYS')
        ->and($toggles->get('kore-app.auth.invitations')['env'])->toBe('AUTH_INVITATIONS')
        ->and($toggles->get('kore-app.e2e.harness')['env'])->toBe('E2E_HARNESS');

    // R11: un toggle que no lee nadie es un toggle fantasma. Si esto falla, el
    // que miente es el boilerplate, no la herramienta.
    $toggles->each(function (array $toggle): void {
        expect($toggle['leido_por'])->not->toBeEmpty("El toggle {$toggle['clave']} no lo lee nadie (R11).");
    });
});

test('kore-list-toggles nombra las claves que no son toggles sin devolver su valor', function (): void {
    Config::set('sentry.dsn', 'https://clave-publica@o0.ingest.sentry.io/1234567');
    Config::set('health.secret_token', 'token-secretisimo-de-prueba');

    KoreServer::tool(ListTogglesTool::class)
        ->assertOk()
        ->assertSee(['pulse.enabled', 'sentry.dsn', 'health.secret_token', 'configurado'])
        ->assertDontSee([
            'https://clave-publica@o0.ingest.sentry.io/1234567',
            'token-secretisimo-de-prueba',
        ]);
});

/*
|--------------------------------------------------------------------------
| kore-list-permissions
|--------------------------------------------------------------------------
*/

test('kore-list-permissions devuelve los roles del sistema desde el catálogo', function (): void {
    KoreServer::tool(ListPermissionsTool::class)
        ->assertOk()
        ->assertSee([
            'App\\\\Core\\\\Contracts\\\\AuthorizationCatalog',
            'superadmin',
            'Administrador',
            'Usuario',
        ]);
});

test('kore-list-permissions marca superadmin como no asignable en la UI', function (): void {
    $payload = mcpToolPayload(ListPermissionsTool::class);

    $roles = collect(Arr::get($payload, 'roles_del_sistema'))->keyBy('valor');

    expect($roles->keys()->all())->toBe(['superadmin', 'Administrador', 'Usuario'])
        ->and($roles->get('superadmin')['asignable_en_ui'])->toBeFalse()
        ->and($roles->get('Administrador')['asignable_en_ui'])->toBeTrue()
        ->and($roles->get('Usuario')['asignable_en_ui'])->toBeTrue()
        ->and(Arr::get($payload, 'avisos'))->toBe([])
        ->and(Arr::get($payload, 'permisos_sembrados.existe'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| kore-get-rule
|--------------------------------------------------------------------------
*/

test('kore-get-rule devuelve el bloque completo de una regla', function (): void {
    KoreServer::tool(GetRuleTool::class, ['rule' => 'R24'])
        ->assertOk()
        ->assertSee(['#[Locked]', 'Enforcement:', 'Escape:', 'Cicatriz.']);
});

test('kore-get-rule acepta el número con o sin la R', function (): void {
    foreach (['R24', 'r24', '24'] as $input) {
        KoreServer::tool(GetRuleTool::class, ['rule' => $input])
            ->assertOk()
            ->assertSee('#[Locked]');
    }
});

test('kore-get-rule sin parámetro devuelve la tabla resumen del catálogo', function (): void {
    $payload = mcpToolPayload(GetRuleTool::class);

    $rules = collect(Arr::get($payload, 'reglas'))->keyBy(
        fn (array $rule): string => (string) strtok($rule['regla'], ' '),
    );

    expect(Arr::get($payload, 'catalogo'))->toBe('docs/architecture/rules.md')
        ->and($rules->count())->toBe(Arr::get($payload, 'total'))
        ->and($rules->count())->toBeGreaterThanOrEqual(45)
        ->and($rules->has('R1'))->toBeTrue()
        ->and($rules->get('R24')['enforcement'])->toContain('kore:arch:check')
        ->and($rules->get('R24')['escape'])->toContain('arch-exception');
});

test('kore-get-rule con una regla inexistente devuelve un error claro', function (): void {
    KoreServer::tool(GetRuleTool::class, ['rule' => 'R1000'])
        ->assertHasErrors(['No existe la regla', 'docs/architecture/rules.md']);

    KoreServer::tool(GetRuleTool::class, ['rule' => 'inexistente'])
        ->assertHasErrors(['No existe la regla']);
});

/*
|--------------------------------------------------------------------------
| kore-arch-check
|--------------------------------------------------------------------------
*/

test('kore-arch-check corre el comando y devuelve exit 0 sobre el repositorio', function (): void {
    KoreServer::tool(ArchCheckTool::class)
        ->assertOk()
        ->assertSee(['php artisan kore:arch:check', 'exit code: 0', 'sin violaciones']);
});

test('kore-arch-check acepta una regla concreta y una lista de archivos', function (): void {
    KoreServer::tool(ArchCheckTool::class, ['rule' => 'R29'])
        ->assertOk()
        ->assertSee('--rule=R29');

    KoreServer::tool(ArchCheckTool::class, ['files' => 'config/kore-app.php'])
        ->assertOk()
        ->assertSee('--files=config/kore-app.php');
});

test('kore-arch-check informa del error cuando el check no existe, sin excepción', function (): void {
    KoreServer::tool(ArchCheckTool::class, ['rule' => 'R1000'])
        ->assertHasErrors(['exit code: 1']);
});

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

/**
 * El JSON que devuelve una tool, ya decodificado, para los asserts que
 * necesitan mirar dentro de la estructura en vez de buscar una subcadena.
 *
 * Aquí sí se llama a `handle()` directamente: el viaje por el transporte lo
 * cubren los `assertSee` de arriba, y `Response::content()` es `Stringable`,
 * así que esto no depende de la forma interna de la respuesta JSON-RPC.
 *
 * @param class-string<McpTool> $tool
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcpToolPayload(string $tool, array $arguments = []): array
{
    $response = resolve($tool)->handle(new McpRequest($arguments));

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    return $payload;
}
