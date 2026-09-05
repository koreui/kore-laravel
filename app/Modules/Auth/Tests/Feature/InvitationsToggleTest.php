<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| AUTH_INVITATIONS
|--------------------------------------------------------------------------
|
| R10 · con el toggle apagado no queda nada observable: ni las tres rutas, ni
| el middleware sobre los grupos, ni el comando, ni su entrada en el scheduler,
| ni el campo del formulario de registro.
|
| Una excepción documentada, y se comprueba aquí: el ESQUEMA. La tabla
| `invitation_codes` y las dos columnas de `users` se migran igual, porque un
| toggle apaga rutas y comportamiento, nunca la forma de la base.
|
| La suite corre con el toggle apagado (`phpunit.xml` lo fuerza), así que los
| casos "encendido" arrancan la aplicación de nuevo con `withEnvironment()`.
|
*/

/**
 * Nombres de los comandos programados en el scheduler.
 *
 * @return Collection<int, string>
 */
function invitationScheduledCommands(): Collection
{
    return collect(resolve(Schedule::class)->events())
        ->map(fn (object $event): string => (string) ($event->command ?? ''));
}

/** Middleware efectivo de un grupo. @return array<int, string> */
function invitationGroupMiddleware(string $group): array
{
    /** @var array<string, array<int, string>> $groups */
    $groups = resolve(HttpKernel::class)->getMiddlewareGroups();

    return $groups[$group] ?? [];
}

it('migrates the schema even with the toggle off', function (): void {
    expect(config('kore-app.auth.invitations'))->toBeFalse()
        ->and(Schema::hasTable('invitation_codes'))->toBeTrue()
        ->and(Schema::hasColumns('users', ['account_status', 'activated_at']))->toBeTrue();
});

it('registers no screen with the toggle off', function (): void {
    expect(Route::has('invitations.index'))->toBeFalse()
        ->and(Route::has('invitations.create'))->toBeFalse()
        ->and(Route::has('account.pending'))->toBeFalse();

    $this->get('/invitations')->assertNotFound();
    $this->get('/account/pending')->assertNotFound();
});

it('does not mount the account middleware with the toggle off', function (): void {
    expect(invitationGroupMiddleware('web'))->not->toContain(EnsureAccountIsActive::class)
        ->and(invitationGroupMiddleware('api'))->not->toContain(EnsureAccountIsActive::class);
});

it('registers neither the prune command nor its schedule with the toggle off', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('invitations:prune')
        ->and(invitationScheduledCommands()->filter(fn (string $c): bool => str_contains($c, 'invitations:prune')))
        ->toBeEmpty();
});

it('keeps the register form free of the invitation field with the toggle off', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertDontSee('Código de invitación');
});

it('registers a user without any code with the toggle off', function (): void {
    $this->post(route('register'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
    ])->assertRedirect();

    $user = User::where('email', 'ada@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user?->isActive())->toBeTrue();
});

it('registers the three screens with the toggle on', function (): void {
    withEnvironment(['AUTH_INVITATIONS' => 'true'], function (): void {
        expect(Route::has('invitations.index'))->toBeTrue()
            ->and(Route::has('invitations.create'))->toBeTrue()
            ->and(Route::has('account.pending'))->toBeTrue();

        $index = Route::getRoutes()->getByName('invitations.index');

        expect($index?->gatherMiddleware())->toContain('auth', 'verified', 'permission:invitations.manage');
    });
});

it('mounts the account middleware on both groups with the toggle on', function (): void {
    withEnvironment(['AUTH_INVITATIONS' => 'true'], function (): void {
        expect(invitationGroupMiddleware('web'))->toContain(EnsureAccountIsActive::class)
            ->and(invitationGroupMiddleware('api'))->toContain(EnsureAccountIsActive::class);
    });
});

it('registers the prune command and its nightly schedule with the toggle on', function (): void {
    withEnvironment(['AUTH_INVITATIONS' => 'true'], function (): void {
        expect(array_keys(Artisan::all()))->toContain('invitations:prune')
            ->and(invitationScheduledCommands()->filter(fn (string $c): bool => str_contains($c, 'invitations:prune')))
            ->not->toBeEmpty();
    });
});

it('shows the invitation field on the register form with the toggle on', function (): void {
    withEnvironment(['AUTH_INVITATIONS' => 'true'], function (): void {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Código de invitación');
    });
});
