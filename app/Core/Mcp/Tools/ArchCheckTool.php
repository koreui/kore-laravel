<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * La única herramienta de este servidor que ejecuta algo, y ejecuta exactamente
 * un comando: `kore:arch:check`.
 *
 * Existe porque el agente que acaba de escribir código necesita saber si rompió
 * una regla antes de dar el trabajo por terminado, y el check textual tarda
 * ~0,2 s. Que sea una tool y no «corre esto en la terminal» tiene un motivo
 * práctico: el agente recibe las violaciones ya formateadas, con regla, archivo
 * y línea, en vez de tener que interpretar la salida de una shell.
 *
 * No hay parámetro para elegir otro comando a propósito: en el momento en que
 * esta tool acepte un comando arbitrario deja de ser un linter y pasa a ser una
 * shell remota. Para eso está `tinker` en `laravel-boost`, que el usuario
 * autoriza sabiendo lo que autoriza.
 */
#[IsReadOnly]
#[IsIdempotent]
final class ArchCheckTool extends Tool
{
    protected string $name = 'kore-arch-check';

    protected string $title = 'Checks textuales de arquitectura';

    protected string $description = 'Ejecuta `php artisan kore:arch:check`, el verificador textual de las reglas de docs/architecture/rules.md (R11, R23, R24, R29, R30, R37, R38, R40, R44, R45, R49, R50): un #[Locked] que falta, un authorize() ausente, una migración sin down(), Eloquent en una Blade, un data-testid, un toggle que no lee nadie, un doc sin enlazar, una válvula caducada, un skill copiado en vez de enlazado o un AGENTS.md viejo. Devuelve la salida y el código de salida (0 = sin violaciones). Córrela después de tocar código, antes de dar el cambio por terminado.';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rule' => $schema->string()
                ->description('Corre sólo un check, por ejemplo «R29». Omítelo para correrlos todos.'),
            'files' => $schema->string()
                ->description('Lista de archivos separada por comas, relativos a la raíz del proyecto (lo que hace el hook de pre-commit). Omítelo para revisar todo el repositorio.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $parameters = [];

        foreach (['rule', 'files'] as $option) {
            $value = $request->get($option);

            if (is_string($value) && trim($value) !== '') {
                $parameters['--'.$option] = trim($value);
            }
        }

        try {
            $exitCode = Artisan::call('kore:arch:check', $parameters);
            $output = Artisan::output();
        } catch (Throwable $throwable) {
            return Response::error('No se pudo ejecutar kore:arch:check ('.$throwable::class.'): '.$throwable->getMessage());
        }

        $body = sprintf(
            "$ php artisan kore:arch:check%s\n\n%s\nexit code: %d (%s)",
            $this->flags($parameters),
            trim($output) === '' ? '(sin salida)' : rtrim($output),
            $exitCode,
            $exitCode === 0 ? 'sin violaciones' : 'hay violaciones que arreglar',
        );

        return $exitCode === 0 ? Response::text($body) : Response::error($body);
    }

    /**
     * Reconstruye la línea de comando para que la salida diga qué se corrió.
     *
     * @param array<string, string> $parameters
     */
    private function flags(array $parameters): string
    {
        $flags = '';

        foreach ($parameters as $option => $value) {
            $flags .= ' '.$option.'='.$value;
        }

        return $flags;
    }
}
