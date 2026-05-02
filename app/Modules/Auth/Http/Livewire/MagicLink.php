<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

final class MagicLink extends Component
{
    public string $email = '';

    public string $code = '';

    public bool $codeSent = false;

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return $this->codeSent
            ? ['code' => ['required', 'string', 'size:6']]
            : ['email' => ['required', 'email', 'exists:users,email']];
    }

    public function sendCode(): void
    {
        $this->validate();

        $user = User::where('email', $this->email)->firstOrFail();
        $user->sendOneTimePassword();

        $this->codeSent = true;
        $this->dispatch('code-sent');
    }

    public function authenticate(): mixed
    {
        $this->validate();

        $user = User::where('email', $this->email)->firstOrFail();
        $result = $user->attemptLoginUsingOneTimePassword($this->code);

        if (! $result->isOk()) {
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
}
