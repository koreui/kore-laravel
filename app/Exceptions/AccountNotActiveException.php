<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * La cuenta que hace la petición existe y está autenticada, pero su estado de
 * alta no la deja operar (HTTP 403, `account_not_active`).
 *
 * La lanza `App\Modules\Auth\Http\Middleware\EnsureAccountIsActive` cuando la
 * petición viaja por `api/*`: una cuenta `Pending` todavía no ha sido activada
 * y una `Suspended` tiene el acceso cerrado. El cliente necesita distinguirlo
 * de un `forbidden` corriente —ahí el remedio es pedir un permiso; aquí es
 * esperar a que alguien active la cuenta, o dejar de intentarlo—, y por eso
 * tiene código propio en {@see \App\Core\Enums\ApiErrorCode}.
 *
 * Es una excepción de **dominio**, igual que `ConflictException`: no extiende
 * `HttpException`, así que quien la traduce a un status es
 * `App\Core\Http\Api\Exceptions\ApiExceptionRenderer` y sólo él (R54).
 *
 * El mensaje viaja —como en el 409— porque «tu cuenta está en revisión» y «tu
 * cuenta está suspendida» son dos cosas distintas y la persona que las lee en
 * la app tiene que poder distinguirlas.
 */
final class AccountNotActiveException extends RuntimeException {}
