<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | KoreLaravel toggles
    |--------------------------------------------------------------------------
    |
    | Boolean switches to enable/disable optional capabilities of the
    | boilerplate. Drive everything from .env so each environment can opt
    | in/out without code changes. For per-user / per-team gradual rollouts
    | use Laravel Pennant features in app/Providers/AppServiceProvider.
    |
    | REGLA: aquí sólo viven toggles que algún archivo lee de verdad. Si una
    | capacidad no está instalada (Reverb, Octane, Scout...) no lleva toggle:
    | es un módulo opcional que se instala bajo demanda. Los toggles fantasma
    | mienten sobre lo que el boilerplate hace.
    |
    */

    'api' => [
        'enabled' => (bool) env('API_ENABLED', true),
    ],

    'tenancy' => [
        'enabled' => (bool) env('TENANCY_ENABLED', false),
    ],

    // Backups (spatie/laravel-backup). Opt-in como tenancy: el provider del
    // paquete está en `dont-discover` y sólo lo registra BackupServiceProvider
    // cuando esto es true. Producción lo enciende en su .env.
    'backup' => [
        'enabled' => (bool) env('BACKUP_ENABLED', false),
    ],

    'socialite' => [
        'google' => (bool) env('SOCIAL_GOOGLE', false),
        'github' => (bool) env('SOCIAL_GITHUB', false),
    ],

    'auth' => [
        'two_factor' => (bool) env('AUTH_2FA_ENABLED', true),
        'magic_links' => (bool) env('AUTH_MAGIC_LINKS', true),
        'social_login' => (bool) env('AUTH_SOCIAL_LOGIN', false),
    ],

];
