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
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
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
class User extends Authenticatable implements HasMedia, MustVerifyEmail, PasskeyUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasOneTimePasswords;
    use HasRoles;

    /**
     * Archivos colgados del usuario, vía `App\Core\Contracts\FileStore`.
     *
     * El contrato y la interfaz se declaran **siempre**, también con
     * `FILES_ENABLED=false`: son la forma del modelo, no una capacidad. Es lo
     * mismo que la tabla `media`, que se migra igual con el toggle apagado. Lo
     * que el toggle apaga es quién guarda y quién sirve archivos, no si este
     * modelo puede tenerlos.
     */
    use InteractsWithMedia;

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
     * La única colección de archivos del boilerplate: el avatar.
     *
     * **Sin `singleFile()` a propósito.** Esa opción de media-library borra el
     * archivo anterior al añadir uno nuevo, y aquí quien decide qué pasa con la
     * versión anterior es el store: la archiva (`is_current = false`) y la
     * conserva. Con `singleFile()` el historial no existiría y `files:cleanup`
     * no tendría nada que purgar, porque el borrado ya habría ocurrido sin que
     * nadie lo pidiera.
     */
    #[Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar');
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
