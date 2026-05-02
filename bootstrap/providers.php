<?php

declare(strict_types=1);
use App\Modules\Auth\Providers\AuthModuleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuthModuleServiceProvider::class,
    // Tenancy boota condicionalmente vía toggle TENANCY_ENABLED dentro del propio provider.
    // App\Modules\Tenancy\Providers\TenancyModuleServiceProvider::class,
];
