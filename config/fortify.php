<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        // R28 · las rutas de passkeys son endpoints sensibles (una de ellas es
        // un login sin contraseña). El limiter `passkeys` lo define
        // FortifyServiceProvider::configureRateLimiting().
        'passkeys' => 'passkeys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Passkeys (WebAuthn)
    |--------------------------------------------------------------------------
    |
    | Fortify envuelve a laravel/passkeys y copia estos valores a
    | `config/passkeys.php` desde su propio `register()`, así que ésta es la
    | única config que hay que tocar (no publiques la del paquete).
    |
    | R12 · aquí NO se puede hacer `config('app.url')` ni `config('app.key')`:
    | un `config/*.php` no lee otro. El valor sale de `env()`, que es lo único
    | legítimo dentro de `config/` (R17).
    |
    | `relying_party_id` es el DOMINIO (sin esquema ni puerto) al que quedan
    | atadas las credenciales: cambiarlo invalida todas las passkeys ya
    | registradas. `allowed_origins` son los orígenes completos que el
    | navegador puede reportar. Ver docs/modules/auth.md.
    |
    */

    'passkeys' => [
        'relying_party_id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),

        'allowed_origins' => [
            (string) env('APP_URL', 'http://localhost'),
        ],

        // Deriva el user handle de WebAuthn. Se separa de APP_KEY para poder
        // rotar la clave de la aplicación sin invalidar las passkeys.
        // `?:` y no el default de env(): con la clave presente y vacía env() devuelve ''.
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET') ?: env('APP_KEY'),

        'timeout' => 60000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    | Ni el 2FA ni las passkeys se listan aquí: los añade o los quita
    | App\Modules\Auth\Providers\FortifyServiceProvider::register() según
    | `config('kore-app.auth.two_factor')` y `config('kore-app.auth.passkeys')`.
    | Los configs se cargan por orden alfabético (`fortify` antes que
    | `kore-app`), así que este archivo no puede leer el toggle; el provider sí,
    | y corre antes de que Fortify registre sus rutas en boot().
    |
    */

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
    ],

];
