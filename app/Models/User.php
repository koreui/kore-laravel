<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;
use Spatie\Permission\Traits\HasRoles;

/**
 * `email_verified_at` es asignable porque el alta administrativa (`UserForm`) y
 * el alta social (`SocialiteController`) marcan el email como verificado al
 * crear. Ninguna ruta vuelca input crudo del request en el modelo, así que no
 * abre escalada (R27). Laravel 13: `#[Fillable]` / `#[Hidden]` en vez de las
 * propiedades `$fillable` / `$hidden`.
 */
#[Fillable([
    'name',
    'email',
    'password',
    'email_verified_at',
])]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_recovery_codes',
    'two_factor_secret',
])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasOneTimePasswords;
    use HasRoles;
    use LogsActivity;
    use Notifiable;

    /**
     * Passkeys (WebAuthn). El trait aporta la relación `passkeys()`,
     * `hasPasskeysEnabled()` y el user handle opaco que WebAuthn usa como
     * identificador; el contrato es lo que Fortify busca para saber que este
     * modelo puede tener credenciales.
     */
    use PasskeyAuthenticatable;

    use TwoFactorAuthenticatable;

    /**
     * Audit log (spatie/laravel-activitylog). Sólo se registran los campos de
     * identidad: nada de password ni de secretos de 2FA.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
