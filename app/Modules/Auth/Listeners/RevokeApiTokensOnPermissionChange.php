<?php

declare(strict_types=1);

namespace App\Modules\Auth\Listeners;

use App\Models\User;
use App\Modules\Auth\Actions\AuthApiTokenRevokeAction;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Cambiaron los permisos de un usuario: sus tokens de API dejan de valer.
 *
 * **Por qué.** Las abilities de un token de Sanctum se congelan en el momento
 * de emitirlo (ver `AuthApiTokenIssueAction`). Sin esto, degradar a alguien en
 * la pantalla de usuarios le quita el botón del navegador y no le quita nada de
 * la API: su móvil sigue presentando un token con `users.delete` dentro hasta
 * que caduque, treinta días después. Es exactamente el agujero que R26 cierra
 * en la UI —nadie conserva un permiso que ya no tiene—, visto desde el otro
 * lado del cable.
 *
 * Es un martillo, y a propósito: al usuario afectado se le cierran **todas** las
 * sesiones de API, incluida la que tuviera abierta en ese momento. La
 * alternativa —recalcular las abilities de cada token vivo— deja al cliente con
 * un token cuyo contenido cambió sin avisar, que es peor de depurar que un
 * re-login.
 *
 * **Requiere `permission.events_enabled = true`** en `config/permission.php`:
 * spatie no dispara estos eventos si esa clave está en `false`, que es su
 * default, y entonces este listener no se ejecuta nunca sin que nadie se entere.
 * `ApiTokenRevocationTest` lo verifica.
 *
 * **Lo que NO cubre.** Cambiar los permisos de un *rol* (no de un usuario)
 * dispara el evento con un `Role` dentro, y aquí se ignora. No es un descuido:
 * `ModulesSeeder` y `kore:auth:permissions` sincronizan los permisos de todos
 * los roles en cada despliegue, y reaccionar a eso echaría a toda la plantilla
 * de sus móviles cada vez que alguien añade un módulo. Un proyecto que necesite
 * cubrir ese caso lo hace desde un job, no desde un listener síncrono.
 */
final readonly class RevokeApiTokensOnPermissionChange
{
    public function __construct(
        private AuthApiTokenRevokeAction $revoke,
    ) {}

    /**
     * Los cuatro eventos van en la firma en vez de un `object` genérico: no
     * comparten interfaz —cada uno tiene su `$model` y su `$rolesOrIds` /
     * `$permissionsOrIds`—, así que la unión es lo único que le dice al análisis
     * estático que `$event->model` existe.
     */
    public function handle(
        RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event,
    ): void {
        $model = $event->model;

        if (! $model instanceof User) {
            return;
        }

        $this->revoke->handle($model, reason: 'permissions_changed');
    }
}
