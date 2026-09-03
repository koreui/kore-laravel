<?php

declare(strict_types=1);

namespace App\Modules\Devices\Enums;

/**
 * Plataforma del cliente que consume la API.
 *
 * Backed por string (R3) porque viaja a la base, al JSON de la API y a la
 * cabecera de un cliente: un enum puro habría acabado mapeado a mano en los
 * tres sitios.
 *
 * La lista es corta a propósito. No pretende describir el dispositivo —eso ya
 * lo hace `name`— sino separar los tres tipos de cliente que se tratan distinto:
 * los móviles (que reciben notificaciones push y se actualizan por una tienda),
 * el navegador (que no manda `X-App-Version` y no tiene push token) y los
 * clientes de consola o servidor, que ni se actualizan solos ni tienen usuario
 * delante.
 *
 * `config('devices.platforms')` es la lista blanca efectiva: puede ser un
 * subconjunto de estos casos, nunca un superconjunto.
 */
enum Platform: string
{
    case Ios = 'ios';

    case Android = 'android';

    case Web = 'web';

    case Cli = 'cli';

    /**
     * Etiqueta legible, la que publica `EnumResource` como `label`.
     *
     * Sin `__()`: son nombres propios de plataforma y se escriben igual en
     * cualquier idioma. Traducirlos sería inventar una diferencia que no existe.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ios => 'iOS',
            self::Android => 'Android',
            self::Web => 'Web',
            self::Cli => 'CLI',
        };
    }

    /**
     * ¿Es un cliente que recibe notificaciones push?
     *
     * Lo usa quien decida enviarlas: un `push_token` guardado en un dispositivo
     * web o de consola no lo va a consumir nadie.
     */
    public function supportsPush(): bool
    {
        return $this === self::Ios || $this === self::Android;
    }
}
