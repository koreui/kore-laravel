<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * La relación entre `CLAUDE.md` y `AGENTS.md`, en un solo sitio (R50).
 *
 * Los dos archivos dicen lo mismo para dos clientes distintos: Claude Code lee
 * `CLAUDE.md` y el estándar AGENTS.md (Codex y compañía) lee `AGENTS.md`. Hasta
 * la v1.3.0 se mantenían a mano y el único verificador era un `diff` que había
 * que acordarse de correr; desde la v1.4.0 uno se **genera** desde el otro.
 *
 * Esta clase existe para que «lo que debería contener AGENTS.md» se escriba una
 * vez: la usan el comando que lo genera (`kore:agents:sync`) y el check que
 * verifica que sigue al día (`kore:arch:check --rule=R50`).
 */
final readonly class AgentsFile
{
    /** Archivo fuente: el que se edita a mano. */
    public const string SOURCE = 'CLAUDE.md';

    /** Archivo generado: el que nadie edita a mano. */
    public const string GENERATED = 'AGENTS.md';

    /**
     * Cabecera que se antepone al contenido de `CLAUDE.md`.
     *
     * Va en un comentario HTML porque los dos archivos son Markdown y ningún
     * renderizador lo pinta, pero cualquiera que abra `AGENTS.md` —persona o
     * agente— lo lee en la primera línea.
     */
    private const string HEADER = "<!--\n"
        ."  Generado desde CLAUDE.md por `php artisan kore:agents:sync`.\n"
        ."  No edites este archivo: edita CLAUDE.md y vuelve a correr el comando.\n"
        .'-->';

    public function __construct(private string $root) {}

    public function sourcePath(): string
    {
        return $this->root.'/'.self::SOURCE;
    }

    public function generatedPath(): string
    {
        return $this->root.'/'.self::GENERATED;
    }

    public function sourceExists(): bool
    {
        return is_file($this->sourcePath());
    }

    /**
     * Contenido que debería tener `AGENTS.md`: la cabecera y, debajo,
     * `CLAUDE.md` íntegro.
     */
    public function expected(): string
    {
        return self::HEADER."\n\n".$this->read($this->sourcePath());
    }

    /**
     * Contenido actual de `AGENTS.md`, o `null` si todavía no existe.
     */
    public function current(): ?string
    {
        return is_file($this->generatedPath()) ? $this->read($this->generatedPath()) : null;
    }

    public function isInSync(): bool
    {
        return $this->current() === $this->expected();
    }

    /**
     * Escribe `AGENTS.md` y devuelve si el archivo cambió.
     */
    public function write(): bool
    {
        if ($this->isInSync()) {
            return false;
        }

        file_put_contents($this->generatedPath(), $this->expected());

        return true;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}
