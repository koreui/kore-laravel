<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Core\Data\NotificationData;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La notificación de Laravel que arma cualquier aviso del boilerplate.
 *
 * **Vive en `Support/` y no en un `Notifications/`** porque la lista de
 * carpetas de un módulo es cerrada (R3): la clase de Notification del framework
 * y su canal son adaptadores de un paquete, y ésa es la carpeta que R3 les
 * reserva. Es la misma decisión que puso `MediaFileStore` en `Files/Support`.
 *
 * Existe una sola clase y no una por caso de uso: los avisos del boilerplate se
 * disparan desde listeners que ya saben exactamente qué decir, y una clase por
 * mensaje sería cien archivos con el mismo cuerpo. El día que un aviso necesite
 * lógica propia —un asunto de correo distinto, un canal extra— se le hace la
 * suya y sigue pasando por el mismo `Notifier`.
 *
 * ## `via()` es donde se respetan las preferencias
 *
 * Tres canales, y cada uno pide dos cosas: que el aviso lo admita
 * (`NotificationData::$mail` / `$push`) y que la persona lo quiera. La bandeja
 * (`database`) sólo pide lo segundo, porque un aviso que no se guarda no
 * existe: es el canal base y por eso `NotificationData` no tiene un `inApp`
 * con el que apagarlo desde quien notifica.
 *
 * Los dos booleanos del payload son un **techo**, nunca una orden: `mail: true`
 * significa «este aviso puede salir por correo», y quien decide si sale es la
 * preferencia. No hay forma de saltársela desde quien notifica, que es
 * justamente el punto.
 *
 * Si `via()` devuelve la lista vacía, Laravel no manda nada y no falla: alguien
 * que apagó los tres canales de una categoría no recibe ese aviso, y eso es lo
 * que pidió.
 */
final class GenericNotification extends Notification
{
    public function __construct(
        public readonly NotificationData $payload,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $preference = $this->preferences->for((int) $notifiable->getKey(), $this->payload->category);

        $channels = [];

        if ($preference->inApp) {
            $channels[] = 'database';
        }

        if ($this->payload->mail && $preference->mail) {
            $channels[] = 'mail';
        }

        if ($this->payload->push && $preference->push) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    /**
     * Lo que se guarda en la columna `data` de `notifications`.
     *
     * Es la forma que leen la bandeja web, la campana y la API: si cambia aquí,
     * cambia en las tres a la vez. Por eso no se guarda el DTO serializado sino
     * un array explícito — un cambio en las propiedades del DTO no debe
     * reinterpretar en silencio lo que ya está en la base.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->payload->category,
            'title' => $this->payload->title,
            'body' => $this->payload->body,
            'url' => $this->payload->url,
            'data' => $this->payload->data,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject($this->payload->title)
            ->line($this->payload->body);

        if ($this->payload->url !== null && $this->payload->url !== '') {
            $mail->action(__('Abrir'), url($this->payload->url));
        }

        return $mail;
    }
}
