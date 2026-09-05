<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Core\Enums\AccountStatus;
use App\Exceptions\AccountNotActiveException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar sólo a quien tiene la cuenta activa.
 *
 * Va montado sobre los **grupos** `web` y `api` —lo hace
 * `AuthModuleServiceProvider` cuando `AUTH_INVITATIONS` está encendido— y no
 * ruta por ruta, a propósito: así una pantalla nueva nace protegida y nadie
 * tiene que acordarse de blindarla. Lo que se enumera es lo contrario: la lista
 * corta de lo que alguien **sin** cuenta activa sí puede tocar.
 *
 * Esa lista es el contrato de la regla, y cada entrada está por una razón:
 *
 * - **Sesión** (`login`, `logout`, `register`, `password.*`, `two-factor.*`,
 *   `verification.*`, `magic-link.*`, `socialite.*`, `api.v1.auth.*`): sin
 *   ellas alguien suspendido no podría ni cerrar sesión, y alguien pendiente no
 *   podría verificar su correo — que es justo lo que puede desbloquearle.
 * - **`account.pending`**: la pantalla de espera. Bloquearla sería un bucle de
 *   redirecciones contra sí misma.
 * - **El endpoint de Livewire** (`*livewire.update`, `livewire.*`): las
 *   pantallas libres de arriba —el magic link, la confirmación de contraseña,
 *   el registro— llevan componentes Livewire, y sus llamadas no viajan por la
 *   ruta de la pantalla sino por `/livewire/update`. Sin esta entrada, alguien
 *   `pending` con sesión abierta vería la pantalla y no podría usarla. Cada
 *   componente autoriza por su cuenta (R23), así que abrir el endpoint no abre
 *   nada. La pantalla de espera, en cambio, **no** monta Livewire: usa el
 *   layout de auth y es HTML plano con un `<form>` de logout.
 *
 *   Van los **dos** patrones porque en Livewire 4 la ruta de la actualización
 *   se llama `default-livewire.update` —el prefijo es el nombre del «bundle»—
 *   y `livewire.*` no la casa; `livewire.*` sigue cubriendo las que no
 *   cambiaron de nombre (`livewire.upload-file`, `livewire.preview-file`).
 *
 * Una ruta **sin nombre** se trata como protegida: no se puede clasificar, y
 * ante la duda el middleware cierra.
 *
 * Con el toggle apagado esta clase no se registra en ningún sitio y el estado
 * de la cuenta no gobierna nada.
 */
final class EnsureAccountIsActive
{
    /**
     * Patrones de nombre de ruta que una cuenta no activa sí puede usar.
     *
     * @var list<string>
     */
    private const array FREE_ROUTES = [
        'login',
        'logout',
        'register',
        'password.*',
        'two-factor.*',
        'verification.*',
        'user-password.*',
        'user-profile-information.*',
        'magic-link.*',
        'socialite.*',
        'account.pending',
        '*livewire.update',
        'livewire.*',
        'api.v1.auth.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * El guard explícito importa: este middleware va montado sobre el
         * grupo, así que en la API corre ANTES que el `auth:sanctum` de la ruta
         * y `$request->user()` todavía no está resuelto. El guard de Sanctum
         * cubre los dos frentes —sesión web y token Bearer—, así que una sola
         * línea vale para los dos grupos.
         */
        $user = $request->user() ?? Auth::guard('sanctum')->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        if ($this->isFreeRoute($request)) {
            return $next($request);
        }

        return $this->block($request, $user);
    }

    private function isFreeRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && Str::is(self::FREE_ROUTES, $name);
    }

    /**
     * La API recibe un 403 con código propio; el navegador, una pantalla.
     *
     * Y la cuenta **suspendida** además pierde la sesión: mantenerla abierta
     * dejaría a alguien a quien se le cerró el acceso navegando por una
     * aplicación que le contesta que no a todo. Una cuenta `pending`, en
     * cambio, conserva la sesión: todavía tiene cosas que hacer —verificar el
     * correo, esperar— y echarla al login no adelantaría ninguna.
     */
    private function block(Request $request, User $user): Response
    {
        $status = $user->accountStatus();

        $message = $status === AccountStatus::Suspended
            ? __('Tu cuenta está suspendida. Ponte en contacto con el administrador.')
            : __('Tu cuenta está en revisión. Te avisaremos en cuanto quede activada.');

        if ($request->expectsJson() || $request->is('api/*')) {
            throw new AccountNotActiveException($message);
        }

        if ($status === AccountStatus::Suspended) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['email' => $message]);
        }

        return to_route('account.pending');
    }
}
