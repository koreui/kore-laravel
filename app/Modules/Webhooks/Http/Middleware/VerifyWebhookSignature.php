<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Middleware;

use App\Core\Support\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El otro lado de la firma: autentica a un emisor sin sesión ni token de
 * usuario.
 *
 * Quien llama no es una persona, es otro sistema —otra instalación de kore, un
 * proveedor de pagos, un panel central—. Firma cada petición con el secreto
 * compartido y un timestamp, de modo que la firma capturada de una petición
 * vieja no sirva para repetirla.
 *
 * Se registra como alias `webhook.signed` (con el toggle encendido) y **ninguna
 * ruta del boilerplate lo lleva puesto**: el boilerplate manda webhooks, no los
 * recibe. Está aquí para el derivado que sí los reciba:
 *
 * ```php
 * Route::post('/hooks/proveedor', ProveedorHookController::class)
 *     ->middleware('webhook.signed');
 * ```
 *
 * Tres decisiones:
 *
 * - **Sin secreto configurado, 404.** `kore-webhooks.inbound_secret` vacío
 *   significa «esta instalación no recibe webhooks»: es mejor que el endpoint
 *   sencillamente no exista a que quede abierto por omisión el día que alguien
 *   copie la ruta sin copiar el `.env`.
 * - **Se firma el cuerpo crudo** (`getContent()`), no `$request->all()`. Dos
 *   serializaciones del mismo array pueden diferir en el orden de las claves o
 *   en el escape de una barra; la firma se calcula sobre los bytes que
 *   llegaron.
 * - **401 y no 403.** No es que el emisor no tenga permiso: es que no ha
 *   demostrado ser quien dice.
 */
final class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('kore-webhooks.inbound_secret', '');

        if ($secret === '') {
            abort(404);
        }

        $parsed = WebhookSignature::parse((string) $request->header('X-Kore-Signature', ''));

        if ($parsed === null) {
            abort(401, 'Firma ausente o mal formada.');
        }

        $valid = WebhookSignature::verify(
            secret: $secret,
            timestamp: $parsed['timestamp'],
            body: $request->getContent(),
            signature: $parsed['signature'],
            toleranceSeconds: (int) config('kore-webhooks.tolerance_seconds', 300),
        );

        if (! $valid) {
            abort(401, 'Firma inválida o fuera de tiempo.');
        }

        return $next($request);
    }
}
