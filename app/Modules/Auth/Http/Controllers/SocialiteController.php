<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

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
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->intended(config('fortify.home'));
    }

    private function ensureProviderEnabled(string $provider): void
    {
        abort_unless(
            (bool) config("kore-app.socialite.{$provider}", false),
            404,
        );
    }
}
