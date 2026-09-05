<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Core\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

final class SocialiteController extends Controller
{
    public function redirect(string $provider): mixed
    {
        $this->ensureProviderEnabled($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->ensureProviderEnabled($provider);

        $oauthUser = Socialite::driver($provider)->user();

        $user = User::firstOrCreate(
            ['email' => $oauthUser->getEmail()],
            [
                'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? 'User',
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
                'account_status' => $this->statusForNewAccount(),
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * Con qué estado nace una cuenta que entra por aquí.
     *
     * Con `AUTH_INVITATIONS` encendido, `pending`: el login social es la puerta
     * que **no** pide código, así que aceptarla como activa sería dejar abierto
     * justo lo que el toggle vino a cerrar. Quien entre así ve la pantalla de
     * espera hasta que alguien la active desde el panel de Users.
     *
     * Con el toggle apagado, `active`, que es el default de la columna y el
     * comportamiento de siempre.
     */
    private function statusForNewAccount(): AccountStatus
    {
        return (bool) config('kore-app.auth.invitations')
            ? AccountStatus::Pending
            : AccountStatus::Active;
    }

    private function ensureProviderEnabled(string $provider): void
    {
        abort_unless(
            (bool) config("kore-app.socialite.{$provider}", false),
            404,
        );
    }
}
