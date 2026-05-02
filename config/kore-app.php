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
    */

    'api' => [
        'enabled' => (bool) env('API_ENABLED', true),
    ],

    'tenancy' => [
        'enabled' => (bool) env('TENANCY_ENABLED', false),
        // single-db (row-based) | multi-db (database per tenant)
        'mode'    => env('TENANCY_MODE', 'single-db'),
    ],

    'reverb' => [
        'enabled' => (bool) env('REVERB_ENABLED', false),
    ],

    'octane' => [
        'enabled' => (bool) env('OCTANE_ENABLED', false),
        'server'  => env('OCTANE_SERVER', 'frankenphp'),
    ],

    'search' => [
        'enabled' => (bool) env('SCOUT_ENABLED', false),
        'driver'  => env('SCOUT_DRIVER', 'meilisearch'),
    ],

    'socialite' => [
        'google' => (bool) env('SOCIAL_GOOGLE', false),
        'github' => (bool) env('SOCIAL_GITHUB', false),
    ],

    'auth' => [
        'two_factor'    => (bool) env('AUTH_2FA_ENABLED', true),
        'magic_links'   => (bool) env('AUTH_MAGIC_LINKS', true),
        'social_login'  => (bool) env('AUTH_SOCIAL_LOGIN', false),
    ],

    'observability' => [
        'sentry' => (bool) env('SENTRY_ENABLED', false),
        'pulse'  => (bool) env('PULSE_ENABLED', false),
    ],

];
