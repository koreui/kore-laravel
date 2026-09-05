<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
 * La pantalla de ajustes de la instalación.
 *
 * No lleva `feature:` a propósito: los ajustes son del núcleo —toda instalación
 * tiene una organización con un nombre—, y ponerle una licencia delante sería
 * poder vender un producto que no se puede configurar. El middleware `feature:`
 * existe para los módulos opcionales de un derivado. Ver
 * `docs/modules/platform.md`.
 */
Route::middleware(['web', 'auth', 'verified'])
    ->prefix('settings')
    ->as('settings.')
    ->controller(SettingsController::class)
    ->group(function (): void {
        Route::middleware('permission:settings.manage')->get('/', 'edit')->name('edit');
    });
