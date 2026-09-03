<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;
use Spatie\Health\Http\Middleware\RequiresSecretToken;

/**
 * spatie/laravel-health: checks + endpoints.
 *
 * El paquete NO publica rutas por su cuenta (sólo el endpoint de Oh Dear, que
 * está apagado), así que las registramos aquí para que vivan junto a los
 * checks. Los checks se ejecutan desde el scheduler (`routes/console.php`).
 */
final class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerChecks();
        $this->registerRoutes();
    }

    private function registerChecks(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            CacheCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70)->failWhenUsedSpaceIsAbovePercentage(90),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
            OptimizedAppCheck::new(),
        ]);
    }

    /**
     * - `/health` (HTML) es para humanos: sesión web, autenticado y gate de
     *   superadmin (`viewHealth`, definido en AuthModuleServiceProvider).
     * - `/health/json` es para monitores externos: sin sesión, protegido por el
     *   header `X-Secret-Token` (`HEALTH_SECRET_TOKEN` en el .env). Ojo: el
     *   middleware del paquete deja pasar TODO si el token está vacío.
     */
    private function registerRoutes(): void
    {
        Route::middleware(['web', 'auth', 'can:viewHealth'])
            ->get('/health', HealthCheckResultsController::class)
            ->name('health');

        Route::middleware(RequiresSecretToken::class)
            ->get('/health/json', HealthCheckJsonResultsController::class)
            ->name('health.json');
    }
}
