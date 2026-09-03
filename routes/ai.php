<?php

declare(strict_types=1);

use App\Core\Mcp\KoreServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Servidores MCP
|--------------------------------------------------------------------------
|
| `laravel/mcp` carga este archivo solo si existe (ver
| `Laravel\Mcp\Server\McpServiceProvider::registerRoutes()`), así que no hay
| que registrarlo en `bootstrap/app.php`.
|
| `laravel-boost` no aparece aquí: lo registra su propio provider
| (`Mcp::local('laravel-boost', Boost::class)`).
|
| Cada servidor declarado aquí se arranca con `php artisan mcp:start {handle}`
| y se declara para los clientes en `.mcp.json` y `.codex/config.toml`, que son
| espejo el uno del otro.
|
*/

Mcp::local('kore', KoreServer::class);
