<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ApiDocsServiceProvider::class,
    App\Providers\HealthServiceProvider::class,
    App\Providers\BackupServiceProvider::class,
    App\Modules\Auth\Providers\AuthModuleServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyModuleServiceProvider::class,
    App\Modules\Users\Providers\UsersModuleServiceProvider::class,
    App\Modules\Docs\Providers\DocsModuleServiceProvider::class,
    App\Modules\E2E\Providers\E2EModuleServiceProvider::class,
];
