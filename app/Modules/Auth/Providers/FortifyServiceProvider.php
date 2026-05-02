<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Actions\Fortify\CreateNewUser;
use App\Modules\Auth\Actions\Fortify\ResetUserPassword;
use App\Modules\Auth\Actions\Fortify\UpdateUserPassword;
use App\Modules\Auth\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
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

    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('auth::pages.login'));
        Fortify::registerView(fn () => view('auth::pages.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth::pages.forgot-password'));
        Fortify::resetPasswordView(fn ($request) => view('auth::pages.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth::pages.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('auth::pages.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth::pages.confirm-password'));
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
