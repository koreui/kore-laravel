<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Policies;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationPreference;

/**
 * Las preferencias son de quien las configura.
 *
 * Nadie —ni un administrador— cambia por dónde le avisan a otra persona: no es
 * una restricción de permisos, es que decidir eso por alguien no es una función
 * del producto. Si algún día lo fuera, el sitio para abrirlo es este archivo y
 * no un `if` en una pantalla.
 *
 * `update()` acepta una fila que todavía no existe (la instancia sin guardar
 * que arma la pantalla la primera vez que alguien toca un interruptor): lo que
 * mira es `user_id`, que en ese caso ya está puesto por quien la construyó.
 */
final class NotificationPreferencePolicy
{
    public function update(User $user, NotificationPreference $preference): bool
    {
        return (int) $preference->user_id === (int) $user->getKey();
    }
}
