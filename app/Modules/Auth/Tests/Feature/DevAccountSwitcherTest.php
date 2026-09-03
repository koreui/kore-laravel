<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\AuthDevImpersonateUserAction;
use App\Modules\Auth\Http\Livewire\DevAccountSwitcher;
use App\Modules\Auth\Providers\AuthModuleServiceProvider;
use App\Modules\Auth\Support\DemoAccounts;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El switcher de cuentas de desarrollo
|--------------------------------------------------------------------------
|
| Un atajo que entra como otra persona sin contraseña es exactamente lo que no
| puede existir fuera de `local`, así que lo primero que se prueba es que NO
| existe: la ruta no está registrada y la URL responde 404 —no un 403, que
| delataría que hay algo detrás—.
|
| Para el caso contrario no hace falta rearrancar la aplicación entera: basta
| `detectEnvironment()` + `register(force: true)`, que vuelve a correr el
| `boot()` del provider (y con él el `loadRoutesFrom`) contra el entorno nuevo.
| Es el patrón barato de docs/patterns/test-con-otro-entorno.md, y aquí es
| además el único posible: `withEnvironment()` rearranca y se lleva por delante
| la SQLite `:memory:` con las cuentas que estos tests necesitan.
|
*/

/** Pone la aplicación en `local` y vuelve a registrar el módulo Auth. */
function asLocalEnvironment(): void
{
    app()->detectEnvironment(fn (): string => 'local');

    app()->register(AuthModuleServiceProvider::class, force: true);

    // `loadRoutesFrom()` mete la ruta en la colección, pero el índice por
    // nombre se reconstruye al empezar a despachar una petición. Sin esto,
    // `Route::has('dev.switch-account')` diría que no aunque la URL responda.
    Route::getRoutes()->refreshNameLookups();
}

/*
|--------------------------------------------------------------------------
| Fuera de local no existe
|--------------------------------------------------------------------------
*/

it('does not register the switcher route outside local', function (): void {
    expect($this->app->environment())->toBe('testing')
        ->and(Route::has('dev.switch-account'))->toBeFalse();
});

it('answers 404 on the switcher url outside local', function (): void {
    $user = User::factory()->create(['email' => 'quien@example.com']);

    $this->actingAs($user)->get('/dev/switch-account')->assertNotFound();
});

it('refuses to impersonate outside local even when called directly', function (): void {
    $target = User::factory()->create(['email' => 'demo@example.com']);

    expect(fn () => resolve(AuthDevImpersonateUserAction::class)->handle($target))
        ->toThrow(RuntimeException::class, 'sólo existe en el entorno local');

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| En local sí
|--------------------------------------------------------------------------
*/

it('registers the switcher route in local', function (): void {
    asLocalEnvironment();

    expect(Route::has('dev.switch-account'))->toBeTrue()
        ->and(route('dev.switch-account'))->toEndWith('/dev/switch-account');
});

it('needs a session: a guest goes to login', function (): void {
    asLocalEnvironment();

    $this->get('/dev/switch-account')->assertRedirect('/login');
});

it('lists the demo accounts grouped by role', function (): void {
    asLocalEnvironment();
    $this->seed();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $viewer = User::factory()->create(['email' => 'viewer@e2e.test']);
    $viewer->syncRoles(['Usuario']);

    // Alguien de un dominio real: no es una cuenta de demostración y no puede
    // salir en la lista.
    User::factory()->create(['email' => 'persona@empresa.es']);

    $roles = Livewire::actingAs($admin)
        ->test(DevAccountSwitcher::class)
        ->assertOk()
        ->instance()
        ->accountsByRole();

    $emails = collect($roles)->flatten(1)->pluck('email')->all();

    expect(array_keys($roles))->toBe(['Administrador', 'Usuario'])
        ->and($emails)->toBe(['admin@example.com', 'viewer@e2e.test'])
        ->and($roles['Administrador'][0]->isCurrent)->toBeTrue()
        ->and($roles['Usuario'][0]->isCurrent)->toBeFalse();
});

it('renders the screen with its warning', function (): void {
    asLocalEnvironment();
    $this->seed();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->actingAs($admin)
        ->get('/dev/switch-account')
        ->assertOk()
        ->assertSee('Atajo de desarrollo')
        ->assertSee('admin@example.com');
});

it('switches the session to the chosen account', function (): void {
    asLocalEnvironment();
    $this->seed();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $target = User::factory()->create(['email' => 'otro@e2e.test']);
    $target->syncRoles(['Usuario']);

    Livewire::actingAs($admin)
        ->test(DevAccountSwitcher::class)
        ->call('switchTo', $target->id)
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
});

it('refuses an account that is not on a reserved domain', function (): void {
    asLocalEnvironment();
    $this->seed();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $real = User::factory()->create(['email' => 'persona@empresa.es']);

    expect(fn () => Livewire::actingAs($admin)
        ->test(DevAccountSwitcher::class)
        ->call('switchTo', $real->id))
        ->toThrow(RuntimeException::class, 'no es una cuenta de demostración');

    $this->assertAuthenticatedAs($admin);
});

/*
|--------------------------------------------------------------------------
| Qué cuenta cuenta como de demostración
|--------------------------------------------------------------------------
*/

it('recognises the reserved domains', function (string $email, bool $expected): void {
    expect(DemoAccounts::includes($email))->toBe($expected);
})->with([
    'seeder de desarrollo' => ['admin@example.com', true],
    'seeder de e2e' => ['superadmin@e2e.test', true],
    'otro tld reservado' => ['alguien@algo.invalid', true],
    'mayúsculas' => ['Admin@Example.COM', true],
    'dominio real' => ['persona@empresa.es', false],
    'parecido pero no' => ['persona@example.com.mx', false],
    'sin arroba' => ['persona', false],
]);
