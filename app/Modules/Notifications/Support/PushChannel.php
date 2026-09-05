<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Core\Contracts\PushTokenDirectory;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal de push — cableado entero, envío no.
 *
 * **Este canal no manda nada a ningún servicio: escribe una línea en el log.**
 * No es un descuido ni un «pendiente»: el boilerplate no puede elegir por un
 * derivado entre FCM, APNs o Expo, y fingir un envío que no ocurre es peor que
 * no tenerlo — un aviso que se da por entregado y nunca llegó no lo descubre
 * nadie. Lo que sí está resuelto aquí es todo lo demás: las preferencias, la
 * elección del canal y de dónde salen los tokens. El día que un proyecto
 * enchufe su servicio, lo único que cambia es el cuerpo de `send()`.
 *
 * ## Cómo se enchufa un servicio real
 *
 * Sustituye el `Log::info()` por la llamada al proveedor, con los `$tokens` que
 * ya vienen resueltos:
 *
 * ```php
 * Http::withToken(config('services.fcm.key'))
 *     ->post('https://fcm.googleapis.com/fcm/send', [
 *         'registration_ids' => $tokens,
 *         'notification' => ['title' => $payload->title, 'body' => $payload->body],
 *     ]);
 * ```
 *
 * Un canal **sí** puede hacer E/S remota: R22 prohíbe la llamada externa en la
 * capa de entrega (controllers y Livewire), y esto corre por debajo, en el
 * envío de la notificación, que además va a la cola en cuanto la notificación
 * implemente `ShouldQueue`.
 *
 * ## De dónde salen los tokens
 *
 * De `App\Core\Contracts\PushTokenDirectory`, que implementa **Devices** y que
 * sólo está bindeado con `DEVICES_ENABLED=true`. Por eso se pregunta antes por
 * `bound()` en vez de resolverlo: una instalación sin inventario de
 * dispositivos no tiene a dónde mandar un push, y eso no puede tumbar el aviso
 * —que ya está en la bandeja—. Se deja dicho en el log una vez, con el motivo,
 * porque «el push no llega» sin explicación es una tarde perdida.
 *
 * Notifications no importa una sola clase de Devices: toda la relación entre
 * los dos módulos es esta interfaz de Core (R5).
 */
final readonly class PushChannel
{
    public function __construct(private Container $container) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof GenericNotification) {
            return;
        }

        if (! $this->container->bound(PushTokenDirectory::class)) {
            Log::info('notifications.push: sin directorio de tokens, no se envía nada.', [
                'motivo' => 'App\Core\Contracts\PushTokenDirectory no está bindeado (¿DEVICES_ENABLED=false?)',
                'user_id' => $notifiable->getKey(),
            ]);

            return;
        }

        /** @var PushTokenDirectory $directory */
        $directory = $this->container->make(PushTokenDirectory::class);

        $tokens = $directory->tokensFor((int) $notifiable->getKey());

        if ($tokens === []) {
            return;
        }

        Log::info('notifications.push', [
            'user_id' => $notifiable->getKey(),
            // Cuántos, no cuáles: un token de push es la credencial con la que
            // se le manda una notificación a ese teléfono, y el log no es sitio
            // para una credencial.
            'tokens' => count($tokens),
            'category' => $notification->payload->category,
            'title' => $notification->payload->title,
            'body' => $notification->payload->body,
            'url' => $notification->payload->url,
        ]);
    }
}
