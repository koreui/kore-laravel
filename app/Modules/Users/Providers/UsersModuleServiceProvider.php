<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Models\User;
use App\Modules\Users\Http\Livewire\FormComponent;
use App\Modules\Users\Http\Livewire\TableUsers;
use App\Modules\Users\Policies\UserPolicy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class UsersModuleServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const array LIVEWIRE_COMPONENTS = [
        'users.form-component' => FormComponent::class,
        'users.table-users' => TableUsers::class,
    ];

    public function boot(): void
    {
        $base = __DIR__.'/..';

        $this->loadRoutesFrom("{$base}/Routes/web.php");
        $this->loadViewsFrom("{$base}/Resources/views", 'users');
        Blade::anonymousComponentPath("{$base}/Resources/views", 'users');

        foreach (self::LIVEWIRE_COMPONENTS as $alias => $class) {
            Livewire::component($alias, $class);
        }

        Gate::policy(User::class, UserPolicy::class);
    }
}
