<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Códigos canónicos del envelope de error de la API (`error.code`).
 *
 * El código —no el status HTTP, ni el mensaje— es el contrato que un cliente
 * puede programar: `snake_case`, estable entre versiones y traducible por
 * separado. Un cliente móvil que quiere reintentar mira `throttled`; uno que
 * quiere pintar errores de formulario mira `validation_failed` y su `details`.
 *
 * Quien traduce un `Throwable` a uno de estos códigos es
 * `App\Core\Http\Api\Exceptions\ApiExceptionRenderer`, y sólo él.
 *
 * Ver `docs/guides/api.md` y R54 en `docs/architecture/rules.md`.
 */
enum ApiErrorCode: string
{
    /** 422 · la validación falló. Es el único código que lleva `details`. */
    case ValidationFailed = 'validation_failed';

    /** 401 · no hay sesión ni token válido. */
    case Unauthenticated = 'unauthenticated';

    /** 403 · hay identidad, pero la policy dice que no. */
    case Forbidden = 'forbidden';

    /** 404 · la ruta o el recurso no existen. */
    case NotFound = 'not_found';

    /** 405 · el verbo no está permitido en esa ruta. */
    case MethodNotAllowed = 'method_not_allowed';

    /** 409 · el estado actual del recurso no admite la operación. */
    case Conflict = 'conflict';

    /**
     * 403 · la cuenta tiene 2FA confirmado y este flujo sólo sabe el primer
     * paso. Es un `forbidden` con nombre propio a propósito: el cliente que lo
     * recibe no tiene que reintentar ni pedir otro permiso, sino mandar a esa
     * persona al navegador. La lanza `App\Exceptions\TwoFactorRequiredException`.
     */
    case TwoFactorRequired = 'two_factor_required';

    /** 429 · rate limit. La respuesta lleva `Retry-After`. */
    case Throttled = 'throttled';

    /** 400 · la petición está mal formada (JSON inválido, parámetro ilegible). */
    case BadRequest = 'bad_request';

    /** 426 · el cliente es demasiado viejo: tiene que actualizarse para seguir. */
    case UpgradeRequired = 'upgrade_required';

    /** 4xx sin código propio (419, 402, 423...). El status manda. */
    case HttpError = 'http_error';

    /** 500 · error inesperado. Nunca filtra detalles salvo con `app.debug`. */
    case ServerError = 'server_error';

    /**
     * Código canónico de un status HTTP.
     *
     * Los 5xx colapsan todos en `server_error` a propósito: para el cliente la
     * diferencia entre un 500 y un 503 no cambia lo que puede hacer, y el
     * detalle vive en Sentry, no en el cuerpo de la respuesta.
     */
    public static function fromHttpStatus(int $status): self
    {
        if ($status >= 500) {
            return self::ServerError;
        }

        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            422 => self::ValidationFailed,
            426 => self::UpgradeRequired,
            429 => self::Throttled,
            default => self::HttpError,
        };
    }

    /**
     * Mensaje por defecto del código, traducible y sin datos del sistema.
     *
     * El mensaje lo pone el contrato y no la excepción: el texto de un
     * `ModelNotFoundException` lleva dentro el FQCN del modelo y el id, y el de
     * una `AuthorizationException` viene en inglés desde el Gate. Un endpoint
     * que necesita explicar más lanza una excepción de dominio con su propio
     * mensaje (ver `App\Exceptions\ConflictException`).
     */
    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => __('Los datos enviados no son válidos.'),
            self::Unauthenticated => __('No has iniciado sesión.'),
            self::Forbidden => __('No tienes permiso para hacer esto.'),
            self::NotFound => __('No se encontró el recurso solicitado.'),
            self::MethodNotAllowed => __('El método no está permitido en esta ruta.'),
            self::Conflict => __('El estado actual del recurso no permite esta operación.'),
            self::TwoFactorRequired => __('Esta cuenta tiene verificación en dos pasos: inicia sesión desde el navegador.'),
            self::Throttled => __('Demasiadas peticiones. Inténtalo de nuevo en unos momentos.'),
            self::BadRequest => __('La petición está mal formada.'),
            self::UpgradeRequired => __('Tu versión de la aplicación ya no está soportada. Actualízala para continuar.'),
            self::HttpError => __('La petición no se pudo completar.'),
            self::ServerError => __('Ocurrió un error inesperado.'),
        };
    }
}
