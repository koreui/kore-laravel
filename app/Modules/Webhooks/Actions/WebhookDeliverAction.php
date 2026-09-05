<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Core\Actions\Action;
use App\Core\Support\WebhookSignature;
use App\Modules\Webhooks\Enums\DeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Intenta entregar una fila del outbox, una vez.
 *
 * No decide *cuándo* se intenta —eso es del listener en cola y del barrido de
 * `webhooks:dispatch`—: decide qué pasa con el resultado.
 *
 *   - 2xx → `delivered`, y se apunta el código.
 *   - cualquier otro código, un timeout o una excepción → se anota el fallo, se
 *     suma un intento y se programa el siguiente con el backoff de
 *     `config/kore-webhooks.php`. Agotados los intentos, `exhausted`.
 *
 * **Nunca lanza.** Un receptor caído no puede tumbar el worker ni dejar el job
 * reintentándose por su cuenta en paralelo al backoff propio: serían dos relojes
 * distintos sobre la misma entrega. Lo que ocurre queda en `last_error` y en
 * `response_status`, que es donde alguien lo puede leer.
 */
final class WebhookDeliverAction extends Action
{
    public function handle(WebhookDelivery $delivery): void
    {
        // Ya cerrada: llega aquí cuando el listener en cola y el barrido del
        // scheduler se cruzan sobre la misma fila.
        if (! $delivery->status->isOpen()) {
            return;
        }

        // `loadMissing` y no `$delivery->endpoint` a secas: el boilerplate corre
        // con `preventLazyLoading()`, y esta Action la invocan tres sitios
        // distintos (el listener, el barrido y un test). Cargar aquí lo que hace
        // falta evita que uno de ellos reviente por no haberlo previsto.
        $delivery->loadMissing('endpoint');

        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof WebhookEndpoint) {
            $this->fail($delivery, 'El endpoint ya no existe.', null);

            return;
        }

        // Apagado desde que se publicó: no se manda, pero tampoco se reintenta
        // eternamente. Se cierra como agotada para que la fila deje de barrerse
        // y quede dicho por qué.
        if (! $endpoint->active) {
            $this->close($delivery, 'El endpoint está desactivado.');

            return;
        }

        try {
            $body = $this->body($delivery);
        } catch (JsonException $exception) {
            // Un payload que no serializa no mejora con el tiempo.
            $this->close($delivery, 'El payload no se puede serializar a JSON: '.$exception->getMessage());

            return;
        }

        $timestamp = (string) CarbonImmutable::now()->getTimestamp();

        try {
            $response = Http::timeout((int) config('kore-webhooks.timeout', 10))
                ->connectTimeout((int) config('kore-webhooks.connect_timeout', 5))
                ->withHeaders($this->headers($delivery, $endpoint, $timestamp, $body))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            if ($response->successful()) {
                $delivery->update([
                    'attempts' => $delivery->attempts + 1,
                    'status' => DeliveryStatus::Delivered,
                    'delivered_at' => CarbonImmutable::now(),
                    'next_attempt_at' => null,
                    'last_error' => null,
                    'response_status' => $response->status(),
                ]);

                return;
            }

            $this->fail($delivery, 'HTTP '.$response->status(), $response->status());
        } catch (Throwable $exception) {
            // Timeout, DNS, TLS: todo lo que ni siquiera llegó a ser una
            // respuesta. Es el caso que más se repite en producción y el que
            // más importa que no rompa nada.
            $this->fail($delivery, $exception->getMessage(), null);
        }
    }

    /**
     * El cuerpo exacto que se firma y se manda.
     *
     * Se calcula UNA vez y se usa para las dos cosas: reserializar el array
     * para firmar y volver a hacerlo para enviar podría dar dos cadenas
     * distintas, y entonces la firma no cuadraría en el receptor.
     *
     * @throws JsonException
     */
    private function body(WebhookDelivery $delivery): string
    {
        return json_encode([
            'id' => $delivery->uuid,
            'event' => $delivery->event,
            'created_at' => $delivery->created_at->toIso8601String(),
            'attempt' => $delivery->attempts + 1,
            'data' => $delivery->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string>
     */
    private function headers(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        string $timestamp,
        string $body,
    ): array {
        $signature = WebhookSignature::sign($endpoint->secret, $timestamp, $body);

        return [
            'X-Kore-Signature' => WebhookSignature::header($timestamp, $signature),
            'X-Kore-Event' => $delivery->event,
            // El uuid de la entrega es el mismo en todos los reintentos: es lo
            // que permite al receptor ser idempotente cuando un 2xx se pierde
            // por el camino y volvemos a llamar.
            'X-Kore-Delivery' => $delivery->uuid,
            'Content-Type' => 'application/json',
            'User-Agent' => (string) config('kore-webhooks.user_agent', 'kore-laravel-webhooks/1'),
        ];
    }

    /**
     * Anota el fallo y programa el reintento, o la da por agotada.
     */
    private function fail(WebhookDelivery $delivery, string $error, ?int $status): void
    {
        $attempts = $delivery->attempts + 1;
        $exhausted = $attempts >= max(1, (int) config('kore-webhooks.max_attempts', 6));

        $delivery->update([
            'attempts' => $attempts,
            'status' => $exhausted ? DeliveryStatus::Exhausted : DeliveryStatus::Failed,
            'next_attempt_at' => $exhausted ? null : CarbonImmutable::now()->addSeconds($this->backoff($attempts)),
            'last_error' => $this->truncate($error),
            'response_status' => $status,
        ]);
    }

    /**
     * Cierra la entrega sin gastar intentos: el fallo no es del receptor y
     * reintentarlo daría el mismo resultado.
     */
    private function close(WebhookDelivery $delivery, string $error): void
    {
        $delivery->update([
            'status' => DeliveryStatus::Exhausted,
            'next_attempt_at' => null,
            'last_error' => $this->truncate($error),
        ]);
    }

    /**
     * Segundos hasta el reintento número `$attempts + 1`.
     *
     * La lista de `config/kore-webhooks.php` se recorre por intento fallido; si
     * se acaba antes que `max_attempts`, se repite el último valor en vez de
     * caer a cero — un backoff que se reinicia solo es un martilleo.
     */
    private function backoff(int $attempts): int
    {
        /** @var array<int, int> $backoff */
        $backoff = array_values(array_map(intval(...), (array) config('kore-webhooks.backoff', [60])));

        if ($backoff === []) {
            return 60;
        }

        return $backoff[min($attempts, count($backoff)) - 1];
    }

    private function truncate(string $error): string
    {
        return mb_substr($error, 0, max(1, (int) config('kore-webhooks.error_max_length', 500)));
    }
}
