<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Actions\Fortify\CreateNewUser;
use App\Modules\Auth\Actions\Fortify\ResetUserPassword;
use App\Modules\Auth\Actions\Fortify\UpdateUserPassword;
use App\Modules\Auth\Actions\Fortify\UpdateUserProfileInformation;
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
    }
}
