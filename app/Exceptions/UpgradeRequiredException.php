<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * El cliente que hace la petición es demasiado viejo para seguir hablando con
 * la API. `ApiExceptionRenderer` la convierte en 426 `upgrade_required`, con el
 * mensaje que traiga (versión del cliente y mínima admitida), igual que
 * `ConflictException` con el 409. La lanza el middleware `devices.version`.
 */
final class UpgradeRequiredException extends RuntimeException {}
