<?php

declare(strict_types=1);

namespace App\Core\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * El catálogo de reglas de `docs/architecture/rules.md`, entero o regla a
 * regla.
 *
 * Sin esta herramienta, un agente que quiere saber qué dice una regla concreta
 * tiene dos opciones malas: leerse las mil líneas del catálogo (y quedarse sin
 * contexto) o fiarse del resumen de `CLAUDE.md` (que a propósito no tiene el
 * detalle). Aquí una regla son veinte líneas y el resumen completo es una
 * tabla.
 *
 * El formato que se parsea lo describe el propio catálogo en su sección «Cómo
 * se lee una regla»:
 *
 *     ### R{n} · Enunciado
 *     El enunciado, en una o dos frases.
 *     > Enforcement: herramienta · comando · severidad
 *     > Escape: cuál de las dos válvulas aplica (o «ninguna»)
 *     **Por qué.** ...
 *     **Cicatriz.** ...
 */
#[IsReadOnly]
#[IsIdempotent]
final class GetRuleTool extends Tool
{
    private const string CATALOG = 'docs/architecture/rules.md';

    protected string $name = 'kore-get-rule';

    protected string $title = 'Regla de arquitectura';

    protected string $description = 'Devuelve una regla del catálogo docs/architecture/rules.md por su número (por ejemplo "R24"): enunciado, enforcement, severidad, válvula admitida, por qué existe y la cicatriz que la originó. Sin parámetro (o con rule="all") devuelve la tabla resumen de todas las reglas. Úsala cuando un review, un commit o CLAUDE.md citen un número de regla, en vez de leer el catálogo entero.';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rule' => $schema->string()
                ->description('Número de la regla, con o sin la R («R24», «r24», «24»). Omítelo o pasa «all» para la tabla resumen de todo el catálogo.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $catalog = $this->catalog();

        if ($catalog === null) {
            return Response::error('No encuentro el catálogo de reglas en '.self::CATALOG.'.');
        }

        $rules = $this->parse($catalog);

        if ($rules === []) {
            return Response::error('El catálogo '.self::CATALOG.' no contiene ninguna regla con el formato «### R{n} · Enunciado».');
        }

        $requested = $request->get('rule');
        $requested = is_string($requested) ? trim($requested) : '';

        if ($requested === '' || mb_strtolower($requested) === 'all') {
            return Response::json($this->summary($rules));
        }

        $key = $this->normalise($requested);

        if ($key === null || ! isset($rules[$key])) {
            return Response::error(sprintf(
                'No existe la regla «%s» en %s. El catálogo tiene %d reglas: %s.',
                $requested,
                self::CATALOG,
                count($rules),
                implode(', ', array_keys($rules)),
            ));
        }

        return Response::text($rules[$key]['bloque']);
    }

    /**
     * Tabla resumen: una fila por regla, sin el cuerpo.
     *
     * @param array<string, array{titulo: string, enunciado: string, enforcement: string|null, escape: string|null, bloque: string}> $rules
     * @return array<string, mixed>
     */
    private function summary(array $rules): array
    {
        return [
            'catalogo' => self::CATALOG,
            'total' => count($rules),
            'como_pedir_una' => 'kore-get-rule con {"rule": "R24"}',
            'reglas' => array_values(array_map(
                fn (array $rule): array => [
                    'regla' => $rule['titulo'],
                    'enunciado' => $rule['enunciado'],
                    'enforcement' => $rule['enforcement'],
                    'escape' => $rule['escape'],
                ],
                $rules,
            )),
        ];
    }

    /**
     * «r24», «24», «R24 » → «R24». Cualquier otra cosa → null.
     */
    private function normalise(string $requested): ?string
    {
        if (preg_match('/^[Rr]?(\d{1,3})$/', $requested, $matches) !== 1) {
            return null;
        }

        return 'R'.$matches[1];
    }

    /**
     * Corta el catálogo en reglas.
     *
     * Un bloque va desde su `### R{n} ·` hasta el siguiente encabezado (`## ` o
     * `### `) o hasta la barra `---` que separa las secciones, lo que llegue
     * antes.
     *
     * @return array<string, array{titulo: string, enunciado: string, enforcement: string|null, escape: string|null, bloque: string}>
     */
    private function parse(string $contents): array
    {
        $lines = explode("\n", $contents);
        $rules = [];

        /** @var string|null $current */
        $current = null;
        /** @var list<string> $block */
        $block = [];

        foreach ($lines as $line) {
            if (preg_match('/^###\s+(R\d{1,3})\s+·/', $line, $matches) === 1) {
                if ($current !== null) {
                    $rules[$current] = $this->buildRule($block);
                }

                $current = $matches[1];
                $block = [$line];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (str_starts_with($line, '## ') || str_starts_with($line, '### ') || rtrim($line) === '---') {
                $rules[$current] = $this->buildRule($block);
                $current = null;
                $block = [];

                continue;
            }

            $block[] = $line;
        }

        if ($current !== null) {
            $rules[$current] = $this->buildRule($block);
        }

        return $rules;
    }

    /**
     * @param list<string> $block
     * @return array{titulo: string, enunciado: string, enforcement: string|null, escape: string|null, bloque: string}
     */
    private function buildRule(array $block): array
    {
        $title = ltrim($block[0] ?? '', '# ');
        $statement = [];
        $enforcement = null;
        $escape = null;

        foreach (array_slice($block, 1) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '> Enforcement:')) {
                $enforcement = trim(mb_substr($trimmed, mb_strlen('> Enforcement:')));

                continue;
            }

            if (str_starts_with($trimmed, '> Escape:')) {
                $escape = trim(mb_substr($trimmed, mb_strlen('> Escape:')));

                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '>') || str_starts_with($trimmed, '**')) {
                continue;
            }

            if ($enforcement === null) {
                $statement[] = $trimmed;
            }
        }

        return [
            'titulo' => $title,
            'enunciado' => implode(' ', $statement),
            'enforcement' => $enforcement,
            'escape' => $escape,
            'bloque' => rtrim(implode("\n", $block))."\n",
        ];
    }

    private function catalog(): ?string
    {
        $path = base_path().'/'.self::CATALOG;

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
