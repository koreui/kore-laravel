<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Fortify\CreateNewUser;
use App\Modules\Auth\Fortify\ResetUserPassword;
use App\Modules\Auth\Fortify\UpdateUserPassword;
use App\Modules\Auth\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Override;

final class FortifyServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->configureTwoFactorFeature();
        $this->configurePasskeysFeature();
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Hace real el toggle `kore-app.auth.two_factor`.
     *
     * `config/fortify.php` no puede leerlo: los archivos de config se cargan en
     * orden alfabético y `fortify` va antes que `kore-app`, así que allí el
     * valor todavía no existe. Aquí sí, porque este `register()` corre después
     * de bootear la config y ANTES del `boot()` del provider de Fortify, que es
     * donde se registran las rutas leyendo `fortify.features`.
     */
    private function configureTwoFactorFeature(): void
    {
        $twoFactor = Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        /** @var array<int, string> $features */
        $features = (array) config('fortify.features', []);
        $features = array_values(array_filter($features, fn (string $feature): bool => $feature !== $twoFactor));

        if ((bool) config('kore-app.auth.two_factor', true)) {
            $features[] = $twoFactor;
        }

        config(['fortify.features' => $features]);
    }

    /**
     * Hace real el toggle `kore-app.auth.passkeys`.
     *
     * Misma forma que el 2FA y por el mismo motivo (R12): `config/fortify.php`
     * no puede leer `kore-app`, así que la feature se añade o se quita aquí,
     * en el `register()`, antes del `boot()` en el que Fortify publica sus
     * rutas leyendo `fortify.features`.
     *
     * `confirmPassword: true` es lo que hace que Fortify cuelgue
     * `password.confirm` de sus rutas de gestión (`/user/passkeys*`): registrar
     * o borrar una credencial es cambiar el segundo factor de la cuenta, y una
     * sesión robada no debería poder hacerlo sin la contraseña.
     */
    private function configurePasskeysFeature(): void
    {
        $passkeys = Features::passkeys([
            'confirmPassword' => true,
        ]);

        /** @var array<int, string> $features */
        $features = (array) config('fortify.features', []);
        $features = array_values(array_filter($features, fn (string $feature): bool => $feature !== $passkeys));

        if ((bool) config('kore-app.auth.passkeys', true)) {
            $features[] = $passkeys;
        }

        config(['fortify.features' => $features]);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (): Factory|View => view('auth::pages.login'));
        Fortify::registerView(fn (): Factory|View => view('auth::pages.register'));
        Fortify::requestPasswordResetLinkView(fn (): Factory|View => view('auth::pages.forgot-password'));
        Fortify::resetPasswordView(fn ($request): Factory|View => view('auth::pages.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn (): Factory|View => view('auth::pages.verify-email'));
        Fortify::twoFactorChallengeView(fn (): Factory|View => view('auth::pages.two-factor-challenge'));
        Fortify::confirmPasswordView(fn (): Factory|View => view('auth::pages.confirm-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));

        /*
         * R28 · `fortify.limiters.passkeys` apunta a este limiter y Fortify lo
         * cuelga de seis de sus siete rutas de passkeys (la de borrado no), incluida `POST /passkeys/login`,
         * que es un login **sin contraseña** y por tanto un endpoint de fuerza
         * bruta como cualquier otro.
         *
         * La clave es el usuario cuando lo hay (gestión y confirmación) y la IP
         * cuando no (login de invitado): un mismo navegador no puede quemar el
         * cupo de todos los usuarios de una oficina, ni al revés.
         *
         * 30/min y no 5 como el login por dos motivos: una ceremonia WebAuthn
         * gasta DOS peticiones (options + submit), y el cubo de los invitados
         * lo comparte toda una oficina detrás del mismo NAT. Sigue siendo un
         * freno duro —y aquí el límite no es la defensa principal: adivinar una
         * firma WebAuthn no es cuestión de intentos—.
         */
        RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
