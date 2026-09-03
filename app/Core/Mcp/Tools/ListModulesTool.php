<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Inventario de `app/Modules/*`.
 *
 * Todo sale de leer el sistema de archivos: ni se instancia ni se autocarga una
 * sola clase de `App\Modules`, porque `App\Core` no puede depender de un módulo
 * (R6) y porque un módulo con el toggle apagado ni siquiera se registra (R10).
 * Leer la carpeta lo ve igual.
 */
#[IsReadOnly]
#[IsIdempotent]
final class ListModulesTool extends Tool
{
    /**
     * Carpetas que un módulo puede tener (R3). La lista es cerrada: cualquier
     * otra se reporta como `carpetas_no_permitidas` en vez de silenciarse, que
     * es lo mismo que hace `tests/Arch/ArchitectureTest.php`.
     *
     * @var list<string>
     */
    private const array ALLOWED_FOLDERS = [
        'Actions', 'Console', 'Data', 'Database', 'Events', 'Fortify', 'Forms',
        'Http', 'Listeners', 'Models', 'Policies', 'Providers', 'Resources',
        'Routes', 'Rules', 'Support', 'Tests',
    ];

    protected string $name = 'kore-list-modules';

    protected string $title = 'Módulos del boilerplate';

    protected string $description = 'Inventario de los módulos de app/Modules: nombre, ServiceProvider, si está registrado en bootstrap/providers.php, qué carpetas de la lista cerrada (R3) tiene, cuántas Actions y componentes Livewire, si expone rutas web/api y cuántos tests trae. Úsala antes de crear un módulo o para saber dónde vive un dominio, en vez de listar el árbol de archivos.';

    /**
     * No recibe parámetros: el inventario completo cabe de sobra en una
     * respuesta y filtrarlo obligaría al agente a saber el nombre de antemano.
     */
    public function handle(Request $request): Response
    {
        $root = base_path();
        $providers = $this->contents($root.'/bootstrap/providers.php');

        $modules = [];

        foreach ($this->directories($root.'/app/Modules') as $path) {
            $modules[] = $this->describe($path, $providers);
        }

        return Response::json([
            'raiz' => 'app/Modules',
            'total' => count($modules),
            'carpetas_permitidas' => self::ALLOWED_FOLDERS,
            'modulos' => $modules,
        ]);
    }

    /**
     * Ficha de un módulo a partir de su carpeta.
     *
     * @return array<string, mixed>
     */
    private function describe(string $path, string $providers): array
    {
        $name = basename($path);
        $folders = array_map(basename(...), $this->directories($path));
        $provider = $this->provider($path, $name);

        return [
            'nombre' => $name,
            'ruta' => 'app/Modules/'.$name,
            'provider' => $provider,
            'registrado_en_bootstrap' => $provider !== null && str_contains($providers, $provider),
            'carpetas' => array_values(array_intersect($folders, self::ALLOWED_FOLDERS)),
            'carpetas_no_permitidas' => array_values(array_diff($folders, self::ALLOWED_FOLDERS)),
            'actions' => count($this->files($path.'/Actions/*.php')),
            'componentes_livewire' => count($this->files($path.'/Http/Livewire/*.php')),
            'rutas' => [
                'web' => is_file($path.'/Routes/web.php'),
                'api' => is_file($path.'/Routes/api.php'),
            ],
            'tests' => $this->countTests($path.'/Tests'),
        ];
    }

    /**
     * FQCN del `{Modulo}ServiceProvider` del módulo, o null si no tiene (R9).
     */
    private function provider(string $path, string $module): ?string
    {
        $found = $this->files($path.'/Providers/*ServiceProvider.php');

        if ($found === []) {
            return null;
        }

        return 'App\\Modules\\'.$module.'\\Providers\\'.basename($found[0], '.php');
    }

    /**
     * Tests del módulo, recursivo: `Tests/Feature`, `Tests/Unit` y lo que haya
     * debajo.
     */
    private function countTests(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $total = count($this->files($path.'/*.php'));

        foreach ($this->directories($path) as $child) {
            $total += $this->countTests($child);
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    private function directories(string $path): array
    {
        return $this->glob($path.'/*', GLOB_ONLYDIR);
    }

    /**
     * @return list<string>
     */
    private function files(string $pattern): array
    {
        return array_values(array_filter($this->glob($pattern), is_file(...)));
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern, int $flags = 0): array
    {
        $found = glob($pattern, $flags);

        if ($found === false) {
            return [];
        }

        sort($found);

        return $found;
    }

    private function contents(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}
