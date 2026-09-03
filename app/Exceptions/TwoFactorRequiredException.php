<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * La cuenta tiene la verificación en dos pasos confirmada y el flujo por el que
 * entra no sabe resolver el reto (HTTP 403, `two_factor_required`).
 *
 * Hoy la lanza `POST /api/v1/auth/login`: la API emite tokens con **un solo
 * paso**, así que aceptar email + contraseña de una cuenta con 2FA sería
 * publicar una puerta trasera al segundo factor que esa persona activó a
 * propósito. Mientras el reto por API no exista, el login se niega y lo dice
 * con un código que el cliente puede programar —«manda a este usuario al
 * navegador»— en vez de con un `forbidden` genérico.
 *
 * Es una excepción de **dominio**, igual que `ConflictException`: no extiende
 * `HttpException`, así que una Action puede lanzarla sin romper R20 y quien la
 * traduce a un status es `App\Core\Http\Api\Exceptions\ApiExceptionRenderer`.
 *
 * El mensaje lo pone el contrato (`ApiErrorCode::TwoFactorRequired->message()`)
 * y no la excepción: a diferencia del 409, aquí no hay texto de dominio que
 * escribir —la causa es siempre la misma— y un mensaje libre sólo serviría para
 * que dos endpoints la contaran distinto.
 */
final class TwoFactorRequiredException extends RuntimeException {}
