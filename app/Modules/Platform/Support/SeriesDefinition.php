<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Carbon\CarbonImmutable;

/**
 * La forma de una serie de folio, resuelta desde `config/kore-numbering.php`.
 *
 * Junta en un sitio las tres preguntas que se hacen la Action que emite y el
 * `peek()` que sólo mira: con qué número empieza la serie, en qué periodo cae
 * hoy y cómo se imprime el resultado. Sin esto, el formato viviría en la Action
 * y el periodo en el `peek()`, y bastaría con tocar uno para que dejaran de
 * coincidir — que es la forma sutil de que `peek()` mienta.
 *
 * Una serie que no está declarada no es un error: hereda `defaults` entero. Es
 * lo que hace que `next('lo-que-sea')` funcione sin configurar nada, y lo que
 * permite que un derivado empiece a numerar antes de decidir el formato
 * definitivo.
 */
final readonly class SeriesDefinition
{
    private function __construct(
        public string $name,
        public string $format,
        public string $prefix,
        public string $reset,
        public int $start,
    ) {}

    /**
     * Resuelve una serie: lo que declare, sobre lo que dicen los `defaults`.
     */
    public static function fromConfig(string $series): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('kore-numbering.defaults', []);

        /** @var array<string, mixed> $declared */
        $declared = (array) config("kore-numbering.series.{$series}", []);

        $merged = [...$defaults, ...$declared];

        return new self(
            name: $series,
            format: (string) ($merged['format'] ?? '{PREFIX}-{YEAR}-{NUMBER:6}'),
            prefix: (string) ($merged['prefix'] ?? 'DOC'),
            reset: (string) ($merged['reset'] ?? 'never'),
            start: max(0, (int) ($merged['start'] ?? 1)),
        );
    }

    /**
     * El periodo al que pertenece una emisión, ya normalizado a texto.
     *
     * `null` con `reset => never`: un solo contador para toda la vida de la
     * serie. Se devuelve resuelto —`'2026'`, no una fecha— porque es lo que
     * entra en la clave única de `number_sequences`, y una fecha obligaría a la
     * base a interpretarla igual que PHP.
     */
    public function period(CarbonImmutable $at): ?string
    {
        return $this->reset === 'yearly' ? (string) $at->year : null;
    }

    /**
     * El folio impreso: el formato de la serie con sus marcas sustituidas.
     *
     * `{NUMBER:6}` se desborda con gracia — el folio un millón sale con siete
     * dígitos en vez de truncarse, porque truncar sería emitir dos folios con
     * el mismo texto.
     */
    public function render(int $number, ?string $scope, CarbonImmutable $at): string
    {
        $replacements = [
            '{PREFIX}' => $this->prefix,
            '{YEAR}' => (string) $at->year,
            '{MONTH}' => $at->format('m'),
            '{SCOPE}' => $scope ?? '',
        ];

        $formatted = strtr($this->format, $replacements);

        return (string) preg_replace_callback(
            '/\{NUMBER(?::(\d+))?\}/',
            static fn (array $matches): string => isset($matches[1])
                ? str_pad((string) $number, (int) $matches[1], '0', STR_PAD_LEFT)
                : (string) $number,
            $formatted,
        );
    }
}
