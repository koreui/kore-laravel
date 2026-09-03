<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Livewire;

use App\Models\User;
use App\Modules\Auth\Data\DashboardStatData;
use App\Modules\Auth\Models\Module;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

/**
 * Página `/dashboard`.
 *
 * Antes era un `Route::view()` cuya blade hacía `User::count()` y compañía
 * dentro de un `@php`: Eloquent en la vista, imposible de testear sin HTTP y
 * en contra del "NO HACER" de CLAUDE.md. Ahora las cifras se calculan aquí y
 * viajan como DTOs.
 *
 * Sin `authorize()`: la ruta ya exige `auth` + `verified` y el dashboard es la
 * pantalla de aterrizaje de cualquier usuario autenticado.
 */
final class Dashboard extends Component
{
    /**
     * Cifras de cabecera. `#[Computed]` para que se calculen una vez por
     * render y no en el constructor de la clase.
     *
     * @return array<int, DashboardStatData>
     */
    #[Computed]
    public function stats(): array
    {
        return [
            new DashboardStatData(
                label: __('Usuarios totales'),
                value: User::query()->count(),
                icon: 'users',
            ),
            new DashboardStatData(
                label: __('Permisos del sistema'),
                value: Permission::query()->count(),
                icon: 'shield-check',
            ),
            new DashboardStatData(
                label: __('Módulos activos'),
                value: Module::query()->where('active', '=', true)->count(),
                icon: 'layers',
            ),
        ];
    }

    public function render(): View
    {
        return view('auth::livewire.dashboard')
            ->layout('components.layouts.app', ['title' => __('Dashboard')]);
    }
}
