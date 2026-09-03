<?php

declare(strict_types=1);

namespace App\Core\Console\Concerns;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * `--dry-run` para los comandos que borran o modifican datos.
 *
 * Un comando que sincroniza permisos, purga tokens o reescribe filas se corre
 * la primera vez en producción, a ciegas y sin vuelta atrás. El `--dry-run` es
 * el ensayo: el mismo cálculo, el mismo recuento, y ni una escritura.
 *
 * El trait **añade la opción por su cuenta**: no hay que acordarse de escribir
 * `{--dry-run}` en la firma. Lo hace desde `configure()`, que Symfony llama
 * dentro del constructor de `Command` antes de que Laravel vuelque encima los
 * argumentos y opciones del `#[Signature]`.
 *
 * ```php
 * #[Signature('kore:purgar')]
 * final class PurgarCommand extends Command
 * {
 *     use SupportsDryRun;
 *
 *     public function handle(): int
 *     {
 *         $candidatos = Cosa::obsoletas()->count();
 *
 *         if ($this->isDryRun()) {
 *             $this->dryRunNotice("se borrarían {$candidatos} cosa(s).");
 *
 *             return self::SUCCESS;
 *         }
 *         // ...
 *     }
 * }
 * ```
 *
 * Única condición: que el comando **no** defina su propio `configure()`. Si lo
 * necesita, que llame a `addDryRunOption()` desde ahí.
 *
 * @phpstan-require-extends Command
 */
trait SupportsDryRun
{
    /**
     * ¿Se pidió el ensayo?
     */
    public function isDryRun(): bool
    {
        return (bool) $this->option('dry-run');
    }

    /**
     * Registra `--dry-run` en la definición del comando.
     */
    protected function addDryRunOption(): void
    {
        if ($this->getDefinition()->hasOption('dry-run')) {
            return;
        }

        $this->getDefinition()->addOption(new InputOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Enseña lo que haría y no escribe nada.',
        ));
    }

    /**
     * Dice, en una línea y en amarillo, qué habría pasado.
     *
     * El prefijo es siempre el mismo para que un `grep` sobre el log de un cron
     * distinga un ensayo de una corrida de verdad. Sin `__()`, como el resto de
     * la salida de consola del boilerplate: R33 habla de la interfaz, y esto no
     * lo lee un usuario final sino quien está delante de la terminal.
     */
    protected function dryRunNotice(string $message): void
    {
        $this->components->warn('Simulacro (--dry-run): '.$message);
    }

    /**
     * Symfony llama a esto desde el constructor de `Command`; Laravel añade
     * después lo que diga el `#[Signature]`, así que las dos definiciones
     * conviven.
     */
    protected function configure(): void
    {
        parent::configure();

        $this->addDryRunOption();
    }
}
