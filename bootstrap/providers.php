<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Auth\Providers\AuthModuleServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyModuleServiceProvider::class,
];
