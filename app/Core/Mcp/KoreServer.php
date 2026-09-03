<?php

declare(strict_types=1);

namespace App\Core\Mcp;

use App\Core\Mcp\Tools\ArchCheckTool;
use App\Core\Mcp\Tools\GetRuleTool;
use App\Core\Mcp\Tools\ListModulesTool;
use App\Core\Mcp\Tools\ListPermissionsTool;
use App\Core\Mcp\Tools\ListTogglesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * MCP server propio del boilerplate: le deja preguntar al proyecto por sí mismo.
 *
 * Motivo de existir: las respuestas a «¿qué módulos hay?», «¿quién lee este
 * toggle?» o «¿qué dice R24?» están repartidas por medio repositorio
 * (`app/Modules/*`, `bootstrap/providers.php`, `config/kore-app.php`,
 * `docs/architecture/rules.md`). Un agente que las necesita hoy se lee todo eso
 * y se queda sin contexto antes de escribir la primera línea. Aquí cada
 * pregunta es una llamada con una respuesta corta.
 *
 * Frontera con `laravel-boost`: Boost ejecuta código (`tinker`), consulta la
 * base (`database-query`) y busca documentación de paquetes. Este servidor no
 * hace nada de eso: sólo lee archivos del repositorio y, como única excepción,
 * corre `kore:arch:check` (un linter textual que tampoco toca la base). Los dos
 * servidores conviven y no se solapan.
 *
 * Registro: `routes/ai.php` (`Mcp::local('kore', KoreServer::class)`), que
 * `laravel/mcp` carga solo si el archivo existe. Se arranca con
 * `php artisan mcp:start kore`.
 */
final class KoreServer extends Server
{
    /**
     * Nombre con el que se anuncia el servidor en el handshake.
     */
    protected string $name = 'kore';

    /**
     * Versión del servidor: la del boilerplate que lo publica.
     */
    protected string $version = '1.4.0';

    /**
     * Instrucciones que el cliente MCP inyecta en el contexto del modelo.
     */
    protected string $instructions = <<<'MARKDOWN'
        Servidor MCP del boilerplate kore-laravel. Responde preguntas sobre el
        propio proyecto leyendo sus archivos, para que no haga falta abrir medio
        repositorio antes de escribir código.

        Qué ofrece:

        - `kore-list-modules`: inventario de `app/Modules/*` — provider, si está
          registrado en `bootstrap/providers.php`, carpetas, Actions,
          componentes Livewire, rutas y tests.
        - `kore-list-toggles`: los toggles de `config/kore-app.php` con su
          variable de `.env`, su valor actual y qué archivos los leen.
        - `kore-list-permissions`: roles y permisos del sistema, vía el contrato
          `App\Core\Contracts\AuthorizationCatalog`.
        - `kore-get-rule`: el catálogo de reglas de `docs/architecture/rules.md`,
          entero (tabla resumen) o regla a regla.
        - `kore-arch-check`: ejecuta `php artisan kore:arch:check`.

        Qué NO hace: no ejecuta código arbitrario, no consulta la base de datos
        (sólo `kore-list-permissions` mira si los permisos están sembrados, y
        degrada a catálogo estático si la base no responde), no escribe nada y
        no devuelve secretos. Para evaluar PHP, inspeccionar el esquema o buscar
        documentación de paquetes, usa el servidor `laravel-boost`.

        Convenciones del proyecto: se trabaja en español y las reglas se citan
        por número (`R24`). Antes de proponer una excepción de arquitectura, lee
        `R44` con `kore-get-rule`.
        MARKDOWN;

    /**
     * Las herramientas del servidor.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListModulesTool::class,
        ListTogglesTool::class,
        ListPermissionsTool::class,
        GetRuleTool::class,
        ArchCheckTool::class,
    ];
}
