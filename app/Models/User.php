<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\AccountStatus;
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
 *
 * `account_status` y `activated_at` son asignables por la misma razón y con la
 * misma condición: quien los escribe es una Action —`InvitationRedeemAction` al
 * canjear un código, `UserAccountStatusChangeAction` desde el panel de
 * Users—, nunca un `fill($request->all())`. El único formulario que llega al
 * modelo (`Users\Forms\UserForm`) no tiene ninguno de los dos campos, así que
 * nadie se auto-activa por la puerta de atrás.
 */
#[Fillable([
    'name',
    'email',
    'password',
    'email_verified_at',
    'account_status',
    'activated_at',
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
            ->logOnly(['name', 'email', 'account_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * ¿Esta cuenta puede usar la aplicación?
     *
     * Es la pregunta que hace `EnsureAccountIsActive` en cada petición, y la
     * que decide si el panel de Users pinta el botón de activar o el de
     * suspender. Con `AUTH_INVITATIONS` apagado siempre es `true`: la columna
     * existe (el esquema no depende del toggle) pero nadie la mueve de
     * `active`.
     */
    public function isActive(): bool
    {
        return $this->accountStatus()->canOperate();
    }

    /**
     * El estado de alta ya tipado.
     *
     * Se pregunta primero por `getAttributes()` y no por `getAttribute()`
     * porque `Model::shouldBeStrict()` está encendido fuera de producción
     * (`AppServiceProvider`) y leer un atributo que no se cargó **lanza**. Y no
     * cargado es un caso normal: un `User::factory()->create()` no relee la fila,
     * así que el default de la columna no está en el modelo; tampoco lo está
     * tras un `select('id', 'email')`.
     *
     * En todos esos casos la respuesta es `Active`, que es el default de la
     * columna: ante la duda el boilerplate no deja a nadie fuera.
     */
    public function accountStatus(): AccountStatus
    {
        if (! array_key_exists('account_status', $this->getAttributes())) {
            return AccountStatus::Active;
        }

        $status = $this->getAttribute('account_status');

        return $status instanceof AccountStatus ? $status : AccountStatus::Active;
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
            'account_status' => AccountStatus::class,
            'activated_at' => 'immutable_datetime',
        ];
    }
}
