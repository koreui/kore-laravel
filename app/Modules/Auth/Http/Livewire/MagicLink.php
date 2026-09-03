<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Login con código de un solo uso (spatie/laravel-one-time-passwords).
 *
 * Dos cosas que este componente tiene que resolver por su cuenta:
 *
 * 1. **Anti-enumeración**: no se valida `exists:users,email`. Si el correo no
 *    está registrado no se envía nada, pero la UI avanza igual y el mensaje es
 *    el mismo, así que no se puede usar el formulario para descubrir cuentas.
 * 2. **Throttle**: las llamadas Livewire viajan por `/livewire/update`, que no
 *    pasa por el `limit_req` de nginx (`docker/nginx/nginx.conf` sólo protege
 *    las rutas de auth). Sin el throttle de aquí, `sendCode()` es un email
 *    bomber gratis.
 */
final class MagicLink extends Component
{
    /** Envíos de código permitidos por email+IP dentro de la ventana. */
    private const int MAX_SEND_ATTEMPTS = 5;

    /** Ventana del throttle de envío, en segundos. */
    private const int SEND_DECAY_SECONDS = 300;

    public string $email = '';

    public string $code = '';

    public bool $codeSent = false;

    public function sendCode(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ]);

        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_SEND_ATTEMPTS)) {
            $this->addError('email', __('Demasiados intentos. Vuelve a intentarlo en :seconds segundos.', [
                'seconds' => RateLimiter::availableIn($key),
            ]));

            return;
        }

        RateLimiter::hit($key, self::SEND_DECAY_SECONDS);

        User::where('email', $this->email)->first()?->sendOneTimePassword();

        $this->codeSent = true;
        $this->dispatch('code-sent');
    }

    /**
     * El consumo del código ya viene limitado por el paquete
     * (`config/one-time-passwords.php` → `rate_limit_attempts`: 5 intentos por
     * usuario cada 60s, aplicados dentro de `ConsumeOneTimePasswordAction`),
     * así que aquí no se duplica el throttle. Los correos inexistentes ni
     * siquiera llegan a esa capa: se responde el mismo error genérico.
     */
    public function authenticate(): mixed
    {
        $this->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('email', $this->email)->first();

        if (! $user instanceof User || ! $user->attemptLoginUsingOneTimePassword($this->code)->isOk()) {
            $this->addError('code', __('Código inválido o expirado.'));

            return null;
        }

        Auth::login($user, remember: true);

        return redirect()->intended(config('fortify.home'));
    }

    #[Layout('auth::layouts.auth')]
    public function render(): mixed
    {
        return view('auth::pages.magic-link');
    }

    private function throttleKey(): string
    {
        return 'magic-link:'.Str::transliterate(Str::lower($this->email)).'|'.(string) request()->ip();
    }
}
