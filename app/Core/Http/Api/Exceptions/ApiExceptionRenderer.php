<?php

declare(strict_types=1);

namespace App\Core\Http\Api\Exceptions;

use App\Core\Enums\ApiErrorCode;
use App\Exceptions\ConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Convierte cualquier `Throwable` de una petición `api/*` en el envelope de
 * error del boilerplate:
 *
 * ```json
 * { "error": { "code": "validation_failed", "message": "…", "details": { "email": ["…"] } } }
 * ```
 *
 * Se registra en `bootstrap/app.php` dentro de `withExceptions()`:
 *
 * ```php
 * $exceptions->render(new ApiExceptionRenderer);
 * ```
 *
 * Devuelve `null` para todo lo que no sea `api/*`, y entonces Laravel sigue con
 * el resto de callbacks y con su render por defecto: una pantalla web se
 * comporta exactamente igual que antes de que esto existiera.
 *
 * **El mensaje lo pone el contrato, no la excepción** (ver
 * `ApiErrorCode::message()`): el texto de un `ModelNotFoundException` incluye el
 * FQCN del modelo y el id buscado, y el de una `AuthorizationException` viene
 * en inglés desde el Gate. La única excepción es `ConflictException`, cuyo
 * mensaje es texto de dominio escrito a propósito.
 *
 * Ver `docs/guides/api.md` y R54 en `docs/architecture/rules.md`.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => $this->validationFailed($e),
            $e instanceof AuthenticationException => $this->error(ApiErrorCode::Unauthenticated, 401),
            $e instanceof AuthorizationException => $this->error(ApiErrorCode::Forbidden, 403),
            $e instanceof ModelNotFoundException => $this->error(ApiErrorCode::NotFound, 404),
            $e instanceof ConflictException => $this->conflict($e),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => $this->serverError($e),
        };
    }

    /**
     * 422 · el único código que lleva `details`, con un array de mensajes por
     * campo tal cual lo produce el validador.
     */
    private function validationFailed(ValidationException $e): JsonResponse
    {
        return $this->response(
            ApiErrorCode::ValidationFailed,
            ApiErrorCode::ValidationFailed->message(),
            $e->status,
            extra: ['details' => $e->errors()],
        );
    }

    private function conflict(ConflictException $e): JsonResponse
    {
        return $this->response(ApiErrorCode::Conflict, $e->getMessage(), 409);
    }

    /**
     * Las excepciones HTTP del framework y de Symfony, que a estas alturas ya
     * incluyen las que `Handler::prepareException()` convirtió:
     * `ModelNotFoundException` → 404, `AuthorizationException` → 403,
     * `ThrottleRequestsException` → 429...
     *
     * Las cabeceras de la excepción viajan tal cual: son las que hacen útil el
     * 429 (`Retry-After`, `X-RateLimit-*`) y el 405 (`Allow`).
     */
    private function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = ApiErrorCode::fromHttpStatus($status);

        if ($code === ApiErrorCode::ServerError) {
            return $this->serverError($e);
        }

        return $this->response($code, $code->message(), $status, headers: $e->getHeaders());
    }

    /**
     * 500 · nunca se filtra nada del error salvo con `app.debug` encendido, y
     * entonces bajo su propia clave `debug` para que ningún cliente la confunda
     * con parte del contrato.
     */
    private function serverError(Throwable $e): JsonResponse
    {
        $extra = [];

        if ((bool) config('app.debug')) {
            $extra['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        return $this->response(ApiErrorCode::ServerError, ApiErrorCode::ServerError->message(), 500, $extra);
    }

    private function error(ApiErrorCode $code, int $status): JsonResponse
    {
        return $this->response($code, $code->message(), $status);
    }

    /**
     * @param array<string, mixed> $extra claves adicionales dentro de `error`
     * @param array<string, string> $headers cabeceras que la excepción aporta
     */
    private function response(ApiErrorCode $code, string $message, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                ...$extra,
            ],
        ], $status, $headers);
    }
}
